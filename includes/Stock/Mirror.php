<?php
/**
 * Keeps WooCommerce product stock in line with pool stock.
 *
 * @package Dev3\AiSharedStock
 */

namespace Dev3\AiSharedStock\Stock;

use Dev3\AiSharedStock\Data\Pools;
use Dev3\AiSharedStock\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Each pooled product (simple product or variation) gets "manage stock" on and a quantity of
 * floor(pool stock / units per item), the lowest across its pools. Storefront stock text, the
 * quantity input, product feeds, the REST API and the block checkout then all see the shared stock.
 */
class Mirror {

	/**
	 * Depth of deferral (pools are synced once at the end of an order sync).
	 *
	 * @var int
	 */
	private static $defer = 0;

	/**
	 * Pools waiting for a sync.
	 *
	 * @var array
	 */
	private static $queue = array();

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'dev3ss_stock_changed', array( __CLASS__, 'on_stock_changed' ), 20, 1 );
		add_action( 'dev3ss_pool_links_changed', array( __CLASS__, 'sync_pool' ) );
		add_action( 'dev3ss_link_deleted', array( __CLASS__, 'on_link_deleted' ), 10, 2 );
		add_action( 'dev3ss_pool_deleted', array( __CLASS__, 'on_pool_deleted' ), 10, 2 );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'sync_product_tree' ), 99 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'sync_product' ), 99 );
	}

	/**
	 * Starts deferring pool syncs.
	 */
	public static function begin() {
		++self::$defer;
	}

	/**
	 * Stops deferring and syncs queued pools.
	 */
	public static function end() {
		self::$defer = max( 0, self::$defer - 1 );
		if ( 0 === self::$defer ) {
			$queue       = array_keys( self::$queue );
			self::$queue = array();
			foreach ( $queue as $pool_id ) {
				self::sync_pool( $pool_id );
			}
		}
	}

	/**
	 * Stock changed: sync now or queue.
	 *
	 * @param int $pool_id Pool id.
	 */
	public static function on_stock_changed( $pool_id ) {
		if ( self::$defer > 0 ) {
			self::$queue[ (int) $pool_id ] = true;
			return;
		}
		self::sync_pool( $pool_id );
	}

	/**
	 * Re-syncs products that lost a link (they may still be in other pools).
	 *
	 * @param array $link     Link.
	 * @param int[] $products Products.
	 */
	public static function on_link_deleted( $link, $products ) {
		unset( $link );
		foreach ( (array) $products as $id ) {
			self::sync_product( $id );
		}
	}

	/**
	 * Re-syncs products of a deleted pool.
	 *
	 * @param int   $pool_id  Pool id.
	 * @param int[] $products Products.
	 */
	public static function on_pool_deleted( $pool_id, $products ) {
		unset( $pool_id );
		foreach ( (array) $products as $id ) {
			self::sync_product( $id );
		}
	}

	/**
	 * Whether mirroring is on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) Settings::get( 'mirror_stock', true ) && function_exists( 'wc_get_product' );
	}

	/**
	 * Syncs every product of a pool.
	 *
	 * @param int $pool_id Pool id.
	 * @return int Products updated.
	 */
	public static function sync_pool( $pool_id ) {
		if ( ! self::enabled() ) {
			return 0;
		}
		$n = 0;
		foreach ( Resolver::products_for_pool( $pool_id ) as $product_id ) {
			$n += self::sync_product( $product_id ) ? 1 : 0;
		}
		return $n;
	}

	/**
	 * Syncs a product and, for variable products, its variations.
	 *
	 * @param int $product_id Product id.
	 */
	public static function sync_product_tree( $product_id ) {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product ) {
			return;
		}
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child ) {
				self::sync_product( $child );
			}
			return;
		}
		self::sync_product( $product_id );
	}

	/**
	 * Writes pool availability into one product.
	 *
	 * @param int $product_id Simple product or variation id.
	 * @return bool Changed.
	 */
	public static function sync_product( $product_id ) {
		if ( ! self::enabled() ) {
			return false;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product || $product->is_type( array( 'variable', 'grouped', 'external' ) ) ) {
			return false;
		}
		$links = Resolver::for_product( $product );
		if ( ! $links ) {
			return false;
		}
		$qty        = Resolver::available( $links );
		$backorders = self::backorders( $links ) ? 'notify' : 'no';
		if ( null === $qty ) {
			return false;
		}
		/**
		 * Filters the stock written to a pooled product.
		 *
		 * @param int         $qty     Quantity.
		 * @param \WC_Product $product Product.
		 * @param array       $links   pool_id => per unit.
		 */
		$qty = (int) apply_filters( 'dev3ss_mirror_quantity', $qty, $product, $links );
		if ( $product->get_manage_stock( 'edit' ) && (int) $product->get_stock_quantity( 'edit' ) === $qty && $product->get_backorders( 'edit' ) === $backorders && null !== $product->get_stock_quantity( 'edit' ) ) {
			return false;
		}
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $qty );
		$product->set_backorders( $backorders );
		$product->save();
		return true;
	}

	/**
	 * True when every linked pool allows backorders.
	 *
	 * @param array $links pool_id => per.
	 * @return bool
	 */
	public static function backorders( array $links ) {
		foreach ( array_keys( $links ) as $pool_id ) {
			$pool = Pools::get( $pool_id );
			if ( $pool && ! $pool['backorders'] ) {
				return false;
			}
		}
		return (bool) $links;
	}
}
