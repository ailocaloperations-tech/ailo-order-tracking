<?php
/**
 * Block registration.
 *
 * register_block_type() is pointed at the BUILD output, not at src/, because
 * block.json in build/ carries the asset handles that @wordpress/scripts writes
 * into index.asset.php. Registering src/ directly appears to work in the editor
 * and then fails silently on a real install.
 *
 * @package AiloOrderTracking
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'init',
	static function () {
		$build = AILO_TRACK_DIR . 'build';
		if ( ! file_exists( $build . '/block.json' ) ) {
			// The release zip always contains build/. A clone of the source repo
			// does not, because build output does not belong in version control.
			add_action(
				'admin_notices',
				static function () {
					if ( ! current_user_can( 'activate_plugins' ) ) {
						return;
					}
					echo '<div class="notice notice-warning"><p>';
					esc_html_e(
						'Ailo Order Tracking: the block is missing. Install the plugin from a release zip, or if you cloned the source repository, run "npm install && npm run build" in the plugin folder.',
						'ailo-order-tracking'
					);
					echo '</p></div>';
				}
			);
			return;
		}
		register_block_type( $build );
	}
);

/**
 * Configuration for the front-end script.
 *
 * ⚠️ This used to hang off wp_enqueue_scripts behind has_block(). Both halves
 * were wrong:
 *
 *   - has_block() reads the CURRENT POST'S post_content only. A block placed in
 *     a Full Site Editing template part, a synced pattern or a widget is invisible
 *     to it — and an FSE template part is precisely where this block belongs, next
 *     to order confirmation. The script would load with no configuration and every
 *     lookup would fail silently.
 *   - It sent an X-WP-Nonce to a route that is public by design. On any site with
 *     page caching, the cached HTML carries a nonce older than its 24-hour life,
 *     and WordPress answers a stale nonce on a cookie-authenticated request with a
 *     hard 403 "Cookie check failed" — so the form broke for real customers while
 *     doing nothing whatsoever to an attacker, who simply omits the header.
 *
 * Now the data is attached to the registered handle at init, unconditionally and
 * with no nonce. Abuse control is the per-IP rate limit inside the route, which
 * is the only thing that was ever actually protecting it.
 */
add_action(
	'init',
	static function () {
		if ( ! function_exists( 'generate_block_asset_handle' ) ) {
			return;
		}
		$handle = generate_block_asset_handle( 'ailo/order-tracking-lookup', 'viewScript' );
		if ( ! wp_script_is( $handle, 'registered' ) ) {
			return;
		}
		wp_localize_script(
			$handle,
			'ailoTrackSettings',
			array(
				'root' => esc_url_raw( rest_url() ),
				'i18n' => array(
					'searching' => __( 'Searching…', 'ailo-order-tracking' ),
					'notFound'  => __( 'We could not find a shipment for those details.', 'ailo-order-tracking' ),
					'missing'   => __( 'Enter a tracking number, or an order number with the email or phone used on the order.', 'ailo-order-tracking' ),
					'error'     => __( 'Something went wrong. Please try again.', 'ailo-order-tracking' ),
					'open'      => __( 'Open carrier page', 'ailo-order-tracking' ),
				),
			)
		);
	},
	20
);
