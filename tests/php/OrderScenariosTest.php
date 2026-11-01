<?php
/**
 * Acceptance (SOW Phase 1.3): stock is deducted correctly across 30+ order / refund / cancel /
 * edit scenarios, with packs, kits, variations, attribute rules, bundles and paused pools.
 *
 * @package Dev3\AiSharedStock
 */

require_once __DIR__ . '/Dev3ss_Test_Case.php';

use Dev3\AiSharedStock\Core3\Core\Settings_Store;
use Dev3\AiSharedStock\Data\Log;
use Dev3\AiSharedStock\Data\Pools;
use Dev3\AiSharedStock\Stock\Order_Sync;

class OrderScenariosTest extends Dev3ss_Test_Case {

	private $cans;
	private $cups;
	private $blank;
	private $ink;
	private $dye;
	private $can;
	private $six;
	private $case;
	private $box;
	private $tee;

	public function set_up() {
		parent::set_up();
		$this->cans  = $this->pool( 'Cola cans', 100 );
		$this->cups  = $this->pool( 'Party cups', 50 );
		$this->blank = $this->pool( 'Blank tees', 50 );
		$this->ink   = $this->pool( 'Red ink', 100 );
		$this->dye   = $this->pool( 'Red dye', 100 );
		$this->can   = $this->simple( 'Cola can', 'COLA-1', 1 );
		$this->six   = $this->simple( 'Cola 6-pack', 'COLA-6', 5 );
		$this->case  = $this->simple( 'Cola case of 24', 'COLA-24', 18 );
		$this->box   = $this->simple( 'Party box', 'BOX', 30 );
		$this->link( $this->cans, $this->can->get_id(), 1 );
		$this->link( $this->cans, $this->six->get_id(), 6 );
		$this->link( $this->cans, $this->case->get_id(), 24 );
		$this->link( $this->cans, $this->box->get_id(), 12 );
		$this->link( $this->cups, $this->box->get_id(), 10 );
		$this->tee = $this->variable( 'Tee', 'color', array( 'red', 'blue' ) );
		$this->link( $this->blank, $this->tee['parent']->get_id(), 1 );
		$this->link( $this->ink, $this->tee['variations']['red']->get_id(), 2 );
		Pools::add_link(
			$this->dye,
			array(
				'attr_key'   => 'pa_color',
				'attr_value' => 'red',
				'qty'        => 0.5,
			)
		);
	}

	private function fresh( $product ) {
		return wc_get_product( $product->get_id() );
	}

	/* 1-6: basic deduction by status */

	public function test_01_single_item_processing() {
		$this->order( array( array( $this->can, 1 ) ) );
		$this->assertSame( 99.0, $this->stock( $this->cans ) );
	}

	public function test_02_pack_deducts_its_size() {
		$this->order( array( array( $this->six, 2 ) ) );
		$this->assertSame( 88.0, $this->stock( $this->cans ) );
	}

	public function test_03_case_completed() {
		$this->order( array( array( $this->case, 1 ) ), 'completed' );
		$this->assertSame( 76.0, $this->stock( $this->cans ) );
	}

	public function test_04_mixed_order_same_pool() {
		$this->order( array( array( $this->can, 1 ), array( $this->six, 1 ), array( $this->case, 1 ) ) );
		$this->assertSame( 69.0, $this->stock( $this->cans ) );
	}

	public function test_05_on_hold_reduces() {
		$this->order( array( array( $this->six, 1 ) ), 'on-hold' );
		$this->assertSame( 94.0, $this->stock( $this->cans ) );
	}

	public function test_06_pending_does_not_deduct() {
		$this->order( array( array( $this->six, 3 ) ), 'pending' );
		$this->assertSame( 100.0, $this->stock( $this->cans ) );
	}

	/* 7-9: cancellations and idempotency */

	public function test_07_cancel_restores() {
		$order = $this->order( array( array( $this->six, 2 ) ) );
		$order->update_status( 'cancelled' );
		$this->assertSame( 100.0, $this->stock( $this->cans ) );
	}

