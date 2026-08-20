<?php
/**
 * Plugin Name:       Ailo Order Tracking
 * Plugin URI:        https://github.com/ailocaloperations-tech/ailo-order-tracking
 * Description:       Carrier-agnostic shipment tracking for WooCommerce. Add a tracking number to any order, show it in customer emails, and let customers look it up from a block. No external service, no API key, no account.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Sashko Markov
 * Author URI:        https://ailo.mk
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ailo-order-tracking
 * Domain Path:       /languages
 *
 * @package AiloOrderTracking
 */

defined( 'ABSPATH' ) || exit;

define( 'AILO_TRACK_VERSION', '1.0.0' );
define( 'AILO_TRACK_FILE', __FILE__ );
define( 'AILO_TRACK_DIR', plugin_dir_path( __FILE__ ) );
define( 'AILO_TRACK_URL', plugin_dir_url( __FILE__ ) );

/**
 * Meta keys. Public so a site owner can read them from their own code, and
 * prefixed with a leading underscore so they stay out of the custom-fields UI.
 */
define( 'AILO_TRACK_META_NUMBER', '_ailo_track_number' );
define( 'AILO_TRACK_META_CARRIER', '_ailo_track_carrier' );

/**
 * HPOS (custom order tables) compatibility.
 *
 * This must run on before_woocommerce_init — declaring later has no effect and
 * WooCommerce will mark the plugin incompatible, which hides it from stores that
 * have HPOS enabled. Everything below reads and writes order data through
 * WC_Order rather than get_post_meta(), so the same code works on both the
 * legacy postmeta store and wc_orders_meta.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', AILO_TRACK_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', AILO_TRACK_FILE, true );
		}
	}
);

/**
 * WooCommerce is declared in the Requires Plugins header, which WordPress 6.5+
 * enforces on activation. This is the belt to that braces: a site that upgrades
 * from an older WordPress, or deactivates WooCommerce afterwards, must not fatal.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					if ( ! current_user_can( 'activate_plugins' ) ) {
						return;
					}
					echo '<div class="notice notice-error"><p>';
					esc_html_e( 'Ailo Order Tracking needs WooCommerce to be installed and active.', 'ailo-order-tracking' );
					echo '</p></div>';
				}
			);
			return;
		}

		require_once AILO_TRACK_DIR . 'includes/order-meta.php';
		require_once AILO_TRACK_DIR . 'includes/carriers.php';
		require_once AILO_TRACK_DIR . 'includes/settings.php';
		require_once AILO_TRACK_DIR . 'includes/admin-order.php';
		require_once AILO_TRACK_DIR . 'includes/orders-list.php';
		require_once AILO_TRACK_DIR . 'includes/emails.php';
		require_once AILO_TRACK_DIR . 'includes/rest.php';
		require_once AILO_TRACK_DIR . 'includes/block.php';
	}
);
