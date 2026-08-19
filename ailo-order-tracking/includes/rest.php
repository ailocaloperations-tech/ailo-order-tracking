<?php
/**
 * Public lookup endpoint.
 *
 * This is the only route in the plugin and it is the only place a request from a
 * logged-out visitor reaches order data, so the rules are strict:
 *
 *   - Looking up by ORDER NUMBER requires proof of ownership. The caller must
 *     also supply the billing email or phone on that order. Without it, the
 *     order number alone would be an enumeration oracle: order numbers are
 *     sequential, so anyone could walk them and harvest shipping data.
 *   - Looking up by TRACKING NUMBER needs no proof, because the tracking number
 *     is itself the secret: the customer got it from us, and it is not guessable.
 *   - Either way the response contains only the tracking number, the carrier
 *     label and the carrier URL. Never a name, address, email, phone or total.
 *   - Failed attempts are rate limited per IP so the ownership check cannot be
 *     brute-forced by trying contacts against one order number.
 *
 * @package AiloOrderTracking
 */

defined( 'ABSPATH' ) || exit;

const AILO_TRACK_RATE_LIMIT   = 20;
const AILO_TRACK_RATE_WINDOW  = 600;
const AILO_TRACK_REST_NAMESPACE = 'ailo-track/v1';

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			AILO_TRACK_REST_NAMESPACE,
			'/lookup',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'ailo_track_rest_lookup',
				// The endpoint is public by design: customers are not logged in.
				// Authorisation happens inside the callback, per lookup mode.
				'permission_callback' => '__return_true',
				'args'                => array(
					'mode'     => array(
						'type'    => 'string',
						'enum'    => array( 'tracking', 'order' ),
						'default' => 'tracking',
					),
					'tracking' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'order_id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'contact'  => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}
);

/**
 * Best available client IP, used only as a rate-limit bucket.
 *
 * Proxy headers are trusted only when the site says it is behind a proxy,
 * because otherwise a caller can forge X-Forwarded-For and get a fresh bucket
 * on every request, which would make the rate limit decorative.
 *
 * @return string
 */
function ailo_track_client_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

	/**
	 * Filters whether to trust proxy headers for rate limiting.
	 *
	 * @param bool $trust Default false.
	 */
	if ( apply_filters( 'ailo_track_trust_proxy_headers', false ) && isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
		$forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
		$parts     = explode( ',', $forwarded );
		$candidate = trim( $parts[0] );
		if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
			$ip = $candidate;
		}
	}

	return $ip;
}

/**
 * Count a failed lookup and report whether the caller is now over the limit.
 *
 * Only FAILURES are counted. A customer who checks the same valid tracking
 * number ten times is not doing anything wrong; someone trying ten different
 * contacts against one order number is.
 *
 * @param bool $register Whether to record a failure.
 * @return bool True when the caller should be blocked.
 */
function ailo_track_rate_limited( $register = false ) {
	$ip = ailo_track_client_ip();
	if ( '' === $ip ) {
		return false;
	}
	$key   = 'ailo_track_rl_' . md5( $ip );
	$count = (int) get_transient( $key );

	if ( $count >= AILO_TRACK_RATE_LIMIT ) {
		return true;
	}
	if ( $register ) {
		set_transient( $key, $count + 1, AILO_TRACK_RATE_WINDOW );
	}
	return false;
}

/**
 * Does the supplied contact match the billing email or phone on this order?
 *
 * Phone comparison keeps only digits and then compares the last six, because
 * customers type the same number as 070123456, +389 70 123 456 and 00389701234
 * and all three should be accepted. Six digits is short enough to be forgiving
 * and long enough that guessing it is not practical inside the rate limit.
 *
 * @param WC_Order $order   Order.
 * @param string   $contact Email or phone as typed by the visitor.
 * @return bool
 */