	public function test_08_processing_to_completed_no_double_count() {
		$order = $this->order( array( array( $this->six, 1 ) ) );
		$order->update_status( 'completed' );
		$this->assertSame( 94.0, $this->stock( $this->cans ) );
	}

	public function test_09_repeated_reduce_hook_is_idempotent() {
		$order = $this->order( array( array( $this->six, 1 ) ) );
		do_action( 'woocommerce_reduce_order_stock', $order );
		do_action( 'woocommerce_reduce_order_stock', wc_get_order( $order->get_id() ) );
		$this->assertSame( 94.0, $this->stock( $this->cans ) );
	}

	/* 10-15: refunds */

	public function test_10_full_refund_with_restock() {
		$order = $this->order( array( array( $this->six, 2 ) ) );
		$this->refund( $order, array( $this->item_id( $order, $this->six ) => 2 ) );
		$this->assertSame( 100.0, $this->stock( $this->cans ) );
	}

	public function test_11_partial_refund_with_restock() {
		$order = $this->order( array( array( $this->six, 3 ) ) );
		$this->refund( $order, array( $this->item_id( $order, $this->six ) => 1 ) );
		$this->assertSame( 88.0, $this->stock( $this->cans ) );
	}

	public function test_12_refund_without_restock_keeps_stock() {
		$order = $this->order( array( array( $this->six, 3 ) ) );
		$this->refund( $order, array( $this->item_id( $order, $this->six ) => 3 ), false );
		$this->assertSame( 82.0, $this->stock( $this->cans ) );
	}

	public function test_13_two_partial_refunds() {
		$order = $this->order( array( array( $this->six, 3 ) ) );
		$item  = $this->item_id( $order, $this->six );
		$this->refund( $order, array( $item => 1 ) );
		$this->refund( wc_get_order( $order->get_id() ), array( $item => 1 ) );
		$this->assertSame( 94.0, $this->stock( $this->cans ) );
	}

	public function test_14_restock_is_capped_at_ordered_quantity() {
		$order = $this->order( array( array( $this->six, 2 ) ) );
		do_action(
			'woocommerce_refund_created',
			0,
			array(
				'order_id'      => $order->get_id(),
				'restock_items' => true,
				'line_items'    => array( $this->item_id( $order, $this->six ) => array( 'qty' => 5 ) ),
			)
		);
		$this->assertSame( 100.0, $this->stock( $this->cans ) );
	}

	public function test_15_refund_then_cancel() {
		$order = $this->order( array( array( $this->six, 3 ) ) );
		$this->refund( $order, array( $this->item_id( $order, $this->six ) => 1 ) );
		$this->assertSame( 88.0, $this->stock( $this->cans ) );
		wc_get_order( $order->get_id() )->update_status( 'cancelled' );
		$this->assertSame( 100.0, $this->stock( $this->cans ) );
	}

	/* 16-17: status round trips */

	public function test_16_cancel_then_processing_again() {
		$order = $this->order( array( array( $this->six, 1 ) ) );
		$order->update_status( 'cancelled' );
		wc_get_order( $order->get_id() )->update_status( 'processing' );
		$this->assertSame( 94.0, $this->stock( $this->cans ) );
	}

	public function test_17_back_to_pending_restores() {
		$order = $this->order( array( array( $this->case, 1 ) ) );
		$order->update_status( 'pending' );
		$this->assertSame( 100.0, $this->stock( $this->cans ) );
	}

	/* 18-21: admin order edits */

	public function test_18_edit_quantity_up() {
		$order = $this->order( array( array( $this->six, 2 ) ) );
		$item  = $order->get_item( $this->item_id( $order, $this->six ) );
		$item->set_quantity( 4 );
		$item->save();
		do_action( 'woocommerce_saved_order_items', $order->get_id(), array() );
		$this->assertSame( 76.0, $this->stock( $this->cans ) );
	}

