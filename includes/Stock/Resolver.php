<?php
/**
 * Works out which pools a product, cart line or order line draws from, and how much per unit.
 *
 * @package Dev3\AiSharedStock
 */

namespace Dev3\AiSharedStock\Stock;

use Dev3\AiSharedStock\Data\Pools;

defined( 'ABSPATH' ) || exit;

/**
 * Link resolution.
 *
 * Every matching link applies (a product linked to two pools draws from both, like a kit).
 * When several links point at the same pool, the most specific wins:
 * variation link (4) > product link (3) > attribute rule on that product (2) > store-wide attribute rule (1).
 */
class Resolver {

	/**
	 * Resolves links.
	 *
	 * @param int   $product_id   Product id (parent for variations).
	 * @param int   $variation_id Variation id or 0.
	 * @param array $attrs        Attribute key (pa_color / color) => slug[] the item has.
	 * @return array pool_id => units drawn per item sold.
	 */
	public static function resolve( $product_id, $variation_id = 0, array $attrs = array() ) {
		$product_id   = (int) $product_id;
		$variation_id = (int) $variation_id;
		$best         = array();
		foreach ( Pools::all_links() as $link ) {
			$pool = Pools::get( $link['pool_id'] );
			if ( ! $pool || 'active' !== $pool['status'] ) {
				continue;
			}
			if ( '' === $link['attr_key'] ) {
				if ( $variation_id && $link['product_id'] === $variation_id ) {
					$prio = 4;
				} elseif ( $link['product_id'] === $product_id ) {
					$prio = 3;
				} else {
					continue;
				}
			} else {
				if ( $link['product_id'] && $link['product_id'] !== $product_id && $link['product_id'] !== $variation_id ) {
					continue;
				}
				$values = isset( $attrs[ $link['attr_key'] ] ) ? (array) $attrs[ $link['attr_key'] ] : array();
				if ( ! in_array( $link['attr_value'], $values, true ) ) {
					continue;
				}
				$prio = $link['product_id'] ? 2 : 1;
			}
			if ( ! isset( $best[ $link['pool_id'] ] ) || $best[ $link['pool_id'] ][0] < $prio ) {
				$best[ $link['pool_id'] ] = array( $prio, (float) $link['qty'] );
			}
		}
		$out = array();
		foreach ( $best as $pool_id => $pair ) {
			$out[ (int) $pool_id ] = $pair[1];
		}
		/**
		 * Filters the pools (pool_id => units per item) a product draws from.
		 *
		 * @param array $out          Pools.
		 * @param int   $product_id   Product id.
		 * @param int   $variation_id Variation id.
		 * @param array $attrs        Attributes.
		 */
		return (array) apply_filters( 'dev3ss_resolve_links', $out, $product_id, $variation_id, $attrs );
	}

