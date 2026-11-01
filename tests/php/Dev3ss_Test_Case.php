<?php
/**
 * Shared fixtures: products, variations, pools, orders, refunds; Gemini mocked via pre_http_request.
 *
 * @package Dev3\AiSharedStock
 */

use Dev3\AiSharedStock\Core3\Api\Ai;
use Dev3\AiSharedStock\Core3\Core\Cache;
use Dev3\AiSharedStock\Core3\Core\Settings_Store;
use Dev3\AiSharedStock\Data\Pools;
use Dev3\AiSharedStock\Data\Tables;

abstract class Dev3ss_Test_Case extends WP_UnitTestCase {

	/**
	 * Captured Gemini requests.
	 *
	 * @var array
	 */
	protected $requests = array();

	/**
	 * Fake Gemini JSON payloads (decoded `data` objects), consumed in order; the last repeats.
	 *
	 * @var array
	 */
	protected $ai_payloads = array();

	public function set_up() {
		parent::set_up();
		global $wpdb;
		foreach ( Tables::all() as $table ) {
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore
		}
		update_option( Settings_Store::OPTION, array() );
		update_option( 'woocommerce_manage_stock', 'yes' );
		Settings_Store::flush();
		Pools::flush();
		if ( method_exists( Cache::class, 'reset_memo' ) ) {
			Cache::reset_memo();
		}
		Ai::$client        = null;
		$this->requests    = array();
		$this->ai_payloads = array();
		add_filter( 'dev3ss_retry_delay_ms', '__return_zero' );
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		// The test site runs on "localhost", whose default From address PHPMailer rejects.
		add_filter( 'wp_mail_from', static fn() => 'shop@example.org' );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		parent::tear_down();
	}

	/**
	 * Sets a fake API key (never a real one).
	 */
	protected function with_key() {
		Settings_Store::update( array( 'api_key' => 'AIza-test-key-000000000000' ) );
		Settings_Store::flush();
	}

