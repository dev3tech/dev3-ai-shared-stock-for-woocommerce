<?php
/**
 * Plugin Name:       DEV3 AI Shared Stock & Inventory for WooCommerce
 * Plugin URI:        https://dev3.tech/item/dev3-ai-shared-stock-for-woocommerce
 * Description:       Shared stock pools, variable deduction, attribute stock rules and AI stockout forecasts for WooCommerce.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            DEV3 Technologies
 * Author URI:        https://dev3.tech
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * WC tested up to:     10.3
 * Text Domain:       dev3-ai-shared-stock-for-woocommerce
 * Domain Path:       /languages
 *
 * @package Dev3\AiSharedStock
 */

defined( 'ABSPATH' ) || exit;

define( 'DEV3SS_VERSION', '1.0.0' );
define( 'DEV3SS_FILE', __FILE__ );
define( 'DEV3SS_PATH', plugin_dir_path( __FILE__ ) );
define( 'DEV3SS_URL', plugin_dir_url( __FILE__ ) );
define( 'DEV3SS_SLUG', 'dev3-ai-shared-stock-for-woocommerce' );
define( 'DEV3SS_NAME', 'DEV3 AI Shared Stock & Inventory for WooCommerce' );
define( 'DEV3SS_MIN_PHP', '7.4' );
define( 'DEV3SS_MIN_WP', '6.0' );
define( 'DEV3SS_DB_VERSION', '1.0.0' );

if ( version_compare( PHP_VERSION, DEV3SS_MIN_PHP, '<' ) ) {
	return;
}

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Dev3\AiSharedStock\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = DEV3SS_PATH . 'includes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', DEV3SS_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', DEV3SS_FILE, true );
		}
	}
);

register_activation_hook( __FILE__, array( '\Dev3\AiSharedStock\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\Dev3\AiSharedStock\Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', array( '\Dev3\AiSharedStock\Plugin', 'instance' ) );
