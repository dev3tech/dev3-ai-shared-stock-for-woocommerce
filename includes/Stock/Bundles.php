<?php
/**
 * Product Bundles / Composite Products compatibility.
 *
 * @package Dev3\AiSharedStock
 */

namespace Dev3\AiSharedStock\Stock;

use Dev3\AiSharedStock\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Bundle and composite plugins add the container and each bundled product as separate lines.
 * Each line is resolved on its own; the "bundle_mode" setting decides which lines count:
 * both (default), only the bundled children, or only the container.
 */
class Bundles {

	/**
	 * Order-item meta that marks a container line.
	 */
	const CONTAINER_META = array( '_bundled_items', '_composite_children', '_mnm_config' );

	/**
	 * Order-item meta that marks a child line.
	 */
	const CHILD_META = array( '_bundled_by', '_composite_parent', '_mnm_container' );

	/**
	 * Cart-item keys that mark a container line.
	 */
	const CONTAINER_CART = array( 'bundled_items', 'composite_children', 'mnm_contents' );

	/**
	 * Cart-item keys that mark a child line.
	 */
	const CHILD_CART = array( 'bundled_by', 'composite_parent', 'mnm_container' );

	/**
	 * Role of an order line: container|child|''.
	 *
	 * @param \WC_Order_Item_Product $item Item.
	 * @return string
	 */
	public static function order_role( $item ) {
		foreach ( self::CHILD_META as $key ) {
			if ( '' !== (string) self::meta_string( $item->get_meta( $key, true ) ) ) {
				return 'child';
			}
		}
		foreach ( self::CONTAINER_META as $key ) {
			if ( ! empty( $item->get_meta( $key, true ) ) ) {
				return 'container';
			}
		}
		return '';
	}

	/**
	 * Role of a cart line.
	 *
	 * @param array $cart_item Cart item.
	 * @return string
	 */
	public static function cart_role( array $cart_item ) {
		foreach ( self::CHILD_CART as $key ) {
			if ( ! empty( $cart_item[ $key ] ) ) {
				return 'child';
			}
		}
		foreach ( self::CONTAINER_CART as $key ) {
			if ( ! empty( $cart_item[ $key ] ) ) {
				return 'container';
			}
		}
		return '';
	}

	/**
	 * Whether a line with this role is skipped under the current mode.
	 *
	 * @param string $role container|child|''.
	 * @return bool
	 */
	public static function skip_role( $role ) {
		$mode = (string) Settings::get( 'bundle_mode', 'both' );
		$skip = ( 'children' === $mode && 'container' === $role ) || ( 'container' === $mode && 'child' === $role );
		/**
		 * Filters whether a bundle/composite line is skipped.
		 *
		 * @param bool   $skip Skip.
		 * @param string $role Role.
		 * @param string $mode Mode.
		 */
		return (bool) apply_filters( 'dev3ss_skip_bundle_role', $skip, $role, $mode );
	}

	/**
	 * Skip an order line?
	 *
	 * @param \WC_Order_Item_Product $item Item.
	 * @return bool
	 */
	public static function skip_order_item( $item ) {
		return self::skip_role( self::order_role( $item ) );
	}

	/**
	 * Skip a cart line?
	 *
	 * @param array $cart_item Cart item.
	 * @return bool
	 */
	public static function skip_cart_item( array $cart_item ) {
		return self::skip_role( self::cart_role( $cart_item ) );
	}

	/**
	 * Scalar meta as string.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function meta_string( $value ) {
		return is_scalar( $value ) ? (string) $value : ( empty( $value ) ? '' : '1' );
	}
}
