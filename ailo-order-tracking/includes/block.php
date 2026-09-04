<?php
/**
 * Block registration.
 *
 * Registration points at the BUILD output, not at src/, because block.json in
 * build/ carries the asset handles that @wordpress/scripts writes into
 * index.asset.php. Pointing register_block_type() at src/ directly appears to
 * work in the editor and then fails silently on a real install.
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

		// Without this the editor strings are shipped but untranslatable: the
		// JSON translation files WordPress builds for a block are only loaded
		// when the script handle is registered for translation.
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations(
				generate_block_asset_handle( 'ailo/order-tracking-lookup', 'editorScript' ),
				'ailo-order-tracking',
				AILO_TRACK_DIR . 'languages'
			);
		}
	}
);

/**
 * Configuration for the front-end script.
 *
 * Attached unconditionally at init rather than behind has_block(), and with no
 * nonce. Two reasons:
 *
 *   - has_block() reads the current post's post_content only, so a block placed
 *     in a Full Site Editing template part, a synced pattern or a widget is
 *     invisible to it — and a template part is precisely where this block belongs.
 *   - The lookup route is public by design. A nonce baked into page-cached HTML
 *     outlives its window and then returns a hard 403 to real customers, while an
 *     attacker simply omits the header. Abuse control is the per-IP rate limit
 *     inside the route.
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