	public function test_19_edit_quantity_down() {
		$order = $this->order( array( array( $this->six, 4 ) ) );
		$item  = $order->get_item( $this->item_id( $order, $this->six ) );
		$item->set_quantity( 1 );
		$item->save();
		do_action( 'woocommerce_saved_order_items', $order->get_id(), array() );
		$this->assertSame( 94.0, $this->stock( $this->cans ) );
	}

	public function test_20_delete_line_item_restores() {
		$order = $this->order( array( array( $this->six, 2 ), array( $this->can, 1 ) ) );
		wc_delete_order_item( $this->item_id( $order, $this->six ) );
		$this->assertSame( 99.0, $this->stock( $this->cans ) );
	}

	public function test_21_add_line_to_processing_order() {
		$order = $this->order( array( array( $this->can, 1 ) ) );
		$order->add_product( $this->six, 1 );
		$order->save();
		do_action( 'woocommerce_saved_order_items', $order->get_id(), array() );
		$this->assertSame( 93.0, $this->stock( $this->cans ) );
	}

	/* 22-25: kits, variations, attribute rules */

	public function test_22_kit_deducts_from_each_pool() {
		$this->order( array( array( $this->box, 2 ) ) );
		$this->assertSame( 76.0, $this->stock( $this->cans ) );
		$this->assertSame( 30.0, $this->stock( $this->cups ) );
	}

	public function test_23_variation_inherits_parent_pool() {
		$this->order( array( array( $this->tee['variations']['blue'], 3 ) ) );
		$this->assertSame( 47.0, $this->stock( $this->blank ) );
		$this->assertSame( 100.0, $this->stock( $this->ink ) );
		$this->assertSame( 100.0, $this->stock( $this->dye ) );
	}

	public function test_24_variation_link_and_attribute_rule_stack() {
		$this->order( array( array( $this->tee['variations']['red'], 3 ) ) );
		$this->assertSame( 47.0, $this->stock( $this->blank ) );
		$this->assertSame( 94.0, $this->stock( $this->ink ) );
		$this->assertSame( 98.5, $this->stock( $this->dye ) );
	}

	public function test_25_most_specific_link_wins_for_the_same_pool() {
		$this->link( $this->dye, $this->tee['variations']['red']->get_id(), 2 );
		$this->order( array( array( $this->tee['variations']['red'], 1 ) ) );
		$this->assertSame( 98.0, $this->stock( $this->dye ), 'variation link (2) beats the store-wide rule (0.5)' );
	}

	/* 26-28: paused pools, changed links, deleted pools */

	public function test_26_paused_pool_is_ignored() {
		Pools::update( $this->cups, array( 'status' => 'paused' ) );
		$this->order( array( array( $this->box, 1 ) ) );
		$this->assertSame( 88.0, $this->stock( $this->cans ) );
		$this->assertSame( 50.0, $this->stock( $this->cups ) );
	}

	public function test_27_cancel_uses_quantities_from_order_time() {
		$order = $this->order( array( array( $this->six, 1 ) ) );
		$this->link( $this->cans, $this->six->get_id(), 12 );
		wc_get_order( $order->get_id() )->update_status( 'cancelled' );
		$this->assertSame( 100.0, $this->stock( $this->cans ) );
	}

	public function test_28_deleted_pool_does_not_break_restore() {
		$order = $this->order( array( array( $this->box, 1 ) ) );
		Pools::delete( $this->cups );
		wc_get_order( $order->get_id() )->update_status( 'cancelled' );
		$this->assertSame( 100.0, $this->stock( $this->cans ) );
	}

	/* 29-31: bundles + master switch */

	private function bundle_order() {
		$order = wc_create_order();
		$order->add_product( $this->box, 1 );
		$order->add_product( $this->can, 2 );
		foreach ( $order->get_items() as $item ) {
			if ( $item->get_product_id() === $this->box->get_id() ) {
				$item->add_meta_data( '_bundled_items', array( 'x' ) );
			} else {
				$item->add_meta_data( '_bundled_by', 'abc' );
			}
			$item->save();
		}
		$order->calculate_totals();
		$order->save();
		$order->update_status( 'processing' );
		return $order;
	}

