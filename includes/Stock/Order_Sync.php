<?php
/**
 * Deducts and restores pool stock for orders, refunds, cancellations and order edits.
 *
 * @package Dev3\AiSharedStock
 */

namespace Dev3\AiSharedStock\Stock;

use Dev3\AiSharedStock\Data\Pools;
use Dev3\AiSharedStock\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Idempotent order sync. Each order line remembers what it took from each pool:
 *
 * - `_dev3ss_map`       pool_id => units per item, fixed when the line is first deducted
 * - `_dev3ss_deducted`  pool_id => units currently taken
 * - `_dev3ss_restocked` items returned to stock by refunds
 *
 * The target for a line is (qty - restocked) x units per item while the order holds stock, else 0.
 * Every sync moves each pool by (target - deducted), so repeated hooks never double-count.
 * Works the same with HPOS and legacy order storage (only the CRUD API is used).
 */
class Order_Sync {

	const META_MAP       = '_dev3ss_map';
	const META_DEDUCTED  = '_dev3ss_deducted';
	const META_RESTOCKED = '_dev3ss_restocked';

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'woocommerce_reduce_order_stock', array( $this, 'on_reduce' ) );
		add_action( 'woocommerce_restore_order_stock', array( $this, 'on_restore' ) );
		add_action( 'woocommerce_refund_created', array( $this, 'on_refund' ), 10, 2 );
		add_action( 'woocommerce_saved_order_items', array( $this, 'on_saved_items' ), 20, 1 );
		add_action( 'woocommerce_before_delete_order_item', array( $this, 'on_delete_item' ) );
	}

	/**
	 * Whether deduction is on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) Settings::get( 'inventory_enabled', true );
	}

	/**
	 * WooCommerce reduced the order's stock (payment, processing, on-hold, completed).
	 *
	 * @param \WC_Order $order Order.
	 */
	public function on_reduce( $order ) {
		self::sync_order( $order, true, 'order' );
	}

	/**
	 * WooCommerce restored the order's stock (cancelled, failed, back to pending).
	 *
	 * @param \WC_Order $order Order.
	 */
	public function on_restore( $order ) {
		self::sync_order( $order, false, 'cancel' );
	}

	/**
	 * A refund with "restock refunded items" returns those items to their pools.
	 *
	 * @param int   $refund_id Refund id.
	 * @param array $args      wc_create_refund() args.
	 */
	public function on_refund( $refund_id, $args ) {
		unset( $refund_id );
		if ( empty( $args['restock_items'] ) || empty( $args['line_items'] ) || empty( $args['order_id'] ) ) {
			return;
		}
		$order = wc_get_order( $args['order_id'] );
		if ( ! $order ) {
			return;
		}
		$changed = false;
		foreach ( (array) $args['line_items'] as $item_id => $line ) {
			$qty  = abs( (float) ( $line['qty'] ?? 0 ) );
			$item = $order->get_item( $item_id );
			if ( $qty <= 0 || ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$restocked = (float) $item->get_meta( self::META_RESTOCKED, true );
			$item->update_meta_data( self::META_RESTOCKED, min( (float) $item->get_quantity(), $restocked + $qty ) );
			$item->save();
			$changed = true;
		}
		if ( $changed ) {
			self::sync_order( $order, self::holds_stock( $order ), 'refund' );
		}
	}

	/**
	 * Admin edited line items (quantities, added or removed lines).
	 *
	 * @param int $order_id Order id.
	 */
	public function on_saved_items( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order && self::holds_stock( $order ) ) {
			self::sync_order( $order, true, 'edit' );
		}
	}

	/**
	 * A line item is about to be deleted: give its units back.
	 *
	 * @param int $item_id Item id.
	 */
	public function on_delete_item( $item_id ) {
		$item = \WC_Order_Factory::get_order_item( $item_id );
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return;
		}
		Mirror::begin();
		self::sync_item( $item, false, 'edit' );
		Mirror::end();
	}

	/**
	 * Whether WooCommerce currently counts the order's stock as reduced.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public static function holds_stock( $order ) {
		return (bool) $order->get_data_store()->get_stock_reduced( $order->get_id() );
	}

	/**
	 * Syncs every product line of an order.
	 *
	 * @param \WC_Order|int $order  Order.
	 * @param bool          $active Whether the order should hold stock.
	 * @param string        $reason Log reason for releases (deductions are logged as "order").
	 * @return array pool_id => net change.
	 */
	public static function sync_order( $order, $active, $reason = 'order' ) {
		$order = is_object( $order ) ? $order : wc_get_order( $order );
		if ( ! $order || ! self::enabled() ) {
			return array();
		}
		/**
		 * Filters whether an order's lines are synced with pools.
		 *
		 * @param bool      $sync   Sync.
		 * @param \WC_Order $order  Order.
		 * @param bool      $active Holds stock.
		 */
		if ( ! apply_filters( 'dev3ss_sync_order', true, $order, $active ) ) {
			return array();
		}
		Mirror::begin();
		$changes = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			foreach ( self::sync_item( $item, $active, $reason, $order ) as $pool_id => $diff ) {
				$changes[ $pool_id ] = ( $changes[ $pool_id ] ?? 0 ) + $diff;
			}
		}
		Mirror::end();
		if ( $changes ) {
			self::note( $order, $changes );
		}
		return $changes;
	}

	/**
	 * Syncs one line.
	 *
	 * @param \WC_Order_Item_Product $item   Item.
	 * @param bool                   $active Holds stock.
	 * @param string                 $reason Reason for releases.
	 * @param \WC_Order|null         $order  Order.
	 * @return array pool_id => change applied (negative = deducted).
	 */
	public static function sync_item( $item, $active, $reason = 'order', $order = null ) {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return array();
		}
		$map = $item->get_meta( self::META_MAP, true );
		if ( ! is_array( $map ) ) {
			if ( ! $active ) {
				return array();
			}
			$map = Bundles::skip_order_item( $item ) ? array() : Resolver::for_order_item( $item );
			$item->update_meta_data( self::META_MAP, $map );
		}
		$deducted  = $item->get_meta( self::META_DEDUCTED, true );
		$deducted  = is_array( $deducted ) ? $deducted : array();
		$restocked = (float) $item->get_meta( self::META_RESTOCKED, true );
		$units     = $active ? max( 0, (float) $item->get_quantity() - $restocked ) : 0;
		$applied   = array();
		$order_id  = $order ? $order->get_id() : (int) $item->get_order_id();
		foreach ( array_unique( array_merge( array_keys( $map ), array_keys( $deducted ) ) ) as $pool_id ) {
			$target  = round( $units * (float) ( $map[ $pool_id ] ?? 0 ), 4 );
			$current = (float) ( $deducted[ $pool_id ] ?? 0 );
			$diff    = round( $target - $current, 4 );
			if ( abs( $diff ) < 0.00005 ) {
				continue;
			}
			$result = Pools::adjust(
				$pool_id,
				-1 * $diff,
				$diff > 0 ? 'order' : $reason,
				array(
					'order_id'   => $order_id,
					'item_id'    => $item->get_id(),
					'product_id' => $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id(),
					/* translators: %s: order number */
					'note'       => sprintf( __( 'Order #%s', 'dev3-ai-shared-stock-for-woocommerce' ), $order ? $order->get_order_number() : $order_id ),
					'user_id'    => get_current_user_id(),
				)
			);
			if ( is_wp_error( $result ) ) {
				unset( $deducted[ $pool_id ] ); // Pool deleted: forget it.
				continue;
			}
			$deducted[ $pool_id ] = $target;
			$applied[ $pool_id ]  = -1 * $diff;
		}
		$deducted = array_filter(
			$deducted,
			static function ( $v ) {
				return abs( (float) $v ) >= 0.00005;
			}
		);
		$item->update_meta_data( self::META_DEDUCTED, $deducted );
		$item->save();
		return $applied;
	}

	/**
	 * Adds an order note summarising pool changes.
	 *
	 * @param \WC_Order $order   Order.
	 * @param array     $changes pool_id => change.
	 */
	private static function note( $order, array $changes ) {
		$parts = array();
		foreach ( $changes as $pool_id => $change ) {
			$pool = Pools::get( $pool_id, true );
			if ( ! $pool || abs( $change ) < 0.00005 ) {
				continue;
			}
			$parts[] = sprintf( '%1$s %2$s%3$s (%4$s %5$s)', $pool['name'], $change > 0 ? '+' : '', wc_format_decimal( $change, 4, true ), wc_format_decimal( $pool['stock'], 4, true ), $pool['unit'] );
		}
		if ( $parts ) {
			/* translators: %s: list of pool changes */
			$order->add_order_note( sprintf( __( 'Shared stock updated: %s', 'dev3-ai-shared-stock-for-woocommerce' ), implode( ', ', $parts ) ) );
		}
	}
}
