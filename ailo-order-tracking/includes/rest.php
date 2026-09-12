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

const AILO_TRACK_RATE_LIMIT     = 20;
const AILO_TRACK_RATE_WINDOW    = 600;
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
	/**
	 * Filters whether proxy headers may identify the caller.
	 *
	 * Off by default. WC_Geolocation::get_ip_address() believes X-Real-IP and
	 * X-Forwarded-For unconditionally, so on a store that is NOT behind a proxy
	 * any caller can set the header and mint a fresh bucket per request, which
	 * makes the limit decorative. A store that really sits behind a CDN or a
	 * reverse proxy (where REMOTE_ADDR is always the edge address and every
	 * visitor would share one bucket) opts in with
	 * define( 'AILO_TRACK_TRUST_PROXY', true ) or with this filter. The
	 * per-order bucket in ailo_track_rest_lookup() holds either way.
	 *
	 * @param bool $trust Whether to use WooCommerce's proxy-aware resolver.
	 */
	$trust_proxy = (bool) apply_filters( 'ailo_track_trust_proxy_headers', defined( 'AILO_TRACK_TRUST_PROXY' ) && AILO_TRACK_TRUST_PROXY );

	if ( $trust_proxy && class_exists( 'WC_Geolocation' ) ) {
		$ip = WC_Geolocation::get_ip_address();
		if ( '' !== $ip && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return $ip;
		}
	}

	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
}

/**
 * Count a failed lookup and report whether the caller is now over the limit.
 *
 * Only FAILURES are counted. A customer who checks the same valid tracking
 * number ten times is not doing anything wrong; someone trying ten different
 * contacts against one order number is.
 *
 * @param bool   $register Whether to record a failure.
 * @param string $bucket   Bucket key. Empty means the caller's address; the
 *                         lookup also passes "order:<id>" so guesses at one
 *                         order are capped no matter where they come from.
 * @return bool True when the caller should be blocked.
 */
function ailo_track_rate_limited( $register = false, $bucket = '' ) {
	if ( '' === $bucket ) {
		$bucket = ailo_track_client_ip();
		if ( '' === $bucket ) {
			// No usable address means no bucket, and a limiter that gives up when it
			// cannot identify the caller is a limiter an attacker turns off. Fall back
			// to a single shared bucket rather than to no limit at all.
			$bucket = 'unknown';
		}
	}
	$key = 'ailo_track_rl_' . md5( $bucket );

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
 * The full national number must match, never a suffix of it. A suffix rule lets
 * the CALLER decide how much of the number has to be right, which turns this
 * endpoint into an oracle for the remaining digits of a stranger's phone number.
 * Tolerance for how a number was typed belongs in normalisation, not in the
 * comparison.
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

	// Both sides are reduced to a comparable "national" form ONCE, and then must
	// match in full. Comparing suffixes was the mistake in both earlier versions:
	// with a suffix rule the attacker, not the shop, picks how much of the number
	// has to be right — submit eight digits and only eight are ever checked.
	$typed = ailo_track_national_number( $contact );
	$phone = ailo_track_national_number( (string) $order->get_billing_phone() );

	if ( '' === $typed || '' === $phone ) {
		return false;
	}
	return hash_equals( $phone, $typed );
}

/**
 * Calling code of the store's own country, digits only, or '' when unknown.
 *
 * WooCommerce returns it as "+389" (or as an array for the few countries that
 * share a code); only the digits are useful for comparison.
 *
 * @return string
 */
function ailo_track_store_calling_code() {
	if ( ! function_exists( 'WC' ) || ! isset( WC()->countries ) ) {
		return '';
	}
	$code = WC()->countries->get_country_calling_code( WC()->countries->get_base_country() );
	return preg_replace( '/D+/', '', (string) $code );
}

/**
 * Reduce a phone number to a comparable national form.
 *
 * Customers write the same number as 070123456, 70 123 456, +389 70 123 456 and
 * 00389 70 123 456. All four must compare equal, and none of them may be allowed
 * to match a DIFFERENT number — which is what a suffix comparison permitted.
 *
 * Strategy: keep digits, drop an international prefix (00 or a leading + that
 * preg has already removed leaves the country digits), then drop one leading
 * zero. What remains is the subscriber number, compared in full.
 *
 * @param string $raw As typed or as stored.
 * @return string Empty when the value is too short to authorise on.
 */
function ailo_track_national_number( $raw ) {
	$digits = preg_replace( '/\D+/', '', (string) $raw );
	if ( '' === $digits ) {
		return '';
	}

	// 00XXX… international prefix.
	if ( 0 === strpos( $digits, '00' ) ) {
		$digits = substr( $digits, 2 );
	}

	/**
	 * Filters the country calling code stripped before comparison.
	 *
	 * Defaults to the calling code of the store's own country, because that is
	 * the number format the overwhelming majority of a shop's customers type.
	 *
	 * @param string $code Digits only, without + or 00. Empty disables stripping.
	 */
	$cc = (string) apply_filters( 'ailo_track_country_calling_code', ailo_track_store_calling_code() );
	if ( '' !== $cc && 0 === strpos( $digits, $cc ) && strlen( $digits ) > strlen( $cc ) ) {
		$digits = substr( $digits, strlen( $cc ) );
	}

	$digits = ltrim( $digits, '0' );

	// Below six digits this is not a phone number, and authorising on it would
	// bring back exactly the guessing problem the suffix rule had.
	return strlen( $digits ) >= 6 ? $digits : '';
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

		// Second bucket, keyed on the order rather than the caller: guesses at the
		// contact behind ONE order are capped no matter how many addresses they
		// arrive from. The cost is that a flood of failures against an order also
		// locks its real owner out for AILO_TRACK_RATE_WINDOW seconds; that is the
		// lesser harm next to letting the contact be enumerated.
		$order_bucket = 'order:' . $order_id;
		if ( ailo_track_rate_limited( false, $order_bucket ) ) {
			return new WP_Error(
				'ailo_track_rate_limited',
				__( 'Too many attempts. Please try again in a few minutes.', 'ailo-order-tracking' ),
				array( 'status' => 429 )
			);
		}

		$order = ailo_track_resolve_order( $order_id );
		if ( ! $order instanceof WC_Order || ! ailo_track_contact_matches( $order, $contact ) ) {
			ailo_track_rate_limited( true );
			ailo_track_rate_limited( true, $order_bucket );
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

	// Only accept the number the customer was actually shown. On a store running
	// a sequential-order-number plugin, order 500 may be displayed as 1000; the
	// customer types 1000 and must not be handed order 1000.
	//
	// The earlier version of this guard also tested $order->get_id() !== $typed,
	// which is dead: the order was fetched BY $typed, so that is never true and
	// the whole condition could never fire.
	$shown = preg_replace( '/\D+/', '', (string) $order->get_order_number() );
	if ( '' !== $shown && (int) $shown !== $typed ) {
		return null;
	}

	return $order;
}

/**
 * Find the order carrying a tracking number.
 *
 * Two independent defences, because on a public endpoint the query alone is not
 * enough to rely on.
 *
 * `meta_query` is NOT supported by the legacy (CPT) order data store: WooCommerce
 * lists it as an unsupported argument and drops it before WP_Query, warning only
 * through wc_doing_it_wrong(), which in a REST request writes to error_log and
 * nothing else. A filter that disappears without a sound leaves "the newest order
 * in the shop", which on a public route is a disclosure. Hence:
 *
 *   1. meta_key/meta_value, which BOTH data stores understand.
 *   2. The stored number on the returned order is compared against the input
 *      before anything is disclosed, so that if the filter is ever dropped again
 *      the comparison fails closed.
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
