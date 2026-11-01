<?php
/**
 * Stock pools + their product / attribute links.
 *
 * @package Dev3\AiSharedStock
 */

namespace Dev3\AiSharedStock\Data;

use Dev3\AiSharedStock\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Pool repository. All stock changes go through adjust() so they are atomic and logged.
 */
class Pools {

	const STATUSES = array( 'active', 'paused' );
	const REASONS  = array( 'order', 'refund', 'cancel', 'edit', 'manual', 'import', 'restock', 'setup' );

	/**
	 * Per-request link cache.
	 *
	 * @var array|null
	 */
	private static $links = null;

	/**
	 * Per-request pool cache.
	 *
	 * @var array
	 */
	private static $pools = array();

	/**
	 * Clears the request caches (after writes and in tests).
	 */
	public static function flush() {
		self::$links = null;
		self::$pools = array();
	}

	/**
	 * Current time in MySQL format (UTC).
	 *
	 * @return string
	 */
	public static function now() {
		return current_time( 'mysql', true );
	}

	/**
	 * Normalises a DB row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function format( array $row ) {
		$forecast = json_decode( (string) $row['forecast'], true );
		return array(
			'id'             => (int) $row['id'],
			'name'           => (string) $row['name'],
			'sku'            => (string) $row['sku'],
			'stock'          => self::num( $row['stock'] ),
			'unit'           => (string) $row['unit'],
			'low_threshold'  => self::num( $row['low_threshold'] ),
			'backorders'     => (bool) $row['backorders'],
			'status'         => (string) $row['status'],
			'supplier_name'  => (string) $row['supplier_name'],
			'supplier_email' => (string) $row['supplier_email'],
			'lead_time_days' => (int) $row['lead_time_days'],
			'unit_cost'      => self::num( $row['unit_cost'] ),
			'notes'          => (string) $row['notes'],
			'forecast'       => is_array( $forecast ) ? $forecast : null,
			'created_at'     => (string) $row['created_at'],
			'updated_at'     => (string) $row['updated_at'],
		);
	}

	/**
	 * Decimal string to int/float (drops trailing zeros).
	 *
	 * @param mixed $value Value.
	 * @return float|int
	 */
	public static function num( $value ) {
		$f = round( (float) $value, 4 );
		return floor( $f ) === $f && abs( $f ) < PHP_INT_MAX ? (int) $f : $f;
	}

	/**
	 * Lists pools.
	 *
	 * @param array $args { search, status }.
	 * @return array[]
	 */
	public static function all( array $args = array() ) {
		global $wpdb;
		$table = Tables::name( 'pools' );
		$where = '1=1';
		$vals  = array();
		if ( ! empty( $args['search'] ) ) {
			$like   = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where .= ' AND (name LIKE %s OR sku LIKE %s)';
			$vals[] = $like;
			$vals[] = $like;
		}
		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$where .= ' AND status = %s';
			$vals[] = $args['status'];
		}
		$sql  = "SELECT * FROM {$table} WHERE {$where} ORDER BY name ASC, id ASC LIMIT 2000";
		$rows = $vals
			? $wpdb->get_results( $wpdb->prepare( $sql, $vals ), ARRAY_A ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		$cnt  = self::link_counts();
		foreach ( (array) $rows as $row ) {
			$pool          = self::format( $row );
			$pool['links'] = isset( $cnt[ $pool['id'] ] ) ? $cnt[ $pool['id'] ] : 0;
			$out[]         = $pool;
		}
		return $out;
	}

	/**
	 * Link counts per pool.
	 *
	 * @return array pool_id => count.
	 */
	public static function link_counts() {
		$out = array();
		foreach ( self::all_links() as $link ) {
			$out[ $link['pool_id'] ] = isset( $out[ $link['pool_id'] ] ) ? $out[ $link['pool_id'] ] + 1 : 1;
		}
		return $out;
	}

