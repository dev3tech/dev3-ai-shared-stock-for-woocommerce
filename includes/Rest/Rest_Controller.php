<?php
/**
 * REST API (dev3ss/v1): pools, links, stock, history, CSV, forecasts, POs, alerts, setup, ask.
 *
 * @package Dev3\AiSharedStock
 */

namespace Dev3\AiSharedStock\Rest;

use Dev3\AiSharedStock\Core3\Api\Ai;
use Dev3\AiSharedStock\Core3\Core\Jobs;
use Dev3\AiSharedStock\Core3\Rest\Base_Controller;
use Dev3\AiSharedStock\Csv\Csv;
use Dev3\AiSharedStock\Data\Alerts;
use Dev3\AiSharedStock\Data\Log;
use Dev3\AiSharedStock\Data\Pools;
use Dev3\AiSharedStock\Data\Purchase_Orders;
use Dev3\AiSharedStock\Insights\Ai_Features;
use Dev3\AiSharedStock\Insights\Anomalies;
use Dev3\AiSharedStock\Insights\Forecast;
use Dev3\AiSharedStock\Insights\Setup_Advisor;
use Dev3\AiSharedStock\Stock\Mirror;
use Dev3\AiSharedStock\Stock\Resolver;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under dev3ss/v1. Every route needs the REST nonce and the inventory capability
 * (manage_woocommerce by default; filter dev3ss_inventory_capability).
 */
class Rest_Controller extends Base_Controller {

	/**
	 * Inventory capability.
	 *
	 * @return string
	 */
	public static function capability() {
		/**
		 * Capability needed to manage shared stock.
		 *
		 * @param string $cap Capability.
		 */
		return (string) apply_filters( 'dev3ss_inventory_capability', 'manage_woocommerce' );
	}

	/**
	 * Permission: nonce + inventory capability.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_inventory( \WP_REST_Request $request ) {
		if ( ! $this->nonce_ok( $request ) ) {
			return new \WP_Error( 'dev3ss_bad_nonce', __( 'Your session has expired. Reload the page and try again.', 'dev3-ai-shared-stock-for-woocommerce' ), array( 'status' => 403 ) );
		}
		if ( ! current_user_can( self::capability() ) ) {
			return new \WP_Error( 'dev3ss_forbidden', __( 'You do not have permission to do that.', 'dev3-ai-shared-stock-for-woocommerce' ), array( 'status' => rest_authorization_required_code() ) );
		}
		return true;
	}

	/**
	 * Registers one route.
	 *
	 * @param string $path     Path.
	 * @param string $methods  Methods.
	 * @param string $callback Method name.
	 * @param array  $args     Args schema.
	 */
	private function route( $path, $methods, $callback, array $args = array() ) {
		register_rest_route(
			self::NS,
			$path,
			array(
				'methods'             => $methods,
				'callback'            => array( $this, $callback ),
				'permission_callback' => array( $this, 'can_inventory' ),
				'args'                => $args,
			)
		);
	}

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		$id   = '(?P<id>\d+)';
		$read = \WP_REST_Server::READABLE;
		$post = \WP_REST_Server::CREATABLE;
		$edit = \WP_REST_Server::EDITABLE;
		$del  = \WP_REST_Server::DELETABLE;

