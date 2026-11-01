<?php
/**
 * Admin menu + React app mount.
 *
 * @package Dev3\AiSharedStock
 */

namespace Dev3\AiSharedStock\Admin;

use Dev3\AiSharedStock\Core3\Core\Assets;
use Dev3\AiSharedStock\Core3\Core\Capabilities;
use Dev3\AiSharedStock\Rest\Rest_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Top-level menu page.
 */
class Admin_Page {

	/**
	 * Hook suffix.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DEV3SS_FILE ), array( $this, 'links' ) );
	}

	/**
	 * Menu.
	 */
	public function menu() {
		$this->hook = (string) add_menu_page( DEV3SS_NAME, __( 'DEV3 Shared Stock', 'dev3-ai-shared-stock-for-woocommerce' ), Rest_Controller::capability(), DEV3SS_SLUG, array( $this, 'render' ), 'dashicons-database', 56 );
	}

	/**
	 * Extra boot data for the React app.
	 *
	 * @return array
	 */
	public static function boot_config() {
		if ( ! is_admin() ) {
			return array();
		}
		return array(
			'canSettings'    => current_user_can( Capabilities::manage() ),
			'wooActive'      => class_exists( 'WooCommerce' ),
			'currency'       => function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES ) : '$',
			'dateFormat'     => get_option( 'date_format' ),
			'productsUrl'    => admin_url( 'edit.php?post_type=product' ),
			'manageStockUrl' => admin_url( 'admin.php?page=wc-settings&tab=products&section=inventory' ),
		);
	}

	/**
	 * Mount point.
	 */
	public function render() {
		// The h1 names the page for screen readers; wp-header-end keeps admin notices above the app.
		echo '<div class="wrap dev3ss-wrap"><h1 class="screen-reader-text">' . esc_html( DEV3SS_NAME ) . '</h1><hr class="wp-header-end"><div id="dev3ss-admin-root" class="dev3ss-root dev3ss-root--app"></div></div>';
	}

	/**
	 * Assets on our page only.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue( $hook ) {
		if ( $hook === $this->hook ) {
			Assets::enqueue( 'admin' );
		}
	}

	/**
	 * Settings link.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . DEV3SS_SLUG ) ) . '">' . esc_html__( 'Settings', 'dev3-ai-shared-stock-for-woocommerce' ) . '</a>' );
		return $links;
	}
}