	/**
	 * One pool.
	 *
	 * @param int  $id    Pool id.
	 * @param bool $fresh Skip the request cache.
	 * @return array|null
	 */
	public static function get( $id, $fresh = false ) {
		global $wpdb;
		$id = (int) $id;
		if ( $id <= 0 ) {
			return null;
		}
		if ( ! $fresh && isset( self::$pools[ $id ] ) ) {
			return self::$pools[ $id ];
		}
		$table = Tables::name( 'pools' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pool  = $row ? self::format( $row ) : null;
		if ( $pool ) {
			self::$pools[ $id ] = $pool;
		}
		return $pool;
	}

	/**
	 * Finds a pool by SKU.
	 *
	 * @param string $sku SKU.
	 * @return array|null
	 */
	public static function find_by_sku( $sku ) {
		global $wpdb;
		$sku = trim( (string) $sku );
		if ( '' === $sku ) {
			return null;
		}
		$table = Tables::name( 'pools' );
		$id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE sku = %s LIMIT 1", $sku ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $id ? self::get( $id ) : null;
	}

	/**
	 * Validates + sanitises pool fields.
	 *
	 * @param array      $data    Raw fields.
	 * @param array|null $current Existing pool (update) or null (create).
	 * @return array|\WP_Error Clean fields (without stock).
	 */
	public static function clean( array $data, $current = null ) {
		$out = array();
		if ( array_key_exists( 'name', $data ) || ! $current ) {
			$name = mb_substr( sanitize_text_field( (string) ( $data['name'] ?? '' ) ), 0, 190 );
			if ( '' === $name ) {
				return new \WP_Error( 'dev3ss_pool_name', __( 'Give the pool a name.', 'dev3-ai-shared-stock-for-woocommerce' ), array( 'status' => 400 ) );
			}
			$out['name'] = $name;
		}
		if ( array_key_exists( 'sku', $data ) ) {
			$sku = mb_substr( sanitize_text_field( (string) $data['sku'] ), 0, 100 );
			if ( '' !== $sku ) {
				$other = self::find_by_sku( $sku );
				if ( $other && ( ! $current || $other['id'] !== $current['id'] ) ) {
					return new \WP_Error( 'dev3ss_pool_sku', __( 'Another pool already uses this SKU.', 'dev3-ai-shared-stock-for-woocommerce' ), array( 'status' => 400 ) );
				}
			}
			$out['sku'] = $sku;
		}
		if ( array_key_exists( 'unit', $data ) ) {
			$out['unit'] = mb_substr( sanitize_text_field( (string) $data['unit'] ), 0, 30 );
		}
		foreach ( array( 'low_threshold', 'unit_cost' ) as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$out[ $key ] = max( 0, round( (float) $data[ $key ], 4 ) );
			}
		}
		if ( array_key_exists( 'lead_time_days', $data ) ) {
			$out['lead_time_days'] = min( 365, max( 0, (int) $data['lead_time_days'] ) );
		}
		if ( array_key_exists( 'backorders', $data ) ) {
			$out['backorders'] = empty( $data['backorders'] ) ? 0 : 1;
		}
		if ( array_key_exists( 'status', $data ) ) {
			$out['status'] = in_array( $data['status'], self::STATUSES, true ) ? $data['status'] : 'active';
		}
		if ( array_key_exists( 'supplier_name', $data ) ) {
			$out['supplier_name'] = mb_substr( sanitize_text_field( (string) $data['supplier_name'] ), 0, 190 );
		}
		if ( array_key_exists( 'supplier_email', $data ) ) {
			$email = sanitize_email( (string) $data['supplier_email'] );
			if ( '' !== trim( (string) $data['supplier_email'] ) && ! is_email( $email ) ) {
				return new \WP_Error( 'dev3ss_pool_email', __( 'Enter a valid supplier email.', 'dev3-ai-shared-stock-for-woocommerce' ), array( 'status' => 400 ) );
			}
			$out['supplier_email'] = $email;
		}
		if ( array_key_exists( 'notes', $data ) ) {
			$out['notes'] = mb_substr( sanitize_textarea_field( (string) $data['notes'] ), 0, 2000 );
		}
		return $out;
	}

