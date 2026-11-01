<?php
/**
 * Stops carts from taking more from a pool than it holds (classic cart + Store API / block checkout).
 *
 * @package Dev3\AiSharedStock
 */

namespace Dev3\AiSharedStock\Stock;

use Dev3\AiSharedStock\Data\Pools;
use Dev3\AiSharedStock\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce checks stock per product; two products in one pool could each pass while together
 * they need more than the pool has. This sums the demand per pool across the whole cart.
 * The Store API runs the same add-to-cart filter and `woocommerce_check_cart_items`, so the
 * block cart and checkout are covered too.
 */
class Cart_Guard {

	/**
	 * Hooks.
	 */
	public function register() {
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add' ), 20, 5 );
		add_filter( 'woocommerce_update_cart_validation', array( $this, 'validate_update' ), 20, 4 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart' ) );
	}

	/**
	 * Whether the guard is on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) Settings::get( 'cart_guard', true ) && (bool) Settings::get( 'inventory_enabled', true );
	}

	/**
	 * Demand per pool for a list of cart lines.
	 *
	 * @param array  $cart_items Cart items.
	 * @param string $skip_key   Cart key to leave out.
	 * @return array pool_id => units
	 */
	public static function demand( array $cart_items, $skip_key = '' ) {
		$out = array();
		foreach ( $cart_items as $key => $cart_item ) {
			if ( (string) $key === (string) $skip_key || Bundles::skip_cart_item( $cart_item ) ) {
				continue;
			}
			$links = Resolver::for_cart_item( $cart_item );
			foreach ( Resolver::demand( $links, (float) ( $cart_item['quantity'] ?? 0 ) ) as $pool_id => $units ) {
				$out[ $pool_id ] = ( $out[ $pool_id ] ?? 0 ) + $units;
			}
		}
		return $out;
	}

	/**
	 * Pools that cannot cover a demand.
	 *
	 * @param array $demand pool_id => units.
	 * @return array pool_id => { need, have }
	 */
	public static function shortfalls( array $demand ) {
		$out = array();
		foreach ( $demand as $pool_id => $need ) {
			$pool = Pools::get( $pool_id );
			if ( ! $pool || $pool['backorders'] || 'active' !== $pool['status'] ) {
				continue;
			}
			if ( $need > (float) $pool['stock'] + 0.00005 ) {
				$out[ $pool_id ] = array(
					'need' => $need,
					'have' => max( 0, (float) $pool['stock'] ),
				);
			}
		}
		return $out;
	}

	/**
	 * Current cart lines.
	 *
	 * @return array
	 */
	private static function cart_items() {
		return function_exists( 'WC' ) && WC()->cart ? (array) WC()->cart->get_cart() : array();
	}

	/**
	 * Attribute map for an add-to-cart request.
	 *
	 * @param int   $product_id   Product.
	 * @param int   $variation_id Variation.
	 * @param array $variations   Chosen attributes.
	 * @return array
	 */
	private static function line( $product_id, $variation_id, $variations ) {
		return array(
			'product_id'   => (int) $product_id,
			'variation_id' => (int) $variation_id,
			'variation'    => is_array( $variations ) ? $variations : array(),
		);
	}

	/**
	 * Add to cart.
	 *
	 * @param bool  $passed       Passed so far.
	 * @param int   $product_id   Product.
	 * @param int   $quantity     Quantity.
	 * @param int   $variation_id Variation.
	 * @param array $variations   Attributes.
	 * @return bool
	 */
	public function validate_add( $passed, $product_id, $quantity, $variation_id = 0, $variations = array() ) {
		if ( ! $passed || ! self::enabled() ) {
			return $passed;
		}
		$line  = self::line( $product_id, $variation_id, $variations );
		$links = Resolver::for_cart_item( $line );
		if ( ! $links || Bundles::skip_cart_item( $line ) ) {
			return $passed;
		}
		$demand = self::demand( self::cart_items() );
		$short  = self::shortfalls( self::merge( $demand, Resolver::demand( $links, $quantity ) ) );
		$short  = array_intersect_key( $short, $links );
		if ( ! $short ) {
			return $passed;
		}
		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		$can     = max( 0, (int) Resolver::available( array_intersect_key( $links, $short ), $demand ) );
		wc_add_notice( self::message_for( $product ? $product->get_name() : '', $can ), 'error' );
		return false;
	}

	/**
	 * Cart quantity update.
	 *
	 * @param bool   $passed        Passed so far.
	 * @param string $cart_item_key Key.
	 * @param array  $values        Cart item.
	 * @param int    $quantity      New quantity.
	 * @return bool
	 */
	public function validate_update( $passed, $cart_item_key, $values, $quantity ) {
		if ( ! $passed || ! self::enabled() || Bundles::skip_cart_item( (array) $values ) ) {
			return $passed;
		}
		$links = Resolver::for_cart_item( (array) $values );
		if ( ! $links ) {
			return $passed;
		}
		$demand = self::demand( self::cart_items(), $cart_item_key );
		$short  = array_intersect_key( self::shortfalls( self::merge( $demand, Resolver::demand( $links, $quantity ) ) ), $links );
		if ( ! $short ) {
			return $passed;
		}
		$product = isset( $values['data'] ) && is_object( $values['data'] ) ? $values['data'] : null;
		$can     = max( 0, (int) Resolver::available( array_intersect_key( $links, $short ), $demand ) );
		wc_add_notice( self::message_for( $product ? $product->get_name() : '', $can ), 'error' );
		return false;
	}

	/**
	 * Whole-cart check (cart page, checkout, Store API).
	 */
	public function check_cart() {
		if ( ! self::enabled() ) {
			return;
		}
		$items = self::cart_items();
		$short = self::shortfalls( self::demand( $items ) );
		if ( ! $short ) {
			return;
		}
		$names = array();
		foreach ( $items as $cart_item ) {
			if ( Bundles::skip_cart_item( $cart_item ) ) {
				continue;
			}
			if ( array_intersect_key( Resolver::for_cart_item( $cart_item ), $short ) && isset( $cart_item['data'] ) && is_object( $cart_item['data'] ) ) {
				$names[] = '"' . $cart_item['data']->get_name() . '"';
			}
		}
		$names = array_unique( $names );
		wc_add_notice(
			sprintf(
				/* translators: %s: product names */
				_n( 'Sorry, we do not have enough stock for %s. Please lower the quantity in your cart.', 'Sorry, we do not have enough stock for %s together. Please lower the quantities in your cart.', count( $names ), 'dev3-ai-shared-stock-for-woocommerce' ),
				implode( ', ', $names )
			),
			'error'
		);
	}

	/**
	 * Shopper message for a capped add.
	 *
	 * @param string $name Product name.
	 * @param int    $can  Items that can still be added.
	 * @return string
	 */
	public static function message_for( $name, $can ) {
		if ( $can <= 0 ) {
			/* translators: %s: product name */
			return sprintf( __( 'Sorry, "%s" is out of stock right now.', 'dev3-ai-shared-stock-for-woocommerce' ), $name );
		}
		/* translators: 1: number of items, 2: product name */
		return sprintf( _n( 'Sorry, only %1$d more of "%2$s" can be added to your cart.', 'Sorry, only %1$d more of "%2$s" can be added to your cart.', $can, 'dev3-ai-shared-stock-for-woocommerce' ), $can, $name );
	}

	/**
	 * Adds two demand maps.
	 *
	 * @param array $a Map.
	 * @param array $b Map.
	 * @return array
	 */
	private static function merge( array $a, array $b ) {
		foreach ( $b as $k => $v ) {
			$a[ $k ] = ( $a[ $k ] ?? 0 ) + $v;
		}
		return $a;
	}
}
