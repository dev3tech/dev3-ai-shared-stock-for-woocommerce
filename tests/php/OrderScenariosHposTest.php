<?php
/**
 * The same 36 order scenarios with WooCommerce High-Performance Order Storage (custom order tables) on.
 *
 * @package Dev3\AiSharedStock
 */

require_once __DIR__ . '/OrderScenariosTest.php';

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer;
use Automattic\WooCommerce\Utilities\OrderUtil;

class OrderScenariosHposTest extends OrderScenariosTest {

	public function set_up() {
		wc_get_container()->get( DataSynchronizer::class )->create_database_tables();
		parent::set_up();
		// Inside the test transaction, so later test classes keep legacy storage.
		update_option( CustomOrdersTableController::CUSTOM_ORDERS_TABLE_USAGE_ENABLED_OPTION, 'yes' );
		update_option( DataSynchronizer::ORDERS_DATA_SYNC_ENABLED_OPTION, 'no' );
		update_option( 'woocommerce_feature_custom_order_tables_enabled', 'yes' );
		$this->assertTrue( OrderUtil::custom_orders_table_usage_is_enabled(), 'HPOS is on for these scenarios' );
	}

	public function test_orders_are_stored_in_custom_tables() {
		$order = $this->order( array( array( $this->simple( 'Probe', 'P', 1 ), 1 ) ) );
		global $wpdb;
		$table = OrderUtil::get_table_for_orders();
		$this->assertSame( $wpdb->prefix . 'wc_orders', $table );
		$this->assertSame( (string) $order->get_id(), $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $order->get_id() ) ) ); // phpcs:ignore
	}
}