	/**
	 * Creates a pool. The opening stock is logged as a "setup" change.
	 *
	 * @param array $data Fields (stock = opening stock).
	 * @return int|\WP_Error Pool id.
	 */
	public static function create( array $data ) {
		global $wpdb;
		$clean = self::clean( $data );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$now = self::now();
		$row = array_merge(
			array(
				'sku'            => '',
				'unit'           => '',
				'low_threshold'  => (float) Settings::get( 'default_threshold', 5 ),
				'backorders'     => 0,
				'status'         => 'active',
				'supplier_name'  => '',
				'supplier_email' => '',
				'lead_time_days' => (int) Settings::get( 'default_lead_time', 7 ),
				'unit_cost'      => 0,
				'notes'          => '',
			),
			$clean,
			array(
				'stock'      => 0,
				'forecast'   => '',
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		$ok  = $wpdb->insert( Tables::name( 'pools' ), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $ok ) {
			return new \WP_Error( 'dev3ss_db', __( 'Could not save the pool.', 'dev3-ai-shared-stock-for-woocommerce' ), array( 'status' => 500 ) );
		}
		$id = (int) $wpdb->insert_id;
		self::flush();
		$opening = isset( $data['stock'] ) ? round( (float) $data['stock'], 4 ) : 0;
		if ( 0.0 !== $opening ) {
			self::adjust( $id, $opening, 'setup', array( 'note' => __( 'Opening stock', 'dev3-ai-shared-stock-for-woocommerce' ) ) );
		}
		/**
		 * A pool was created.
		 *
		 * @param int $id Pool id.
		 */
		do_action( 'dev3ss_pool_created', $id );
		return $id;
	}

	/**
	 * Updates pool fields (stock changes go through adjust()/set_stock()).
	 *
	 * @param int   $id   Pool id.
	 * @param array $data Fields.
	 * @return array|\WP_Error Updated pool.
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		$current = self::get( $id, true );
		if ( ! $current ) {
			return self::missing();
		}
		$clean = self::clean( $data, $current );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( $clean ) {
			$clean['updated_at'] = self::now();
			$wpdb->update( Tables::name( 'pools' ), $clean, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::flush();
		}
		if ( isset( $clean['backorders'] ) || isset( $clean['status'] ) ) {
			/** This action is documented in includes/Data/Pools.php */
			do_action( 'dev3ss_pool_links_changed', (int) $id );
		}
		return self::get( $id, true );
	}

