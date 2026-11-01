<?php
/**
 * PHPUnit bootstrap: WordPress test suite from wp-env (WP_TESTS_DIR) + this plugin and its dependencies.
 *
 * Run: npx wp-env run tests-cli --env-cwd=wp-content/plugins/dev3-ai-shared-stock-for-woocommerce vendor/bin/phpunit
 *
 * @package Dev3\AiSharedStock
 */

$dev3ss_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : '/wordpress-phpunit';
if ( ! file_exists( $dev3ss_tests_dir . '/includes/functions.php' ) ) {
	echo "WordPress test suite not found in {$dev3ss_tests_dir}. Run the tests inside wp-env (tests-cli).\n"; // phpcs:ignore
	exit( 1 );
}
require_once dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
require_once $dev3ss_tests_dir . '/includes/functions.php';

// Optional per-plugin hook (not overwritten by sync): register dependencies, e.g.
// add_filter( 'dev3ss_test_dependencies', fn() => array( 'woocommerce.latest-stable/woocommerce.php' ) );
if ( file_exists( __DIR__ . '/dependencies.php' ) ) {
	require __DIR__ . '/dependencies.php';
}

tests_add_filter(
	'muplugins_loaded',
	static function () {
		// Load required third-party plugins first when they are installed in wp-env (e.g. WooCommerce).
		foreach ( (array) apply_filters( 'dev3ss_test_dependencies', array() ) as $dev3ss_dep ) {
			if ( file_exists( WP_PLUGIN_DIR . '/' . $dev3ss_dep ) ) {
				require WP_PLUGIN_DIR . '/' . $dev3ss_dep;
			}
		}
		require dirname( __DIR__, 2 ) . '/dev3-ai-shared-stock-for-woocommerce.php';
	}
);

require $dev3ss_tests_dir . '/includes/bootstrap.php';
