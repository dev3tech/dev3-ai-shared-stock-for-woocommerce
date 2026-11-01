<?php
/**
 * Loads WooCommerce for the test suite and installs its tables once.
 *
 * @package Dev3\AiSharedStock
 */

tests_add_filter(
	'dev3ss_test_dependencies',
	static function ( $deps ) {
		$deps[] = file_exists( WP_PLUGIN_DIR . '/woocommerce/woocommerce.php' ) ? 'woocommerce/woocommerce.php' : 'woocommerce.latest-stable/woocommerce.php';
		return $deps;
	}
);

tests_add_filter(
	'setup_theme',
	static function () {
		if ( class_exists( 'WC_Install' ) ) {
			define( 'WC_REMOVE_ALL_DATA', true );
			WC_Install::install();
			$GLOBALS['wp_roles'] = null; // phpcs:ignore
			wp_roles();
			\Dev3\AiSharedStock\Plugin::activate();
		}
	}
);

