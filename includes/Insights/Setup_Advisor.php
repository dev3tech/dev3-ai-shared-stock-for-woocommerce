<?php
/**
 * "Set up pools for me": reads products and attributes and proposes shared pools.
 *
 * @package Dev3\AiSharedStock
 */

namespace Dev3\AiSharedStock\Insights;

use Dev3\AiSharedStock\Data\Pools;

defined( 'ABSPATH' ) || exit;

/**
 * Rule-based proposals (always available) + the catalogue snapshot DEV3 AI reads.
 * Proposals are drafts: nothing is created until the admin applies them.
 */
class Setup_Advisor {

	/**
	 * Attribute names that usually mean a shared raw material.
	 */
	const MATERIAL_WORDS = '/(material|fabric|wood|metal|leather|yarn|base|blank|stone|paper)/i';

	/**
	 * Attribute names that mean "how many in the pack".
	 */
	const PACK_WORDS = '/(pack|quantity|qty|count|bundle|case|units?)/i';

	/**
	 * Catalogue snapshot (products, variations, attributes, stock).
	 *
	 * @param int $limit Max products.
	 * @return array[]
	 */
	public static function snapshot( $limit = 250 ) {
		$out      = array();
		$products = wc_get_products(
			array(
				'limit'   => (int) $limit,
				'status'  => array( 'publish', 'private', 'draft' ),
				'type'    => array( 'simple', 'variable' ),
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);
		foreach ( $products as $product ) {
			$row = array(
				'id'         => $product->get_id(),
				'name'       => mb_substr( $product->get_name(), 0, 120 ),
				'sku'        => (string) $product->get_sku(),
				'type'       => $product->get_type(),
				'stock'      => $product->get_stock_quantity(),
				'attributes' => array(),
				'variations' => array(),
			);
			foreach ( $product->get_attributes() as $key => $attribute ) {
				if ( ! is_object( $attribute ) ) {
					continue;
				}
				$values                                      = $attribute->is_taxonomy()
					? wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'slugs' ) )
					: array_map( 'sanitize_title', (array) $attribute->get_options() );
				$row['attributes'][ sanitize_title( $key ) ] = array(
					'label'  => wc_attribute_label( $attribute->get_name() ),
					'values' => array_slice( array_values( (array) $values ), 0, 20 ),
				);
			}
			if ( $product->is_type( 'variable' ) ) {
				foreach ( array_slice( $product->get_children(), 0, 40 ) as $child_id ) {
					$child = wc_get_product( $child_id );
					if ( ! $child ) {
						continue;
					}
					$attrs = array();
					foreach ( (array) $child->get_attributes() as $k => $v ) {
						$attrs[ sanitize_title( $k ) ] = sanitize_title( (string) $v );
					}
					$row['variations'][] = array(
						'id'    => $child->get_id(),
						'sku'   => (string) $child->get_sku(),
						'stock' => $child->get_stock_quantity(),
						'attrs' => $attrs,
					);
				}
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Pack size in a product name ("6-pack", "Pack of 12", "Case of 24", "x6"), or 0.
	 *
	 * @param string $name Name.
	 * @return array { size, base }
	 */
	public static function pack_size( $name ) {
		$patterns = array(
			'/\b(?:pack|case|box|crate|carton|set|bundle)\s+of\s+(\d{1,4})\b/i',
			'/\b(\d{1,4})\s*[- ]?\s*(?:pack|pk|pcs|pieces|count|ct|piece|pc)\b/i',
			'/(?:^|\s)[x×]\s*(\d{1,4})\b/iu',
			'/\b(\d{1,4})\s*[x×](?:\s|$)/iu',
		);
		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $name, $m ) && (int) $m[1] > 1 ) {
				$base = trim( preg_replace( '/[\s\-–,:()\[\]]+$/u', '', preg_replace( '/^[\s\-–,:()\[\]]+/u', '', str_replace( $m[0], ' ', $name ) ) ) );
				$base = preg_replace( '/\s{2,}/', ' ', str_replace( array( '()', '[]' ), '', $base ) );
				return array(
					'size' => (int) $m[1],
					'base' => trim( $base ),
				);
			}
		}
		return array(
			'size' => 0,
			'base' => trim( $name ),
		);
	}

	/**
	 * Number in an attribute value ("6", "6-pack", "pack-of-12"), or 0.
	 *
	 * @param string $value Value slug.
	 * @return int
	 */
	public static function number_in( $value ) {
		return preg_match( '/(\d{1,4})/', (string) $value, $m ) ? (int) $m[1] : 0;
	}

	/**
	 * Rule-based proposals from a snapshot.
	 *
	 * @param array[] $snapshot Snapshot.
	 * @return array[] Proposals.
	 */
	public static function rule_proposals( array $snapshot ) {
		$linked = array();
		foreach ( Pools::all_links() as $link ) {
			if ( '' === $link['attr_key'] ) {
				$linked[ $link['product_id'] ] = true;
			}
		}
		$out = array();

		// A. Pack sizes in product names: "Cola can" + "Cola can 6-pack" + "Cola can (Case of 24)".
		$groups = array();
		foreach ( $snapshot as $p ) {
			if ( 'simple' !== $p['type'] || isset( $linked[ $p['id'] ] ) ) {
				continue;
			}
			$pack                   = self::pack_size( $p['name'] );
			$key                    = strtolower( $pack['base'] );
			$groups[ $key ][]       = array(
				'product' => $p,
				'size'    => max( 1, $pack['size'] ),
				'packed'  => $pack['size'] > 1,
			);
			$groups[ $key ]['base'] = $pack['base'];
		}
		// A single unit named "<base> bottle/can/bar…" joins the pack group of "<base>".
		foreach ( array_keys( $groups ) as $key ) {
			if ( 2 !== count( $groups[ $key ] ) || ! empty( $groups[ $key ][0]['packed'] ) ) {
				continue;
			}
			foreach ( array_keys( $groups ) as $target ) {
				if ( $target !== $key && '' !== $target && 0 === strpos( $key, $target . ' ' ) && isset( $groups[ $target ] ) ) {
					array_unshift( $groups[ $target ], $groups[ $key ][0] );
					unset( $groups[ $key ] );
					break;
				}
			}
		}
		foreach ( $groups as $group ) {
			$base = $group['base'];
			unset( $group['base'] );
			$packed = array_filter(
				$group,
				static function ( $g ) {
					return $g['packed'];
				}
			);
			if ( ! $packed || count( $group ) < 2 ) {
				continue;
			}
			$links = array();
			$stock = 0;
			foreach ( $group as $g ) {
				$links[] = array(
					'product_id' => $g['product']['id'],
					'label'      => $g['product']['name'],
					'qty'        => $g['size'],
					'attr_key'   => '',
					'attr_value' => '',
				);
				$stock   = max( $stock, (int) $g['product']['stock'] * $g['size'] );
			}
			$out[] = array(
				'name'   => $base,
				'unit'   => __( 'units', 'dev3-ai-shared-stock-for-woocommerce' ),
				'stock'  => $stock,
				/* translators: %d: number of products */
				'reason' => sprintf( __( '%d products sell the same item in different pack sizes. Each pack deducts its size from one shared pool.', 'dev3-ai-shared-stock-for-woocommerce' ), count( $group ) ),
				'links'  => $links,
				'source' => 'rules',
			);
		}

		// B. Variable products whose variations are pack sizes.
		foreach ( $snapshot as $p ) {
			if ( 'variable' !== $p['type'] || isset( $linked[ $p['id'] ] ) ) {
				continue;
			}
			foreach ( $p['attributes'] as $key => $attr ) {
				if ( ! preg_match( self::PACK_WORDS, $key . ' ' . $attr['label'] ) ) {
					continue;
				}
				$links = array();
				$stock = 0;
				foreach ( $p['variations'] as $v ) {
					$n = self::number_in( $v['attrs'][ $key ] ?? '' );
					if ( $n <= 0 || isset( $linked[ $v['id'] ] ) ) {
						continue;
					}
					$links[] = array(
						'product_id' => $v['id'],
						/* translators: 1: product, 2: value */
						'label'      => sprintf( __( '%1$s – %2$s', 'dev3-ai-shared-stock-for-woocommerce' ), $p['name'], $v['attrs'][ $key ] ),
						'qty'        => $n,
						'attr_key'   => '',
						'attr_value' => '',
					);
					$stock   = max( $stock, (int) $v['stock'] * $n );
				}
				if ( count( $links ) >= 2 ) {
					$out[] = array(
						'name'   => $p['name'],
						'unit'   => __( 'units', 'dev3-ai-shared-stock-for-woocommerce' ),
						'stock'  => $stock,
						/* translators: %s: attribute label */
						'reason' => sprintf( __( 'The "%s" variations are pack sizes of one item, so they can share one pool.', 'dev3-ai-shared-stock-for-woocommerce' ), $attr['label'] ),
						'links'  => $links,
						'source' => 'rules',
					);
				}
				break;
			}
		}

		// C. A material / fabric attribute used across several products: one pool per value.
		$values = array();
		foreach ( $snapshot as $p ) {
			foreach ( $p['attributes'] as $key => $attr ) {
				if ( 0 !== strpos( $key, 'pa_' ) || ! preg_match( self::MATERIAL_WORDS, $key . ' ' . $attr['label'] ) ) {
					continue;
				}
				foreach ( $attr['values'] as $value ) {
					$values[ $key ][ $value ]['label']      = $attr['label'];
					$values[ $key ][ $value ]['products'][] = $p['name'];
				}
			}
		}
		$existing = array();
		foreach ( Pools::all_links() as $link ) {
			$existing[ $link['attr_key'] . '=' . $link['attr_value'] ] = true;
		}
		foreach ( $values as $key => $by_value ) {
			foreach ( $by_value as $value => $info ) {
				if ( count( array_unique( $info['products'] ) ) < 2 || isset( $existing[ $key . '=' . $value ] ) ) {
					continue;
				}
				$term  = get_term_by( 'slug', $value, $key );
				$label = $term ? $term->name : $value;
				$out[] = array(
					/* translators: 1: attribute label, 2: value */
					'name'   => sprintf( __( '%1$s: %2$s', 'dev3-ai-shared-stock-for-woocommerce' ), $info['label'], $label ),
					'unit'   => __( 'units', 'dev3-ai-shared-stock-for-woocommerce' ),
					'stock'  => 0,
					/* translators: 1: count, 2: label */
					'reason' => sprintf( __( '%1$d products use the same %2$s. A store-wide attribute rule makes them all draw from one pool.', 'dev3-ai-shared-stock-for-woocommerce' ), count( array_unique( $info['products'] ) ), strtolower( $info['label'] ) ),
					'links'  => array(
						array(
							'product_id' => 0,
							/* translators: 1: attribute label, 2: value */
							'label'      => sprintf( __( 'Any product with %1$s = %2$s', 'dev3-ai-shared-stock-for-woocommerce' ), $info['label'], $label ),
							'qty'        => 1,
							'attr_key'   => $key,
							'attr_value' => $value,
						),
					),
					'source' => 'rules',
				);
			}
		}
		return array_slice( $out, 0, 30 );
	}

	/**
	 * Validates proposals (from AI or the client) against the catalogue.
	 *
	 * @param array   $proposals Proposals.
	 * @param array[] $snapshot  Snapshot.
	 * @return array[]
	 */
	public static function validate( array $proposals, array $snapshot ) {
		$known = array();
		$attrs = array();
		foreach ( $snapshot as $p ) {
			$known[ $p['id'] ] = $p['name'];
			foreach ( $p['attributes'] as $key => $attr ) {
				foreach ( $attr['values'] as $v ) {
					$attrs[ $key ][ $v ] = $attr['label'];
				}
			}
			foreach ( $p['variations'] as $v ) {
				$label = array();
				foreach ( $v['attrs'] as $val ) {
					$label[] = $val;
				}
				$known[ $v['id'] ] = $p['name'] . ( $label ? ' – ' . implode( ', ', $label ) : '' );
			}
		}
		$out = array();
		foreach ( $proposals as $proposal ) {
			$name = mb_substr( sanitize_text_field( (string) ( $proposal['name'] ?? '' ) ), 0, 120 );
			if ( '' === $name ) {
				continue;
			}
			$links = array();
			foreach ( (array) ( $proposal['links'] ?? array() ) as $link ) {
				$pid = (int) ( $link['product_id'] ?? 0 );
				$key = sanitize_title( (string) ( $link['attr_key'] ?? '' ) );
				$val = sanitize_title( (string) ( $link['attr_value'] ?? '' ) );
				$qty = round( (float) ( $link['qty'] ?? 1 ), 4 );
				if ( $qty <= 0 || $qty > 10000 ) {
					continue;
				}
				if ( $pid && ! isset( $known[ $pid ] ) ) {
					continue;
				}
				if ( '' !== $key && ! isset( $attrs[ $key ][ $val ] ) ) {
					continue;
				}
				if ( ! $pid && '' === $key ) {
					continue;
				}
				if ( $pid && '' === $key ) {
					$label = $known[ $pid ];
				} elseif ( $pid ) {
					/* translators: 1: product, 2: attribute, 3: value */
					$label = sprintf( __( '%1$s with %2$s = %3$s', 'dev3-ai-shared-stock-for-woocommerce' ), $known[ $pid ], $attrs[ $key ][ $val ], $val );
				} else {
					/* translators: 1: attribute, 2: value */
					$label = sprintf( __( 'Any product with %1$s = %2$s', 'dev3-ai-shared-stock-for-woocommerce' ), $attrs[ $key ][ $val ], $val );
				}
				$links[] = array(
					'product_id' => $pid,
					'label'      => $label,
					'qty'        => $qty,
					'attr_key'   => $key,
					'attr_value' => $val,
				);
			}
			if ( ! $links ) {
				continue;
			}
			$out[] = array(
				'name'   => $name,
				'unit'   => mb_substr( sanitize_text_field( (string) ( $proposal['unit'] ?? '' ) ), 0, 30 ),
				'stock'  => max( 0, round( (float) ( $proposal['stock'] ?? 0 ), 4 ) ),
				'reason' => mb_substr( sanitize_text_field( (string) ( $proposal['reason'] ?? '' ) ), 0, 400 ),
				'links'  => $links,
				'source' => in_array( $proposal['source'] ?? '', array( 'ai', 'rules' ), true ) ? $proposal['source'] : 'ai',
			);
		}
		return array_slice( $out, 0, 30 );
	}

	/**
	 * Creates accepted proposals.
	 *
	 * @param array $proposals Validated proposals.
	 * @return array { created: int[], errors: string[] }
	 */
	public static function apply( array $proposals ) {
		$created = array();
		$errors  = array();
		foreach ( $proposals as $proposal ) {
			$id = Pools::create(
				array(
					'name'  => $proposal['name'],
					'unit'  => $proposal['unit'],
					'stock' => $proposal['stock'],
					'notes' => $proposal['reason'],
				)
			);
			if ( is_wp_error( $id ) ) {
				$errors[] = $proposal['name'] . ': ' . $id->get_error_message();
				continue;
			}
			foreach ( $proposal['links'] as $link ) {
				$res = Pools::add_link( $id, $link );
				if ( is_wp_error( $res ) ) {
					$errors[] = $proposal['name'] . ': ' . $res->get_error_message();
				}
			}
			$created[] = $id;
		}
		return array(
			'created' => $created,
			'errors'  => $errors,
		);
	}
}
