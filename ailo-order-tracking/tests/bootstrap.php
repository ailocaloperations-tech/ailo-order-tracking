<?php
/**
 * PHPUnit bootstrap.
 *
 * WooCommerce has to be loaded before this plugin, not alongside it: the plugin
 * declares HPOS compatibility on before_woocommerce_init and calls wc_get_order()
 * everywhere, so loading them in the wrong order gives fatals that look like bugs
 * in the plugin.
 *
 * @package AiloOrderTracking
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wp-tests-lib';
}

/**
 * PHPUnit Polyfills, which the WordPress test suite refuses to start without.
 *
 * Loaded here rather than through the WP_TESTS_PHPUNIT_POLYFILLS_PATH constant
 * because that has to be a constant, and an environment variable of the same
 * name is silently ignored — which looks exactly like the library being missing.
 */
$_polyfills = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( ! $_polyfills ) {
	$_polyfills = rtrim( sys_get_temp_dir(), '/\\' ) . '/phpunit-polyfills';
}
if ( file_exists( "{$_polyfills}/phpunitpolyfills-autoload.php" ) ) {
	require_once "{$_polyfills}/phpunitpolyfills-autoload.php";
} elseif ( file_exists( dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php' ) ) {
	require_once dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php." . PHP_EOL;
	echo 'Set WP_TESTS_DIR to the WordPress test library.' . PHP_EOL;
	exit( 1 );
}

require_once "{$_tests_dir}/includes/functions.php";

/**
 * Load WooCommerce first, then this plugin.
 */
function _ailo_track_manually_load_plugins() {
	$wc = getenv( 'WC_PLUGIN_FILE' );
	if ( ! $wc ) {
		$wc = dirname( dirname( dirname( __DIR__ ) ) ) . '/woocommerce/woocommerce.php';
	}
	if ( file_exists( $wc ) ) {
		require_once $wc;
	}
	require dirname( __DIR__ ) . '/ailo-order-tracking.php';
}
tests_add_filter( 'muplugins_loaded', '_ailo_track_manually_load_plugins' );

/**
 * WooCommerce installs its tables on activation, which never happens here.
 * Without this the order data store has nowhere to write and every test that
 * touches an order fails for a reason that has nothing to do with the plugin.
 */
function _ailo_track_install_wc() {
	if ( class_exists( 'WC_Install' ) ) {
		WC_Install::install();
		if ( class_exists( 'WooCommerce' ) ) {
			$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WC_Install::install() adds roles; the cached object has to be rebuilt or capability checks read the stale copy.
			wp_roles();
		}
	}
}
tests_add_filter( 'setup_theme', '_ailo_track_install_wc' );

require "{$_tests_dir}/includes/bootstrap.php";
