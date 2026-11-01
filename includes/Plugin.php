<?php
/**
 * Bootstrap and wiring.
 *
 * @package Dev3\AiSharedStock
 */

namespace Dev3\AiSharedStock;

use Dev3\AiSharedStock\Core3\Core\Assets;
use Dev3\AiSharedStock\Core3\Core\Jobs;
use Dev3\AiSharedStock\Core3\Core\Privacy;
use Dev3\AiSharedStock\Core3\Core\Schema;
use Dev3\AiSharedStock\Data\Tables;
use Dev3\AiSharedStock\Insights\Ai_Features;
use Dev3\AiSharedStock\Insights\Anomalies;
use Dev3\AiSharedStock\Insights\Forecast;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton entry point.
 */
final class Plugin {

	const CRON_HOOK = 'dev3ss_daily';

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Boots once.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Activation.
	 */
	public static function activate() {
		self::register_schema();
		Schema::install( DEV3SS_DB_VERSION );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( 'dev3ss_license_check' ); // Licence check from builds before licensing was removed.
	}

	/**
	 * Plugin tables / settings defaults are registered here so activation and runtime agree.
	 */
	public static function register_schema() {
		add_filter( 'dev3ss_settings_defaults', array( Settings::class, 'defaults' ) );
		add_filter( 'dev3ss_settings_sanitize', array( Settings::class, 'sanitize' ), 10, 3 );
		add_filter( 'dev3ss_schema_sql', array( Tables::class, 'schema' ), 10, 2 );
	}

	/**
	 * Whether WooCommerce is loaded.
	 *
	 * @return bool
	 */
	public static function woo_active() {
		return class_exists( 'WooCommerce' ) && function_exists( 'WC' );
	}

	/**
	 * Daily housekeeping: usage pruning, forecasts (statistics only) and the anomaly scan.
	 */
	public static function daily() {
		Schema::prune_usage();
		if ( self::woo_active() ) {
			Forecast::refresh_all();
			Anomalies::scan();
		}
	}

	/**
	 * Hooks.
	 */
	private function boot() {
		load_plugin_textdomain( 'dev3-ai-shared-stock-for-woocommerce', false, dirname( plugin_basename( DEV3SS_FILE ) ) . '/languages' );
		self::register_schema();
		add_action( 'admin_init', static fn() => Schema::maybe_install( DEV3SS_DB_VERSION ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'daily' ) );
		add_action( 'rest_api_init', array( $this, 'rest' ) );
		Privacy::init();
		Ai_Features::register_prompts();
		// Background job types must be registered on every request (the runner looks them up).
		Jobs::register( Forecast::JOB, array( Forecast::class, 'run_chunk' ) );
		add_action( 'init', static fn() => Assets::register( array( 'admin' ), Admin\Admin_Page::boot_config() ) );
		if ( is_admin() ) {
			( new Admin\Admin_Page() )->register();
		}
		if ( ! self::woo_active() ) {
			add_action( 'admin_notices', array( $this, 'woo_notice' ) );
			return;
		}
		( new Stock\Order_Sync() )->register();
		( new Stock\Mirror() )->register();
		( new Stock\Cart_Guard() )->register();
		( new Stock\Watcher() )->register();
		if ( is_admin() ) {
			( new Admin\Product_Panel() )->register();
		}
	}

	/**
	 * Notice when WooCommerce is missing.
	 */
	public function woo_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html( DEV3SS_NAME ),
			esc_html__( 'needs WooCommerce. Install and activate WooCommerce to use shared stock pools.', 'dev3-ai-shared-stock-for-woocommerce' )
		);
	}

	/**
	 * REST routes.
	 */
	public function rest() {
		( new Core3\Rest\Core_Controller() )->register_routes();
		if ( self::woo_active() ) {
			( new Rest\Rest_Controller() )->register_routes();
		}
	}
}