	/**
	 * Fake HTTP for Gemini.
	 *
	 * @param mixed  $pre  Pre.
	 * @param array  $args Args.
	 * @param string $url  URL.
	 * @return mixed
	 */
	public function fake_http( $pre, $args, $url ) {
		if ( false !== $pre || false === strpos( (string) $url, 'generativelanguage' ) ) {
			return $pre;
		}
		$this->requests[] = array(
			'url'  => $url,
			'body' => json_decode( (string) $args['body'], true ),
		);
		$payload = count( $this->ai_payloads ) > 1 ? array_shift( $this->ai_payloads ) : ( $this->ai_payloads ? $this->ai_payloads[0] : array() );
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					'candidates'    => array(
						array(
							'content'      => array( 'parts' => array( array( 'text' => wp_json_encode( $payload ) ) ) ),
							'finishReason' => 'STOP',
						),
					),
					'usageMetadata' => array(
						'promptTokenCount'     => 120,
						'candidatesTokenCount' => 60,
						'totalTokenCount'      => 180,
					),
				)
			),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * Prompt text of the last Gemini request.
	 *
	 * @return string
	 */
	protected function last_prompt() {
		$req = end( $this->requests );
		return $req ? (string) ( $req['body']['contents'][0]['parts'][0]['text'] ?? '' ) : '';
	}

	/**
	 * Simple product.
	 *
	 * @param string $name  Name.
	 * @param string $sku   SKU.
	 * @param float  $price Price.
	 * @param array  $meta  Extra setters (e.g. stock).
	 * @return WC_Product_Simple
	 */
	protected function simple( $name, $sku = '', $price = 10, array $meta = array() ) {
		$p = new WC_Product_Simple();
		$p->set_name( $name );
		$p->set_sku( $sku );
		$p->set_regular_price( $price );
		if ( isset( $meta['stock'] ) ) {
			$p->set_manage_stock( true );
			$p->set_stock_quantity( $meta['stock'] );
		}
		$p->save();
		return wc_get_product( $p->get_id() );
	}

	/**
	 * Ensures a global attribute taxonomy with terms exists.
	 *
	 * @param string   $slug  Attribute slug (without pa_).
	 * @param string[] $terms Term names.
	 * @return string Taxonomy name.
	 */
	protected function attribute( $slug, array $terms ) {
		$tax = 'pa_' . $slug;
		if ( ! wc_attribute_taxonomy_id_by_name( $tax ) ) {
			wc_create_attribute(
				array(
					'name' => ucfirst( $slug ),
					'slug' => $slug,
				)
			);
		}
		if ( ! taxonomy_exists( $tax ) ) {
			register_taxonomy( $tax, array( 'product', 'product_variation' ), array( 'hierarchical' => false ) );
		}
		foreach ( $terms as $term ) {
			if ( ! term_exists( sanitize_title( $term ), $tax ) ) {
				wp_insert_term( $term, $tax, array( 'slug' => sanitize_title( $term ) ) );
			}
		}
		return $tax;
	}

	/**
	 * Variable product with one variation per value of a global attribute.
	 *
	 * @param string   $name   Name.
	 * @param string   $attr   Attribute slug.
	 * @param string[] $values Values.
	 * @param float    $price  Price.
	 * @return array { parent: WC_Product_Variable, variations: slug => WC_Product_Variation }
	 */
	protected function variable( $name, $attr, array $values, $price = 20 ) {
		$tax     = $this->attribute( $attr, $values );
		$product = new WC_Product_Variable();
		$product->set_name( $name );
		$a = new WC_Product_Attribute();
		$a->set_id( wc_attribute_taxonomy_id_by_name( $tax ) );
		$a->set_name( $tax );
		$a->set_options(
			array_map(
				static function ( $v ) use ( $tax ) {
					return (int) get_term_by( 'slug', sanitize_title( $v ), $tax )->term_id;
				},
				$values
			)
		);
		$a->set_visible( true );
		$a->set_variation( true );
		$product->set_attributes( array( $a ) );
		$product->save();
		wp_set_object_terms( $product->get_id(), array_map( 'sanitize_title', $values ), $tax );
		$variations = array();
		foreach ( $values as $v ) {
			$var = new WC_Product_Variation();
			$var->set_parent_id( $product->get_id() );
			$var->set_attributes( array( $tax => sanitize_title( $v ) ) );
			$var->set_regular_price( $price );
			$var->save();
			$variations[ sanitize_title( $v ) ] = wc_get_product( $var->get_id() );
		}
		return array(
			'parent'     => wc_get_product( $product->get_id() ),
			'variations' => $variations,
		);
	}

	/**
	 * Pool.
	 *
	 * @param string $name  Name.
	 * @param float  $stock Stock.
	 * @param array  $extra Fields.
	 * @return int
	 */
	protected function pool( $name, $stock, array $extra = array() ) {
		$id = Pools::create( array_merge( array( 'name' => $name, 'stock' => $stock, 'unit' => 'units', 'low_threshold' => 0 ), $extra ) );
		$this->assertIsInt( $id );
		return $id;
	}

	/**
	 * Links a product to a pool.
	 *
	 * @param int   $pool_id    Pool.
	 * @param int   $product_id Product.
	 * @param float $qty        Units per sale.
	 * @return int
	 */
	protected function link( $pool_id, $product_id, $qty = 1 ) {
		$id = Pools::add_link(
			$pool_id,
			array(
				'product_id' => $product_id,
				'qty'        => $qty,
			)
		);
		$this->assertIsInt( $id );
		return $id;
	}

	/**
	 * Current pool stock.
	 *
	 * @param int $pool_id Pool.
	 * @return float
	 */
	protected function stock( $pool_id ) {
		return (float) Pools::get( $pool_id, true )['stock'];
	}

	/**
	 * Creates an order.
	 *
	 * @param array  $lines  [ [ product, qty ], … ].
	 * @param string $status Final status.
	 * @return WC_Order
	 */
	protected function order( array $lines, $status = 'processing' ) {
		$order = wc_create_order();
		foreach ( $lines as $line ) {
			$order->add_product( $line[0], $line[1] );
		}
		$order->calculate_totals();
		$order->save();
		if ( 'pending' !== $status ) {
			$order->update_status( $status );
		}
		return wc_get_order( $order->get_id() );
	}

	/**
	 * First line item id for a product.
	 *
	 * @param WC_Order   $order   Order.
	 * @param WC_Product $product Product.
	 * @return int
	 */
	protected function item_id( $order, $product ) {
		foreach ( $order->get_items() as $id => $item ) {
			if ( (int) $item->get_variation_id() === $product->get_id() || (int) $item->get_product_id() === $product->get_id() ) {
				return (int) $id;
			}
		}
		return 0;
	}

	/**
	 * Refunds items.
	 *
	 * @param WC_Order $order   Order.
	 * @param array    $qtys    item_id => qty.
	 * @param bool     $restock Restock.
	 * @return WC_Order_Refund|WP_Error
	 */
	protected function refund( $order, array $qtys, $restock = true ) {
		$lines  = array();
		$amount = 0;
		foreach ( $qtys as $item_id => $qty ) {
			$item              = $order->get_item( $item_id );
			$per               = $item->get_quantity() ? (float) $item->get_total() / $item->get_quantity() : 0;
			$total             = round( $per * min( $qty, $item->get_quantity() ), 2 );
			$amount           += $total;
			$lines[ $item_id ] = array(
				'qty'          => $qty,
				'refund_total' => $total,
			);
		}
		return wc_create_refund(
			array(
				'order_id'      => $order->get_id(),
				'amount'        => $amount,
				'line_items'    => $lines,
				'restock_items' => $restock,
			)
		);
	}

	/**
	 * Logged-in user with a role.
	 *
	 * @param string $role Role.
	 * @return int
	 */
	protected function login( $role = 'administrator' ) {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $id );
		return $id;
	}

	/**
	 * REST request with nonce.
	 *
	 * @param string $method Method.
	 * @param string $route  Route under dev3ss/v1.
	 * @param array  $body   JSON body / query.
	 * @param bool   $nonce  Send a nonce.
	 * @return WP_REST_Response
	 */
	protected function rest( $method, $route, array $body = array(), $nonce = true ) {
		$parts = explode( '?', $route, 2 );
		$req   = new WP_REST_Request( $method, '/dev3ss/v1/' . ltrim( $parts[0], '/' ) );
		if ( isset( $parts[1] ) ) {
			parse_str( $parts[1], $query );
			$req->set_query_params( $query );
		}
		if ( $nonce ) {
			$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}
		if ( $body ) {
			if ( 'GET' === $method ) {
				$req->set_query_params( $body );
			} else {
				$req->set_header( 'Content-Type', 'application/json' );
				$req->set_body( wp_json_encode( $body ) );
			}
		}
		return rest_do_request( $req );
	}
}
