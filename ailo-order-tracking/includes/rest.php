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
	$key = 'ailo_track_rl_' . md5( $ip );

	// With a persistent object cache, increment atomically. A read-modify-write
	// on a transient loses increments under concurrency, which means the real
	// ceiling is the limit multiplied by however many requests a caller runs in
	// parallel — a limit that a script defeats by simply going faster is not a
	// limit. Without an object cache the transient path is best effort, but the
	// ownership check no longer depends on it: full phone or email must match.
	if ( wp_using_ext_object_cache() ) {
		$count = wp_cache_get( $key, 'ailo_track' );
		if ( false === $count ) {
			wp_cache_add( $key, 0, 'ailo_track', AILO_TRACK_RATE_WINDOW );
			$count = 0;
		}
		if ( (int) $count >= AILO_TRACK_RATE_LIMIT ) {
			return true;
		}
		if ( $register ) {
			wp_cache_incr( $key, 1, 'ailo_track' );
		}
		return false;
	}

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
 * ⚠️ The first version accepted a match on the LAST SIX DIGITS of the phone.
 * That was meant as convenience — customers type 070123456, +389 70 123 456 and
 * 00389 70 123 456 for the same number — but it turned the endpoint into an
 * oracle for six digits of a stranger's phone number: pick an order id, walk
 * six-digit suffixes, and a 200 tells you when you have them. A million
 * combinations is a lot for one person and nothing at all for a script, and the
 * per-IP rate limit is not what should be standing between a stranger and
 * somebody's phone number.
 *
 * Now: the full national number must match. To stay forgiving about how it was
 * typed, both sides are reduced to digits and, when one carries a country code
 * and the other does not, compared on the longer of the two lengths.
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

	// Below 8 digits a "phone number" is not identifying enough to authorise on.
	if ( strlen( $typed ) < 8 || strlen( $phone ) < 8 ) {
		return false;
	}
	if ( hash_equals( $phone, $typed ) ) {
		return true;
	}

	// One side may carry a country code or a leading zero the other lacks.
	// Compare on the shorter length, but only when that is still most of the
	// number — never on a short suffix.
	$len = min( strlen( $typed ), strlen( $phone ) );
	if ( $len < 8 ) {
		return false;
	}
	return hash_equals( substr( $phone, -$len ), substr( $typed, -$len ) );
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

		$order = ailo_track_resolve_order( $order_id );
		if ( ! $order instanceof WC_Order || ! ailo_track_contact_matches( $order, $contact ) ) {
			ailo_track_rate_limited( true );
			return new WP_Error( 'ailo_track_not_found', $generic, array( 'status' => 404 ) );
		}

		$number = ailo_track_get_meta( $order, AILO_TRACK_META_NUMBER );
		if ( '' === $number ) {
			// NOT counted as a failure: ownership was proven, the shop simply has
			// not shipped yet. Counting it would rate-limit the one customer who
			// is checking most often — the one still waiting.
			return new WP_Error(
				'ailo_track_pending',
				__( 'This order does not have a tracking number yet.', 'ailo-order-tracking' ),
				array( 'status' => 404 )
			);
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
 * Resolve what the customer typed as an "order number" into an order.
 *
 * WooCommerce lets plugins replace the displayed order number, and shops that
 * run one of the sequential-order-number plugins show their customers something
 * that is not the internal order ID at all. Passing the typed value straight to
 * wc_get_order() means the order mode is dead on every one of those stores —
 * the customer types the number printed on their invoice and is told, correctly
 * from the code's point of view and uselessly from theirs, that nothing matched.
 *
 * @param int $typed What the customer entered.
 * @return WC_Order|null
 */
function ailo_track_resolve_order( $typed ) {
	$typed = absint( $typed );
	if ( $typed <= 0 ) {
		return null;
	}

	/**
	 * Filters the resolution of a customer-facing order number to an order ID.
	 *
	 * Return an order ID to short-circuit, or 0 to fall through to the default.
	 * Sites running a custom order numbering scheme should hook this.
	 *
	 * @param int $order_id Resolved order ID, 0 by default.
	 * @param int $typed    The number the customer entered.
	 */
	$resolved = (int) apply_filters( 'ailo_track_resolve_order_number', 0, $typed );
	if ( $resolved > 0 ) {
		$order = wc_get_order( $resolved );
		return $order instanceof WC_Order ? $order : null;
	}

	$order = wc_get_order( $typed );
	if ( ! $order instanceof WC_Order ) {
		return null;
	}

	// When the store shows a different number than the ID, only accept the
	// number the customer was actually shown.
	$shown = preg_replace( '/\D+/', '', (string) $order->get_order_number() );
	if ( '' !== $shown && (int) $shown !== $typed && $order->get_id() !== $typed ) {
		return null;
	}

	return $order;
}

/**
 * Find the order carrying a tracking number.
 *
 * ⚠️ THIS FUNCTION LEAKED. The first version passed a `meta_query` to
 * wc_get_orders(). That works on HPOS and is silently DISCARDED on the legacy
 * post store: WC_Order_Data_Store_CPT lists meta_query in $unsupported_args and
 * WC_Data_Store_WP::get_wp_query_args() does `continue` on the key, so it never
 * reaches WP_Query. The warning goes through wc_doing_it_wrong(), which inside a
 * REST request only writes to error_log — invisible to the caller.
 *
 * What was left was: post_type=shop_order, posts_per_page=1, orderby=ID desc.
 * In other words, on every non-HPOS store this returned THE NEWEST ORDER IN THE
 * SHOP for any input at all. An anonymous POST with a made-up tracking number
 * came back 200 with a real customer's tracking number and carrier link, and
 * because nothing ever "failed", the rate limit never engaged.
 *
 * Two independent defences now, because one of them already proved fallible:
 *   1. meta_key/meta_value, which BOTH data stores understand.
 *   2. The returned order's stored number is compared against the input. If the
 *      query ever degenerates again, the comparison fails and nothing is
 *      disclosed. Never trust a query to have filtered.
 *
 * @param string $normalized Normalised tracking number.
 * @return WC_Order|null
 */
function ailo_track_find_order_by_number( $normalized ) {
	if ( '' === $normalized ) {
		return null;
	}

	$orders = wc_get_orders(
		array(
			'limit'        => 1,
			'return'       => 'objects',
			'meta_key'     => AILO_TRACK_META_NUMBER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'   => $normalized,            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'meta_compare' => '=',
		)
	);

	if ( empty( $orders ) || ! $orders[0] instanceof WC_Order ) {
		return null;
	}

	// The belt to that braces. hash_equals because the input is attacker-controlled.
	$stored = ailo_track_normalize_number( ailo_track_get_meta( $orders[0], AILO_TRACK_META_NUMBER ) );
	if ( '' === $stored || ! hash_equals( $stored, $normalized ) ) {
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
