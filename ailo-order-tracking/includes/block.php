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
			// Not built yet — say so in the admin rather than failing silently.
			add_action(
				'admin_notices',
				static function () {
					if ( ! current_user_can( 'activate_plugins' ) ) {
						return;
					}
					echo '<div class="notice notice-warning"><p>';
					esc_html_e( 'Ailo Order Tracking: the block is not built. Run "npm install && npm run build" in the plugin folder.', 'ailo-order-tracking' );
					echo '</p></div>';
				}
			);
			return;
		}
		register_block_type( $build );
	}
);

/**
 * Hand the front-end script the REST root and a nonce.
 *
 * The nonce is not an authorisation check — the lookup route is public by design
 * — but WordPress needs it to treat the request as coming from this site rather
 * than as an anonymous cross-origin call, and it keeps the route out of reach of
 * trivially scripted abuse from another domain.
 */
add_action(
	'wp_enqueue_scripts',
	static function () {
		if ( ! has_block( 'ailo/order-tracking-lookup' ) ) {
			return;
		}
		$handle = generate_block_asset_handle( 'ailo/order-tracking-lookup', 'viewScript' );
		wp_localize_script(
			$handle,
			'ailoTrackSettings',
			array(
				'root'  => esc_url_raw( rest_url() ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'  => array(
					'searching' => __( 'Searching…', 'ailo-order-tracking' ),
					'notFound'  => __( 'We could not find a shipment for those details.', 'ailo-order-tracking' ),
					'missing'   => __( 'Enter a tracking number, or an order number with the email or phone used on the order.', 'ailo-order-tracking' ),
					'error'     => __( 'Something went wrong. Please try again.', 'ailo-order-tracking' ),
					'open'      => __( 'Open carrier page', 'ailo-order-tracking' ),
				),
			)
		);
	}
);