	/**
	 * Attribute slugs a product has (variation values + parent attributes).
	 *
	 * @param \WC_Product $product Product.
	 * @return array key => slug[]
	 */
	public static function product_attrs( $product ) {
		$out = array();
		if ( ! $product ) {
			return $out;
		}
		if ( $product->is_type( 'variation' ) ) {
			foreach ( (array) $product->get_attributes() as $key => $value ) {
				$out[ sanitize_title( $key ) ] = '' === (string) $value ? array() : array( sanitize_title( (string) $value ) );
			}
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent ) {
				foreach ( self::product_attrs( $parent ) as $key => $values ) {
					if ( ! isset( $out[ $key ] ) ) {
						$out[ $key ] = $values;
					}
				}
			}
			return $out;
		}
		foreach ( (array) $product->get_attributes() as $key => $attribute ) {
			if ( ! is_object( $attribute ) || ! method_exists( $attribute, 'is_taxonomy' ) ) {
				continue;
			}
			if ( $attribute->get_variation() && $product->is_type( 'variable' ) ) {
				continue; // Chosen per variation.
			}
			if ( $attribute->is_taxonomy() ) {
				$slugs = wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'slugs' ) );
			} else {
				$slugs = array_map( 'sanitize_title', (array) $attribute->get_options() );
			}
			$out[ sanitize_title( $key ) ] = array_values( array_map( 'strval', (array) $slugs ) );
		}
		return $out;
	}

	/**
	 * Pools for a catalogue product (used to mirror stock).
	 *
	 * @param \WC_Product $product Product (simple, variation…).
	 * @return array
	 */
	public static function for_product( $product ) {
		if ( ! $product ) {
			return array();
		}
		$is_var = $product->is_type( 'variation' );
		return self::resolve( $is_var ? $product->get_parent_id() : $product->get_id(), $is_var ? $product->get_id() : 0, self::product_attrs( $product ) );
	}

	/**
	 * Pools for a cart line.
	 *
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public static function for_cart_item( array $cart_item ) {
		$product_id   = (int) ( $cart_item['product_id'] ?? 0 );
		$variation_id = (int) ( $cart_item['variation_id'] ?? 0 );
		$product      = wc_get_product( $variation_id ? $variation_id : $product_id );
		$attrs        = self::product_attrs( $product );
		foreach ( (array) ( $cart_item['variation'] ?? array() ) as $key => $value ) {
			$key = sanitize_title( preg_replace( '/^attribute_/', '', (string) $key ) );
			if ( '' !== (string) $value ) {
				$attrs[ $key ] = array( sanitize_title( (string) $value ) );
			}
		}
		return self::resolve( $product_id, $variation_id, $attrs );
	}

	/**
	 * Pools for an order line.
	 *
	 * @param \WC_Order_Item_Product $item Item.
	 * @return array
	 */
	public static function for_order_item( $item ) {
		$product = $item->get_product();
		$attrs   = self::product_attrs( $product );
		foreach ( $attrs as $key => $values ) {
			if ( ! $values ) {
				$chosen = (string) $item->get_meta( $key, true );
				if ( '' !== $chosen ) {
					$attrs[ $key ] = array( sanitize_title( $chosen ) );
				}
			}
		}
		return self::resolve( (int) $item->get_product_id(), (int) $item->get_variation_id(), $attrs );
	}

	/**
	 * Catalogue products (simple products + variations) a link covers.
	 *
	 * @param array $link Link.
	 * @return int[]
	 */
	public static function products_for_link( array $link ) {
		global $wpdb;
		$ids = array();
		if ( '' === $link['attr_key'] ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $link['product_id'] ) : null;
			if ( ! $product ) {
				return array();
			}
			if ( $product->is_type( 'variable' ) ) {
				return array_map( 'intval', $product->get_children() );
			}
			return array( (int) $link['product_id'] );
		}
		// Variations with this attribute value (stored as a slug for global attributes, as text for custom ones).
		$meta_key = 'attribute_' . $link['attr_key'];
		if ( $link['product_id'] ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_parent = %d AND p.post_type = 'product_variation'", $meta_key, (int) $link['product_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = 'product_variation' LIMIT 5000", $meta_key ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery
		}
		foreach ( (array) $rows as $row ) {
			if ( '' !== (string) $row['meta_value'] && sanitize_title( (string) $row['meta_value'] ) === $link['attr_value'] ) {
				$ids[] = (int) $row['post_id'];
			}
		}
		// Simple products (and variable parents, whose variations inherit it) carrying the term.
		if ( taxonomy_exists( $link['attr_key'] ) ) {
			$term = get_term_by( 'slug', $link['attr_value'], $link['attr_key'] );
			if ( $term ) {
				$objects = get_objects_in_term( (int) $term->term_id, $link['attr_key'] );
				foreach ( is_array( $objects ) ? $objects : array() as $object_id ) {
					if ( $link['product_id'] && (int) $object_id !== (int) $link['product_id'] ) {
						continue;
					}
					$product = wc_get_product( $object_id );
					if ( ! $product ) {
						continue;
					}
					if ( $product->is_type( 'variable' ) ) {
						foreach ( $product->get_children() as $child ) {
							$ids[] = (int) $child;
						}
					} else {
						$ids[] = (int) $object_id;
					}
				}
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Catalogue products a pool covers.
	 *
	 * @param int $pool_id Pool id.
	 * @return int[]
	 */
	public static function products_for_pool( $pool_id ) {
		$ids = array();
		foreach ( Pools::links( $pool_id ) as $link ) {
			$ids = array_merge( $ids, self::products_for_link( $link ) );
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Units of each pool that a quantity of items would use.
	 *
	 * @param array $links pool_id => per unit.
	 * @param float $qty   Items.
	 * @return array pool_id => units
	 */
	public static function demand( array $links, $qty ) {
		$out = array();
		foreach ( $links as $pool_id => $per ) {
			$out[ $pool_id ] = round( (float) $per * (float) $qty, 4 );
		}
		return $out;
	}

	/**
	 * How many items the pools can still supply (min over pools of floor(stock / per)).
	 *
	 * @param array $links    pool_id => per unit.
	 * @param array $reserved pool_id => units already taken (e.g. by the cart).
	 * @return int|null Null when no linked pool limits it (all allow backorders or none active).
	 */
	public static function available( array $links, array $reserved = array() ) {
		$min = null;
		foreach ( $links as $pool_id => $per ) {
			$pool = Pools::get( $pool_id );
			if ( ! $pool || $per <= 0 ) {
				continue;
			}
			$free = (float) $pool['stock'] - (float) ( $reserved[ $pool_id ] ?? 0 );
			$can  = (int) floor( $free / (float) $per + 1e-9 );
			$min  = null === $min ? $can : min( $min, $can );
		}
		return $min;
	}
}