function ailo_track_contact_matches( WC_Order $order, $contact ) {
	$contact = trim( $contact );
	if ( '' === $contact ) {
		return false;
	}

	$email = (string) $order->get_billing_email();
	if ( '' !== $email && 0 === strcasecmp( $contact, $email ) ) {
		return true;
	}

	$typed = preg_replace( '/\D+/', '', $contact );
	$phone = preg_replace( '/\D+/', '', (string) $order->get_billing_phone() );
	if ( '' === $typed || '' === $phone ) {
		return false;
	}
	if ( hash_equals( $phone, $typed ) ) {
		return true;
	}
	if ( strlen( $typed ) >= 6 && strlen( $phone ) >= 6 ) {
		return hash_equals( substr( $phone, -6 ), substr( $typed, -6 ) );
	}
	return false;
}

/**
 * Handle a lookup.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function ailo_track_rest_lookup( WP_REST_Request $request ) {
	if ( ailo_track_rate_limited() ) {
		return new WP_Error(
			'ailo_track_rate_limited',
			__( 'Too many attempts. Please try again in a few minutes.', 'ailo-order-tracking' ),
			array( 'status' => 429 )
		);
	}

	$mode = (string) $request->get_param( 'mode' );

	// Deliberately identical wording for "no such order", "contact does not
	// match" and "no tracking yet". Distinct messages would tell an attacker
	// which order numbers exist and which contacts are close.
	$generic = __( 'We could not find a shipment for those details.', 'ailo-order-tracking' );

	if ( 'order' === $mode ) {
		$order_id = (int) $request->get_param( 'order_id' );
		$contact  = (string) $request->get_param( 'contact' );

		if ( $order_id <= 0 || '' === trim( $contact ) ) {
			return new WP_Error( 'ailo_track_missing', __( 'Please enter your order number and the email or phone used on the order.', 'ailo-order-tracking' ), array( 'status' => 400 ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! ailo_track_contact_matches( $order, $contact ) ) {
			ailo_track_rate_limited( true );
			return new WP_Error( 'ailo_track_not_found', $generic, array( 'status' => 404 ) );
		}

		$number = ailo_track_get_meta( $order, AILO_TRACK_META_NUMBER );
		if ( '' === $number ) {
			ailo_track_rate_limited( true );
			return new WP_Error( 'ailo_track_not_found', $generic, array( 'status' => 404 ) );
		}
		$carrier = ailo_track_get_meta( $order, AILO_TRACK_META_CARRIER );

		return ailo_track_lookup_response( $number, $carrier );
	}

	$typed = ailo_track_normalize_number( (string) $request->get_param( 'tracking' ) );
	if ( '' === $typed ) {
		return new WP_Error( 'ailo_track_missing', __( 'Please enter a tracking number.', 'ailo-order-tracking' ), array( 'status' => 400 ) );
	}

	$order = ailo_track_find_order_by_number( $typed );
	if ( ! $order instanceof WC_Order ) {
		ailo_track_rate_limited( true );
		return new WP_Error( 'ailo_track_not_found', $generic, array( 'status' => 404 ) );
	}

	return ailo_track_lookup_response(
		ailo_track_get_meta( $order, AILO_TRACK_META_NUMBER ),
		ailo_track_get_meta( $order, AILO_TRACK_META_CARRIER )
	);
}

/**
 * Find the order carrying a tracking number.
 *
 * wc_get_orders() is used rather than a direct query so this works unchanged on
 * both HPOS and the legacy post store.
 *
 * @param string $normalized Normalised tracking number.
 * @return WC_Order|null
 */
function ailo_track_find_order_by_number( $normalized ) {
	$orders = wc_get_orders(
		array(
			'limit'      => 1,
			'return'     => 'objects',
			'meta_query' => array(
				array(
					'key'     => AILO_TRACK_META_NUMBER,
					'value'   => $normalized,
					'compare' => '=',
				),
			),
		)
	);
	if ( empty( $orders ) || ! $orders[0] instanceof WC_Order ) {
		return null;
	}
	return $orders[0];
}

/**
 * Shape the successful response. Nothing personal ever goes in here.
 *
 * @param string $number  Tracking number.
 * @param string $carrier Carrier slug.
 * @return WP_REST_Response
 */
function ailo_track_lookup_response( $number, $carrier ) {
	return new WP_REST_Response(
		array(
			'tracking' => $number,
			'carrier'  => '' !== $carrier ? ailo_track_carrier_label( $carrier ) : '',
			'url'      => ailo_track_build_url( $carrier, $number ),
		),
		200
	);
}