	public function test_29_bundle_mode_children_skips_container() {
		Settings_Store::update( array( 'bundle_mode' => 'children' ) );
		Settings_Store::flush();
		$this->bundle_order();
		$this->assertSame( 98.0, $this->stock( $this->cans ) );
		$this->assertSame( 50.0, $this->stock( $this->cups ) );
	}

	public function test_30_bundle_mode_container_skips_children() {
		Settings_Store::update( array( 'bundle_mode' => 'container' ) );
		Settings_Store::flush();
		$this->bundle_order();
		$this->assertSame( 88.0, $this->stock( $this->cans ) );
		$this->assertSame( 40.0, $this->stock( $this->cups ) );
	}

	public function test_31_bundle_mode_both_counts_every_linked_line() {
		$this->bundle_order();
		$this->assertSame( 86.0, $this->stock( $this->cans ) );
	}

	public function test_32_master_switch_off() {
		Settings_Store::update( array( 'inventory_enabled' => false ) );
		Settings_Store::flush();
		$this->order( array( array( $this->six, 2 ) ) );
		$this->assertSame( 100.0, $this->stock( $this->cans ) );
	}

	/* 33-35: fractions, logs, product mirror */

	public function test_33_fractional_units() {
		$fabric = $this->pool( 'Linen (m)', 10 );
		$napkin = $this->simple( 'Linen napkin', 'NAP', 4 );
		$this->link( $fabric, $napkin->get_id(), 0.25 );
		$order = $this->order( array( array( $napkin, 3 ) ) );
		$this->assertSame( 9.25, $this->stock( $fabric ) );
		$this->refund( $order, array( $this->item_id( $order, $napkin ) => 1 ) );
		$this->assertSame( 9.5, $this->stock( $fabric ) );
	}

	public function test_34_log_rows_and_order_note() {
		$order = $this->order( array( array( $this->box, 1 ) ) );
		$order->update_status( 'cancelled' );
		$rows    = Log::for_order( $order->get_id() );
		$reasons = wp_list_pluck( $rows, 'reason' );
		sort( $reasons );
		$this->assertSame( array( 'cancel', 'cancel', 'order', 'order' ), $reasons );
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertNotEmpty(
			array_filter(
				$notes,
				static function ( $n ) {
					return false !== strpos( $n->content, 'Shared stock updated' );
				}
			)
		);
	}

	public function test_35_product_stock_mirrors_pool() {
		$this->order( array( array( $this->six, 2 ) ) );
		$this->assertSame( 88, $this->fresh( $this->can )->get_stock_quantity() );
		$this->assertSame( 14, $this->fresh( $this->six )->get_stock_quantity() );
		$this->assertSame( 3, $this->fresh( $this->case )->get_stock_quantity() );
		$this->assertSame( 5, $this->fresh( $this->box )->get_stock_quantity(), 'kit = min(88/12, 50/10)' );
		$this->assertSame( 50, $this->fresh( $this->tee['variations']['red'] )->get_stock_quantity(), 'min(50/1, 100/2, 100/0.5)' );
		Pools::set_stock( $this->cans, 3 );
		$this->assertSame( 'outofstock', $this->fresh( $this->six )->get_stock_status() );
		$this->assertSame( 'instock', $this->fresh( $this->can )->get_stock_status() );
	}

	public function test_36_holds_stock_reads_the_order_flag() {
		$order = $this->order( array( array( $this->can, 1 ) ) );
		$this->assertTrue( Order_Sync::holds_stock( $order ) );
		$order->update_status( 'cancelled' );
		$this->assertFalse( Order_Sync::holds_stock( wc_get_order( $order->get_id() ) ) );
	}
}