		$this->route( '/dashboard', $read, 'dashboard' );
		$this->route(
			'/pools',
			$read,
			'list_pools',
			array(
				'search' => array( 'type' => 'string' ),
				'status' => array( 'type' => 'string' ),
			)
		);
		$this->route( '/pools', $post, 'create_pool' );
		$this->route( '/pools/' . $id, $read, 'get_pool' );
		$this->route( '/pools/' . $id, $edit, 'update_pool' );
		$this->route( '/pools/' . $id, $del, 'delete_pool' );
		$this->route(
			'/pools/' . $id . '/adjust',
			$post,
			'adjust_pool',
			array(
				'mode' => array(
					'type'     => 'string',
					'enum'     => array( 'add', 'remove', 'set' ),
					'required' => true,
				),
				'qty'  => array(
					'type'     => 'number',
					'required' => true,
				),
				'note' => array( 'type' => 'string' ),
			)
		);
		$this->route(
			'/pools/' . $id . '/links',
			$post,
			'add_link',
			array(
				'product_id' => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'attr_key'   => array( 'type' => 'string' ),
				'attr_value' => array( 'type' => 'string' ),
				'qty'        => array(
					'type'    => 'number',
					'default' => 1,
				),
			)
		);
		$this->route( '/links/' . $id, $del, 'delete_link' );
		$this->route( '/pools/' . $id . '/sync', $post, 'sync_pool' );
		$this->route( '/pools/' . $id . '/forecast', $post, 'refresh_forecast' );
		$this->route( '/pools/' . $id . '/explain', $post, 'explain_forecast' );
		$this->route(
			'/pools/' . $id . '/po-draft',
			$post,
			'po_draft',
			array(
				'qty'  => array( 'type' => 'number' ),
				'date' => array( 'type' => 'string' ),
				'ai'   => array(
					'type'    => 'boolean',
					'default' => true,
				),
			)
		);
		$this->route( '/products/search', $read, 'search_products', array( 'q' => array( 'type' => 'string' ) ) );
		$this->route( '/attributes', $read, 'attributes' );
		$this->route(
			'/history',
			$read,
			'history',
			array(
				'pool_id'  => array( 'type' => 'integer' ),
				'reason'   => array( 'type' => 'string' ),
				'from'     => array( 'type' => 'string' ),
				'to'       => array( 'type' => 'string' ),
				'page'     => array(
					'type'    => 'integer',
					'default' => 1,
					'minimum' => 1,
				),
				'per_page' => array(
					'type'    => 'integer',
					'default' => 25,
					'minimum' => 1,
					'maximum' => 200,
				),
			)
		);
		$this->route(
			'/export',
			$read,
			'export',
			array(
				'type' => array(
					'type'     => 'string',
					'enum'     => array( 'pools', 'links', 'history' ),
					'required' => true,
				),
			)
		);
		$this->route(
			'/import',
			$post,
			'import',
			array(
				'type'    => array(
					'type'     => 'string',
					'enum'     => array( 'pools', 'links' ),
					'required' => true,
				),
				'csv'     => array(
					'type'     => 'string',
					'required' => true,
				),
				'dry_run' => array(
					'type'    => 'boolean',
					'default' => true,
				),
			)
		);
		$this->route( '/forecasts', $read, 'forecasts' );
		$this->route( '/forecasts/run', $post, 'run_forecasts', array( 'explain' => array( 'type' => 'boolean' ) ) );
		$this->route( '/purchase-orders', $read, 'list_pos', array( 'status' => array( 'type' => 'string' ) ) );
		$this->route( '/purchase-orders', $post, 'create_po' );
		$this->route( '/purchase-orders/' . $id, $edit, 'update_po' );
		$this->route( '/purchase-orders/' . $id . '/send', $post, 'send_po' );
		$this->route( '/purchase-orders/' . $id . '/receive', $post, 'receive_po', array( 'qty' => array( 'type' => 'number' ) ) );
		$this->route( '/purchase-orders/' . $id . '/cancel', $post, 'cancel_po' );
		$this->route( '/alerts', $read, 'list_alerts', array( 'status' => array( 'type' => 'string' ) ) );
		$this->route( '/alerts/scan', $post, 'scan_alerts' );
		$this->route( '/alerts/' . $id . '/dismiss', $post, 'dismiss_alert' );
		$this->route( '/alerts/' . $id . '/reopen', $post, 'reopen_alert' );
		$this->route( '/alerts/' . $id . '/explain', $post, 'explain_alert' );
		$this->route(
			'/alerts/' . $id . '/fix',
			$post,
			'fix_alert',
			array(
				'stock' => array(
					'type'     => 'number',
					'required' => true,
				),
			)
		);
		$this->route( '/setup/suggest', $post, 'setup_suggest', array( 'ai' => array( 'type' => 'boolean' ) ) );
		$this->route( '/setup/apply', $post, 'setup_apply', array( 'proposals' => array( 'type' => 'array' ) ) );
		$this->route(
			'/ask',
			$post,
			'ask',
			array(
				'question' => array(
					'type'     => 'string',
					'required' => true,
				),
				'history'  => array( 'type' => 'array' ),
				'ai'       => array(
					'type'    => 'boolean',
					'default' => true,
				),
			)
		);
	}

	/* --------------------------------------------------------------- helpers */

	/**
	 * Pool with links, linked products and a fresh-enough forecast.
	 *
	 * @param array $pool Pool.
	 * @return array
	 */
	public static function pool_detail( array $pool ) {
		$pool['links']    = array_map( array( __CLASS__, 'link_out' ), Pools::links( $pool['id'] ) );
		$pool['products'] = array();
		foreach ( array_slice( Resolver::products_for_pool( $pool['id'] ), 0, 200 ) as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}
			$links              = Resolver::for_product( $product );
			$pool['products'][] = array(
				'id'       => $product->get_id(),
				'name'     => wp_strip_all_tags( $product->get_name() ),
				'sku'      => (string) $product->get_sku(),
				'type'     => $product->get_type(),
				'stock'    => $product->get_stock_quantity(),
				'status'   => $product->get_stock_status(),
				'per'      => isset( $links[ $pool['id'] ] ) ? $links[ $pool['id'] ] : null,
				'pools'    => count( $links ),
				'edit_url' => get_edit_post_link( $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id(), 'raw' ),
			);
		}
		if ( ! $pool['forecast'] || ( $pool['forecast']['today'] ?? '' ) !== gmdate( 'Y-m-d' ) ) {
			$pool['forecast'] = Forecast::refresh( $pool['id'] );
		}
		$pool['purchase_orders'] = Purchase_Orders::all( array( 'pool_id' => $pool['id'] ) );
		return $pool;
	}

	/**
	 * Link with a readable label.
	 *
	 * @param array $link Link.
	 * @return array
	 */
	public static function link_out( array $link ) {
		$label = '';
		$kind  = 'product';
		if ( $link['product_id'] ) {
			$product = wc_get_product( $link['product_id'] );
			$label   = $product ? wp_strip_all_tags( $product->get_name() ) : sprintf( '#%d', $link['product_id'] );
			if ( $product && $product->is_type( 'variation' ) ) {
				$kind = 'variation';
			} elseif ( $product && $product->is_type( 'variable' ) ) {
				$kind = 'variable';
			}
		}
		if ( '' !== $link['attr_key'] ) {
			$attr_label = taxonomy_exists( $link['attr_key'] ) ? wc_attribute_label( $link['attr_key'] ) : $link['attr_key'];
			$term       = taxonomy_exists( $link['attr_key'] ) ? get_term_by( 'slug', $link['attr_value'], $link['attr_key'] ) : null;
			$value      = $term ? $term->name : $link['attr_value'];
			$rule       = $attr_label . ' = ' . $value;
			$label      = $link['product_id']
				/* translators: 1: product, 2: rule */
				? sprintf( __( '%1$s, when %2$s', 'dev3-ai-shared-stock-for-woocommerce' ), $label, $rule )
				/* translators: %s: rule */
				: sprintf( __( 'Any product with %s', 'dev3-ai-shared-stock-for-woocommerce' ), $rule );
			$kind = 'attribute';
		}
		$link['label'] = $label;
		$link['kind']  = $kind;
		return $link;
	}

	/**
	 * JSON body (or params) as array.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private function body( \WP_REST_Request $request ) {
		$json = $request->get_json_params();
		return is_array( $json ) ? $json : $request->get_params();
	}

	/* ---------------------------------------------------------------- routes */

	/**
	 * GET /dashboard.
	 *
	 * @return \WP_REST_Response
	 */
	public function dashboard() {
		$pools   = Pools::all();
		$active  = array_values( array_filter( $pools, static fn( $p ) => 'active' === $p['status'] ) );
		$today   = gmdate( 'Y-m-d' );
		$low     = 0;
		$out     = 0;
		$now     = 0;
		$risk14  = 0;
		$used30  = 0.0;
		$linked  = 0;
		$at_risk = array();
		foreach ( $active as $pool ) {
			$f = $pool['forecast'];
			if ( ! $f || ( $f['today'] ?? '' ) !== $today ) {
				$f = Forecast::refresh( $pool['id'] );
			}
			$linked += $pool['links'];
			if ( $pool['stock'] <= 0 ) {
				++$out;
			} elseif ( $pool['stock'] <= $pool['low_threshold'] ) {
				++$low;
			}
			if ( $f && in_array( $f['urgency'], array( 'now', 'out' ), true ) ) {
				++$now;
			}
			if ( $f && null !== $f['days_left'] && $f['days_left'] <= 14 ) {
				++$risk14;
			}
			if ( $f ) {
				$used30 += array_sum( $f['series'] );
			}
			$at_risk[] = array(
				'id'            => $pool['id'],
				'name'          => $pool['name'],
				'unit'          => $pool['unit'],
				'stock'         => $pool['stock'],
				'low_threshold' => $pool['low_threshold'],
				'days_left'     => $f ? $f['days_left'] : null,
				'stockout_date' => $f ? $f['stockout_date'] : null,
				'reorder_qty'   => $f ? $f['reorder_qty'] : 0,
				'reorder_date'  => $f ? $f['reorder_date'] : null,
				'urgency'       => $f ? $f['urgency'] : 'idle',
				'rate_per_day'  => $f ? $f['rate_per_day'] : 0,
				'series'        => $f ? array_values( $f['series'] ) : array(),
			);
		}
		usort(
			$at_risk,
			static function ( $a, $b ) {
				$x = null === $a['days_left'] ? PHP_INT_MAX : $a['days_left'];
				$y = null === $b['days_left'] ? PHP_INT_MAX : $b['days_left'];
				return $x <=> $y;
			}
		);
		return $this->respond(
			array(
				'kpis'    => array(
					'pools'       => count( $pools ),
					'active'      => count( $active ),
					'links'       => $linked,
					'low'         => $low,
					'out'         => $out,
					'reorder_now' => $now,
					'risk_14'     => $risk14,
					'alerts'      => Alerts::open_count(),
					'used_30'     => round( $used30, 2 ),
					'open_pos'    => count( Purchase_Orders::all( array( 'status' => 'sent' ) ) ),
				),
				'at_risk' => array_slice( $at_risk, 0, 8 ),
				'alerts'  => Alerts::query( 'open', 5 ),
				'recent'  => Log::query( array( 'per_page' => 8 ) )['rows'],
				'woo'     => array(
					'manage_stock' => 'yes' === get_option( 'woocommerce_manage_stock', 'yes' ),
					'hpos'         => class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),
				),
				'ai'      => Ai::status(),
			)
		);
	}

	/**
	 * GET /pools.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_pools( \WP_REST_Request $request ) {
		$pools = Pools::all(
			array(
				'search' => (string) $request['search'],
				'status' => (string) $request['status'],
			)
		);
		foreach ( $pools as &$pool ) {
			$f                = $pool['forecast'];
			$pool['forecast'] = $f ? array_intersect_key( $f, array_flip( array( 'days_left', 'stockout_date', 'reorder_date', 'reorder_qty', 'urgency', 'rate_per_day', 'trend_pct', 'today' ) ) ) : null;
		}
		unset( $pool );
		return $this->respond( $pools );
	}

	/**
	 * POST /pools.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create_pool( \WP_REST_Request $request ) {
		$data = $this->body( $request );
		$id   = Pools::create( $data );
		if ( is_wp_error( $id ) ) {
			return $this->respond( $id );
		}
		foreach ( (array) ( $data['links'] ?? array() ) as $link ) {
			Pools::add_link( $id, (array) $link );
		}
		return $this->respond( self::pool_detail( Pools::get( $id, true ) ), 201 );
	}

	/**
	 * GET /pools/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_pool( \WP_REST_Request $request ) {
		$pool = Pools::get( (int) $request['id'], true );
		return $this->respond( $pool ? self::pool_detail( $pool ) : Pools::missing() );
	}

	/**
	 * PUT /pools/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function update_pool( \WP_REST_Request $request ) {
		$data = $this->body( $request );
		unset( $data['stock'], $data['id'] );
		$pool = Pools::update( (int) $request['id'], $data );
		return $this->respond( is_wp_error( $pool ) ? $pool : self::pool_detail( $pool ) );
	}

	/**
	 * DELETE /pools/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function delete_pool( \WP_REST_Request $request ) {
		return $this->respond( Pools::delete( (int) $request['id'] ) ? array( 'deleted' => true ) : Pools::missing() );
	}

	/**
	 * POST /pools/{id}/adjust.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function adjust_pool( \WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$qty  = round( (float) $request['qty'], 4 );
		$note = mb_substr( sanitize_text_field( (string) $request['note'] ), 0, 200 );
		if ( 'set' !== $request['mode'] && $qty <= 0 ) {
			return $this->respond( new \WP_Error( 'dev3ss_adjust_qty', __( 'Enter a quantity above 0.', 'dev3-ai-shared-stock-for-woocommerce' ), array( 'status' => 400 ) ) );
		}
		$ctx = array( 'note' => $note );
		if ( 'set' === $request['mode'] ) {
			$after = Pools::set_stock( $id, $qty, 'manual', $ctx );
		} else {
			$after = Pools::adjust( $id, 'add' === $request['mode'] ? $qty : -1 * $qty, 'manual', $ctx );
		}
		if ( is_wp_error( $after ) ) {
			return $this->respond( $after );
		}
		Forecast::refresh( $id );
		return $this->respond( self::pool_detail( Pools::get( $id, true ) ) );
	}

	/**
	 * POST /pools/{id}/links.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function add_link( \WP_REST_Request $request ) {
		$res = Pools::add_link(
			(int) $request['id'],
			array(
				'product_id' => (int) $request['product_id'],
				'attr_key'   => (string) $request['attr_key'],
				'attr_value' => (string) $request['attr_value'],
				'qty'        => (float) $request['qty'],
			)
		);
		return $this->respond( is_wp_error( $res ) ? $res : self::pool_detail( Pools::get( (int) $request['id'], true ) ), 201 );
	}

	/**
	 * DELETE /links/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function delete_link( \WP_REST_Request $request ) {
		$link = null;
		foreach ( Pools::all_links() as $l ) {
			if ( (int) $request['id'] === $l['id'] ) {
				$link = $l;
			}
		}
		if ( ! $link || ! Pools::delete_link( $link['id'] ) ) {
			return $this->respond( new \WP_Error( 'dev3ss_link_missing', __( 'That link no longer exists.', 'dev3-ai-shared-stock-for-woocommerce' ), array( 'status' => 404 ) ) );
		}
		return $this->respond( self::pool_detail( Pools::get( $link['pool_id'], true ) ) );
	}

	/**
	 * POST /pools/{id}/sync.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function sync_pool( \WP_REST_Request $request ) {
		$pool = Pools::get( (int) $request['id'], true );
		if ( ! $pool ) {
			return $this->respond( Pools::missing() );
		}
		$n = Mirror::sync_pool( $pool['id'] );
		return $this->respond(
			array(
				'updated' => $n,
				'pool'    => self::pool_detail( Pools::get( $pool['id'], true ) ),
			)
		);
	}

	/**
	 * POST /pools/{id}/forecast.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function refresh_forecast( \WP_REST_Request $request ) {
		$f = Forecast::refresh( (int) $request['id'] );
		return $this->respond( $f ? $f : Pools::missing() );
	}

	/**
	 * POST /pools/{id}/explain (DEV3 AI).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function explain_forecast( \WP_REST_Request $request ) {
		return $this->respond( Ai_Features::explain_forecast( (int) $request['id'] ) );
	}

	/**
	 * POST /pools/{id}/po-draft (DEV3 AI or template). Nothing is saved.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function po_draft( \WP_REST_Request $request ) {
		$qty = null === $request['qty'] ? null : (float) $request['qty'];
		return $this->respond( Ai_Features::po_draft( (int) $request['id'], $qty, (string) $request['date'], (bool) $request['ai'] ) );
	}

	/**
	 * GET /products/search.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function search_products( \WP_REST_Request $request ) {
		$q = trim( sanitize_text_field( (string) $request['q'] ) );
		if ( '' === $q ) {
			$ids = wc_get_products(
				array(
					'limit'   => 20,
					'return'  => 'ids',
					'orderby' => 'date',
					'order'   => 'DESC',
					'type'    => array( 'simple', 'variable' ),
				)
			);
		} else {
			$store = \WC_Data_Store::load( 'product' );
			$ids   = $store->search_products( $q, '', true, true, 25 );
		}
		$out = array();
		foreach ( array_filter( array_map( 'intval', (array) $ids ) ) as $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product || $product->is_type( array( 'grouped', 'external' ) ) ) {
				continue;
			}
			$out[] = array(
				'id'        => $product->get_id(),
				'name'      => wp_strip_all_tags( $product->get_name() ),
				'sku'       => (string) $product->get_sku(),
				'type'      => $product->get_type(),
				'parent_id' => $product->get_parent_id(),
				'stock'     => $product->get_stock_quantity(),
				'pools'     => count( Resolver::for_product( $product ) ),
			);
			if ( $product->is_type( 'variable' ) && '' !== $q ) {
				foreach ( array_slice( $product->get_children(), 0, 30 ) as $child_id ) {
					$child = wc_get_product( $child_id );
					if ( $child ) {
						$out[] = array(
							'id'        => $child->get_id(),
							'name'      => wp_strip_all_tags( $child->get_name() ),
							'sku'       => (string) $child->get_sku(),
							'type'      => 'variation',
							'parent_id' => $product->get_id(),
							'stock'     => $child->get_stock_quantity(),
							'pools'     => count( Resolver::for_product( $child ) ),
						);
					}
				}
			}
		}
		$seen = array();
		$out  = array_values(
			array_filter(
				$out,
				static function ( $p ) use ( &$seen ) {
					if ( isset( $seen[ $p['id'] ] ) ) {
						return false;
					}
					$seen[ $p['id'] ] = true;
					return true;
				}
			)
		);
		return $this->respond( array_slice( $out, 0, 60 ) );
	}

	/**
	 * GET /attributes.
	 *
	 * @return \WP_REST_Response
	 */
	public function attributes() {
		$out = array();
		foreach ( wc_get_attribute_taxonomies() as $tax ) {
			$key   = wc_attribute_taxonomy_name( $tax->attribute_name );
			$terms = get_terms(
				array(
					'taxonomy'   => $key,
					'hide_empty' => false,
					'number'     => 200,
				)
			);
			$out[] = array(
				'key'   => $key,
				'label' => $tax->attribute_label,
				'terms' => is_wp_error( $terms ) ? array() : array_map(
					static function ( $t ) {
						return array(
							'slug' => $t->slug,
							'name' => $t->name,
						);
					},
					$terms
				),
			);
		}
		return $this->respond( $out );
	}

	/**
	 * GET /history.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function history( \WP_REST_Request $request ) {
		return $this->respond(
			Log::query(
				array(
					'pool_id'  => (int) $request['pool_id'],
					'reason'   => (string) $request['reason'],
					'from'     => (string) $request['from'],
					'to'       => (string) $request['to'],
					'page'     => (int) $request['page'],
					'per_page' => (int) $request['per_page'],
				)
			)
		);
	}

	/**
	 * GET /export.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function export( \WP_REST_Request $request ) {
		$type = (string) $request['type'];
		if ( 'links' === $type ) {
			$csv = Csv::export_links();
		} elseif ( 'history' === $type ) {
			$csv = Csv::export_log( array( 'pool_id' => (int) $request['pool_id'] ) );
		} else {
			$csv = Csv::export_pools();
		}
		$res = $this->respond(
			array(
				'filename' => sprintf( 'shared-stock-%s-%s.csv', $type, gmdate( 'Y-m-d' ) ),
				'csv'      => $csv,
			)
		);
		$res->header( 'Cache-Control', 'no-store, private' );
		return $res;
	}

	/**
	 * POST /import.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function import( \WP_REST_Request $request ) {
		$csv = (string) $request['csv'];
		if ( strlen( $csv ) > 5 * MB_IN_BYTES ) {
			return $this->respond( new \WP_Error( 'dev3ss_csv_big', __( 'The CSV is larger than 5 MB. Split it into smaller files.', 'dev3-ai-shared-stock-for-woocommerce' ), array( 'status' => 400 ) ) );
		}
		$dry = (bool) $request['dry_run'];
		$res = 'links' === $request['type'] ? Csv::import_links( $csv, $dry ) : Csv::import_pools( $csv, $dry );
		return $this->respond( $res );
	}

	/**
	 * GET /forecasts.
	 *
	 * @return \WP_REST_Response
	 */
	public function forecasts() {
		$today = gmdate( 'Y-m-d' );
		$out   = array();
		foreach ( Pools::all( array( 'status' => 'active' ) ) as $pool ) {
			$f = $pool['forecast'];
			if ( ! $f || ( $f['today'] ?? '' ) !== $today ) {
				$f = Forecast::refresh( $pool['id'] );
			}
			$out[] = array(
				'id'       => $pool['id'],
				'name'     => $pool['name'],
				'unit'     => $pool['unit'],
				'stock'    => $pool['stock'],
				'supplier' => $pool['supplier_name'],
				'forecast' => $f,
			);
		}
		return $this->respond(
			array(
				'pools' => $out,
				'jobs'  => Jobs::recent( Forecast::JOB, 1 ),
			)
		);
	}

	/**
	 * POST /forecasts/run (background job).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function run_forecasts( \WP_REST_Request $request ) {
		$active = Jobs::active( Forecast::JOB );
		if ( $active ) {
			return $this->respond( $active );
		}
		$ids = wp_list_pluck( Pools::all( array( 'status' => 'active' ) ), 'id' );
		if ( ! $ids ) {
			return $this->respond( new \WP_Error( 'dev3ss_no_pools', __( 'Create a pool first.', 'dev3-ai-shared-stock-for-woocommerce' ), array( 'status' => 400 ) ) );
		}
		$id = Jobs::create( Forecast::JOB, array_map( 'intval', $ids ), array( 'explain' => (bool) $request['explain'] ), 10 );
		return $this->respond( is_wp_error( $id ) ? $id : Jobs::get( $id ), 201 );
	}

	/**
	 * GET /purchase-orders.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_pos( \WP_REST_Request $request ) {
		return $this->respond( Purchase_Orders::all( array( 'status' => (string) $request['status'] ) ) );
	}

	/**
	 * POST /purchase-orders (saves an accepted draft).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create_po( \WP_REST_Request $request ) {
		$data = $this->body( $request );
		$id   = Purchase_Orders::create( (int) ( $data['pool_id'] ?? 0 ), $data );
		return $this->respond( is_wp_error( $id ) ? $id : Purchase_Orders::get( $id ), 201 );
	}

	/**
	 * PUT /purchase-orders/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function update_po( \WP_REST_Request $request ) {
		return $this->respond( Purchase_Orders::update( (int) $request['id'], $this->body( $request ) ) );
	}

	/**
	 * POST /purchase-orders/{id}/send.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function send_po( \WP_REST_Request $request ) {
		return $this->respond( Purchase_Orders::send( (int) $request['id'] ) );
	}

	/**
	 * POST /purchase-orders/{id}/receive.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function receive_po( \WP_REST_Request $request ) {
		$qty = null === $request['qty'] ? null : (float) $request['qty'];
		$res = Purchase_Orders::receive( (int) $request['id'], $qty );
		if ( ! is_wp_error( $res ) ) {
			Forecast::refresh( $res['pool_id'] );
		}
		return $this->respond( $res );
	}

	/**
	 * POST /purchase-orders/{id}/cancel.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function cancel_po( \WP_REST_Request $request ) {
		return $this->respond( Purchase_Orders::cancel( (int) $request['id'] ) );
	}

	/**
	 * GET /alerts.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_alerts( \WP_REST_Request $request ) {
		$status = in_array( $request['status'], array( 'open', 'dismissed', 'all' ), true ) ? $request['status'] : 'open';
		return $this->respond( Alerts::query( $status, 200 ) );
	}

	/**
	 * POST /alerts/scan.
	 *
	 * @return \WP_REST_Response
	 */
	public function scan_alerts() {
		$n = Anomalies::scan();
		Forecast::refresh_all();
		return $this->respond(
			array(
				'raised' => $n,
				'alerts' => Alerts::query( 'open', 200 ),
			)
		);
	}

	/**
	 * POST /alerts/{id}/dismiss.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function dismiss_alert( \WP_REST_Request $request ) {
		Alerts::set_status( (int) $request['id'], 'dismissed' );
		return $this->respond( Alerts::get( (int) $request['id'] ) );
	}

	/**
	 * POST /alerts/{id}/reopen.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function reopen_alert( \WP_REST_Request $request ) {
		Alerts::set_status( (int) $request['id'], 'open' );
		return $this->respond( Alerts::get( (int) $request['id'] ) );
	}

	/**
	 * POST /alerts/{id}/explain (DEV3 AI).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function explain_alert( \WP_REST_Request $request ) {
		return $this->respond( Ai_Features::explain_anomaly( (int) $request['id'] ) );
	}

	/**
	 * POST /alerts/{id}/fix: the admin accepted a corrected stock level.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function fix_alert( \WP_REST_Request $request ) {
		$alert = Alerts::get( (int) $request['id'] );
		if ( ! $alert ) {
			return $this->respond( new \WP_Error( 'dev3ss_alert_missing', __( 'That alert no longer exists.', 'dev3-ai-shared-stock-for-woocommerce' ), array( 'status' => 404 ) ) );
		}
		$stock = round( (float) $request['stock'], 4 );
		if ( $stock < 0 ) {
			return $this->respond( new \WP_Error( 'dev3ss_fix_stock', __( 'Stock cannot be negative here.', 'dev3-ai-shared-stock-for-woocommerce' ), array( 'status' => 400 ) ) );
		}
		$after = Pools::set_stock(
			$alert['pool_id'],
			$stock,
			'manual',
			array(
				/* translators: %d: alert id */
				'note'         => sprintf( __( 'Correction from alert #%d', 'dev3-ai-shared-stock-for-woocommerce' ), $alert['id'] ),
				'skip_anomaly' => true,
			)
		);
		if ( is_wp_error( $after ) ) {
			return $this->respond( $after );
		}
		Alerts::update_detail( $alert['id'], array( 'fixed_to' => $stock ) );
		Alerts::set_status( $alert['id'], 'dismissed' );
		return $this->respond( Alerts::get( $alert['id'] ) );
	}

	/**
	 * POST /setup/suggest.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function setup_suggest( \WP_REST_Request $request ) {
		return $this->respond( Ai_Features::setup_suggest( (bool) $request['ai'] ) );
	}

	/**
	 * POST /setup/apply: creates the proposals the admin accepted (validated again server-side).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function setup_apply( \WP_REST_Request $request ) {
		$proposals = Setup_Advisor::validate( (array) $request['proposals'], Setup_Advisor::snapshot( 1000 ) );
		if ( ! $proposals ) {
			return $this->respond( new \WP_Error( 'dev3ss_setup_empty', __( 'Pick at least one valid pool to create.', 'dev3-ai-shared-stock-for-woocommerce' ), array( 'status' => 400 ) ) );
		}
		$res          = Setup_Advisor::apply( $proposals );
		$res['pools'] = Pools::all();
		return $this->respond( $res, 201 );
	}

	/**
	 * POST /ask.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function ask( \WP_REST_Request $request ) {
		$q = mb_substr( trim( sanitize_textarea_field( (string) $request['question'] ) ), 0, 500 );
		if ( '' === $q ) {
			return $this->respond( new \WP_Error( 'dev3ss_ask_empty', __( 'Type a question first.', 'dev3-ai-shared-stock-for-woocommerce' ), array( 'status' => 400 ) ) );
		}
		return $this->respond( Ai_Features::ask( $q, (array) $request['history'], (bool) $request['ai'] ) );
	}
}