	/**
	 * Deletes a pool, its links and alerts (the stock log is kept for history).
	 *
	 * @param int $id Pool id.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		$id = (int) $id;
		if ( ! self::get( $id, true ) ) {
			return false;
		}
		$products = \Dev3\AiSharedStock\Stock\Resolver::products_for_pool( $id );
		$wpdb->delete( Tables::name( 'links' ), array( 'pool_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Tables::name( 'alerts' ), array( 'pool_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Tables::name( 'pools' ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::flush();
		/**
		 * A pool was deleted.
		 *
		 * @param int   $id       Pool id.
		 * @param int[] $products Products that were linked.
		 */
		do_action( 'dev3ss_pool_deleted', $id, $products );
		return true;
	}

	/**
	 * Atomically changes a pool's stock and logs it.
	 *
	 * @param int    $id     Pool id.
	 * @param float  $delta  Change (negative = deduct).
	 * @param string $reason One of REASONS.
	 * @param array  $ctx    { order_id, item_id, product_id, note, user_id }.
	 * @return float|\WP_Error Stock after the change.
	 */
	public static function adjust( $id, $delta, $reason, array $ctx = array() ) {
		global $wpdb;
		$id    = (int) $id;
		$delta = round( (float) $delta, 4 );
		if ( ! in_array( $reason, self::REASONS, true ) ) {
			$reason = 'manual';
		}
		if ( 0.0 === $delta ) {
			$pool = self::get( $id, true );
			return $pool ? (float) $pool['stock'] : self::missing();
		}
		$table = Tables::name( 'pools' );
		$done  = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET stock = stock + %f, updated_at = %s WHERE id = %d", $delta, self::now(), $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $done ) {
			return self::missing();
		}
		$after = (float) $wpdb->get_var( $wpdb->prepare( "SELECT stock FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		unset( self::$pools[ $id ] );
		$log_id = Log::add( $id, $delta, $after, $reason, $ctx );
		/**
		 * Pool stock changed.
		 *
		 * @param int    $id     Pool id.
		 * @param float  $delta  Change.
		 * @param float  $after  Stock after.
		 * @param string $reason Reason.
		 * @param array  $ctx    Context (+ log_id).
		 */
		do_action( 'dev3ss_stock_changed', $id, $delta, $after, $reason, $ctx + array( 'log_id' => $log_id ) );
		return $after;
	}

	/**
	 * Sets a pool's stock to an absolute value (logged as the difference).
	 *
	 * @param int    $id     Pool id.
	 * @param float  $value  New stock.
	 * @param string $reason Reason.
	 * @param array  $ctx    Context.
	 * @return float|\WP_Error
	 */
	public static function set_stock( $id, $value, $reason = 'manual', array $ctx = array() ) {
		$pool = self::get( $id, true );
		if ( ! $pool ) {
			return self::missing();
		}
		$delta = round( (float) $value - (float) $pool['stock'], 4 );
		if ( 0.0 === $delta ) {
			return (float) $pool['stock'];
		}
		return self::adjust( $id, $delta, $reason, $ctx + array( 'set' => true ) );
	}

	/**
	 * Stores the latest forecast.
	 *
	 * @param int   $id       Pool id.
	 * @param array $forecast Forecast.
	 */
	public static function save_forecast( $id, array $forecast ) {
		global $wpdb;
		$wpdb->update( Tables::name( 'pools' ), array( 'forecast' => wp_json_encode( $forecast ) ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		unset( self::$pools[ (int) $id ] );
	}

	/**
	 * Not-found error.
	 *
	 * @return \WP_Error
	 */
	public static function missing() {
		return new \WP_Error( 'dev3ss_pool_missing', __( 'That stock pool no longer exists.', 'dev3-ai-shared-stock-for-woocommerce' ), array( 'status' => 404 ) );
	}

	/* ------------------------------------------------------------------ links */

	/**
	 * Every link (request-cached).
	 *
	 * @return array[]
	 */
	public static function all_links() {
		global $wpdb;
		if ( null === self::$links ) {
			$table       = Tables::name( 'links' );
			$rows        = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			self::$links = array_map( array( __CLASS__, 'format_link' ), (array) $rows );
		}
		return self::$links;
	}

	/**
	 * Normalises a link row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function format_link( array $row ) {
		return array(
			'id'         => (int) $row['id'],
			'pool_id'    => (int) $row['pool_id'],
			'product_id' => (int) $row['product_id'],
			'attr_key'   => (string) $row['attr_key'],
			'attr_value' => (string) $row['attr_value'],
			'qty'        => (float) $row['qty'],
		);
	}

	/**
	 * Links of one pool.
	 *
	 * @param int $pool_id Pool id.
	 * @return array[]
	 */
	public static function links( $pool_id ) {
		return array_values(
			array_filter(
				self::all_links(),
				static function ( $l ) use ( $pool_id ) {
					return (int) $pool_id === $l['pool_id'];
				}
			)
		);
	}

	/**
	 * Adds (or updates the quantity of) a link.
	 *
	 * @param int   $pool_id Pool id.
	 * @param array $data    { product_id, attr_key, attr_value, qty }.
	 * @return int|\WP_Error Link id.
	 */
	public static function add_link( $pool_id, array $data ) {
		global $wpdb;
		if ( ! self::get( $pool_id ) ) {
			return self::missing();
		}
		$product_id = max( 0, (int) ( $data['product_id'] ?? 0 ) );
		$attr_key   = sanitize_title( (string) ( $data['attr_key'] ?? '' ) );
		$attr_value = mb_substr( sanitize_title( (string) ( $data['attr_value'] ?? '' ) ), 0, 190 );
		$qty        = round( (float) ( $data['qty'] ?? 1 ), 4 );
		if ( $qty <= 0 ) {
			return new \WP_Error( 'dev3ss_link_qty', __( 'The quantity per sale must be more than 0.', 'dev3-ai-shared-stock-for-woocommerce' ), array( 'status' => 400 ) );
		}
		if ( 0 === $product_id && ( '' === $attr_key || '' === $attr_value ) ) {
			return new \WP_Error( 'dev3ss_link_target', __( 'Pick a product, a variation or an attribute value.', 'dev3-ai-shared-stock-for-woocommerce' ), array( 'status' => 400 ) );
		}
		if ( ( '' === $attr_key ) !== ( '' === $attr_value ) ) {
			return new \WP_Error( 'dev3ss_link_attr', __( 'An attribute rule needs both the attribute and its value.', 'dev3-ai-shared-stock-for-woocommerce' ), array( 'status' => 400 ) );
		}
		if ( $product_id && function_exists( 'wc_get_product' ) && ! wc_get_product( $product_id ) ) {
			return new \WP_Error( 'dev3ss_link_product', __( 'That product does not exist.', 'dev3-ai-shared-stock-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$table    = Tables::name( 'links' );
		$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE pool_id = %d AND product_id = %d AND attr_key = %s AND attr_value = %s", $pool_id, $product_id, $attr_key, $attr_value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $existing ) {
			$wpdb->update( $table, array( 'qty' => $qty ), array( 'id' => $existing ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$id = $existing;
		} else {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'pool_id'    => (int) $pool_id,
					'product_id' => $product_id,
					'attr_key'   => $attr_key,
					'attr_value' => $attr_value,
					'qty'        => $qty,
					'created_at' => self::now(),
				)
			);
			$id = (int) $wpdb->insert_id;
		}
		self::flush();
		/**
		 * Links of a pool changed (products are re-synced).
		 *
		 * @param int $pool_id Pool id.
		 */
		do_action( 'dev3ss_pool_links_changed', (int) $pool_id );
		return $id;
	}

	/**
	 * Removes a link.
	 *
	 * @param int $link_id Link id.
	 * @return bool
	 */
	public static function delete_link( $link_id ) {
		global $wpdb;
		$link = null;
		foreach ( self::all_links() as $l ) {
			if ( (int) $link_id === $l['id'] ) {
				$link = $l;
			}
		}
		if ( ! $link ) {
			return false;
		}
		$products = \Dev3\AiSharedStock\Stock\Resolver::products_for_link( $link );
		$wpdb->delete( Tables::name( 'links' ), array( 'id' => (int) $link_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::flush();
		/**
		 * A link was removed.
		 *
		 * @param array $link     Link.
		 * @param int[] $products Products it covered.
		 */
		do_action( 'dev3ss_link_deleted', $link, $products );
		/** This action is documented in includes/Data/Pools.php */
		do_action( 'dev3ss_pool_links_changed', $link['pool_id'] );
		return true;
	}

	/**
	 * Removes every direct link of a product (product panel save).
	 *
	 * @param int $product_id Product or variation id.
	 * @return int[] Pool ids that lost a link.
	 */
	public static function unlink_product( $product_id ) {
		$pools = array();
		foreach ( self::all_links() as $l ) {
			if ( (int) $product_id === $l['product_id'] && '' === $l['attr_key'] ) {
				self::delete_link( $l['id'] );
				$pools[] = $l['pool_id'];
			}
		}
		return $pools;
	}
}
