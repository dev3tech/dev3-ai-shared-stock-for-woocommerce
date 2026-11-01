<?php
/**
 * Stock history log.
 *
 * @package Dev3\AiSharedStock
 */

namespace Dev3\AiSharedStock\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Every pool stock change, plus the daily sales series used by forecasts.
 */
class Log {

	/**
	 * Reasons that count as customer demand (net of releases).
	 */
	const DEMAND = array( 'order', 'refund', 'cancel', 'edit' );

	/**
	 * Adds a log row.
	 *
	 * @param int    $pool_id Pool id.
	 * @param float  $delta   Change.
	 * @param float  $after   Stock after.
	 * @param string $reason  Reason.
	 * @param array  $ctx     Context.
	 * @return int Log id.
	 */
	public static function add( $pool_id, $delta, $after, $reason, array $ctx = array() ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Tables::name( 'log' ),
			array(
				'pool_id'     => (int) $pool_id,
				'delta'       => round( (float) $delta, 4 ),
				'stock_after' => round( (float) $after, 4 ),
				'reason'      => (string) $reason,
				'order_id'    => (int) ( $ctx['order_id'] ?? 0 ),
				'item_id'     => (int) ( $ctx['item_id'] ?? 0 ),
				'product_id'  => (int) ( $ctx['product_id'] ?? 0 ),
				'user_id'     => (int) ( $ctx['user_id'] ?? get_current_user_id() ),
				'note'        => mb_substr( sanitize_text_field( (string) ( $ctx['note'] ?? '' ) ), 0, 255 ),
				'created_at'  => isset( $ctx['created_at'] ) ? (string) $ctx['created_at'] : Pools::now(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Normalises a row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function format( array $row ) {
		return array(
			'id'          => (int) $row['id'],
			'pool_id'     => (int) $row['pool_id'],
			'pool_name'   => isset( $row['pool_name'] ) ? (string) $row['pool_name'] : '',
			'unit'        => isset( $row['unit'] ) ? (string) $row['unit'] : '',
			'delta'       => Pools::num( $row['delta'] ),
			'stock_after' => Pools::num( $row['stock_after'] ),
			'reason'      => (string) $row['reason'],
			'order_id'    => (int) $row['order_id'],
			'item_id'     => (int) $row['item_id'],
			'product_id'  => (int) $row['product_id'],
			'user_id'     => (int) $row['user_id'],
			'note'        => (string) $row['note'],
			'created_at'  => (string) $row['created_at'],
		);
	}

	/**
	 * Queries the log.
	 *
	 * @param array $args { pool_id, reason, order_id, from, to, page, per_page }.
	 * @return array { rows, total }
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$log    = Tables::name( 'log' );
		$pools  = Tables::name( 'pools' );
		$filter = self::where( $args );
		$where  = $filter[0];
		$vals   = $filter[1];
		$per    = min( (int) ( $args['max_per_page'] ?? 500 ), max( 1, (int) ( $args['per_page'] ?? 25 ) ) );
		$page   = max( 1, (int) ( $args['page'] ?? 1 ) );
		$off    = ( $page - 1 ) * $per;
		$total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$log} l WHERE {$where}", $vals ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$vals2  = array_merge( $vals, array( $per, $off ) );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT l.*, p.name AS pool_name, p.unit AS unit FROM {$log} l LEFT JOIN {$pools} p ON p.id = l.pool_id WHERE {$where} ORDER BY l.created_at DESC, l.id DESC LIMIT %d OFFSET %d", $vals2 ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		return array(
			'rows'  => array_map( array( __CLASS__, 'format' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * WHERE clause for query()/export (always has one placeholder so prepare() is valid).
	 *
	 * @param array $args Filters.
	 * @return array [ sql, values ]
	 */
	private static function where( array $args ) {
		$where = 'l.id > %d';
		$vals  = array( 0 );
		if ( ! empty( $args['pool_id'] ) ) {
			$where .= ' AND l.pool_id = %d';
			$vals[] = (int) $args['pool_id'];
		}
		if ( ! empty( $args['reason'] ) && in_array( $args['reason'], Pools::REASONS, true ) ) {
			$where .= ' AND l.reason = %s';
			$vals[] = $args['reason'];
		}
		if ( ! empty( $args['order_id'] ) ) {
			$where .= ' AND l.order_id = %d';
			$vals[] = (int) $args['order_id'];
		}
		if ( ! empty( $args['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['from'] ) ) {
			$where .= ' AND l.created_at >= %s';
			$vals[] = $args['from'] . ' 00:00:00';
		}
		if ( ! empty( $args['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['to'] ) ) {
			$where .= ' AND l.created_at <= %s';
			$vals[] = $args['to'] . ' 23:59:59';
		}
		return array( $where, $vals );
	}

	/**
	 * Daily demand (units used per day, net of cancels/refunds, never below 0) for the last N days.
	 *
	 * @param int    $pool_id Pool id.
	 * @param int    $days    Window in days.
	 * @param string $today   Y-m-d (defaults to today, UTC).
	 * @return array Y-m-d => units, oldest first, one key per day.
	 */
	public static function daily_demand( $pool_id, $days, $today = '' ) {
		global $wpdb;
		$days  = max( 1, (int) $days );
		$today = $today ? $today : gmdate( 'Y-m-d' );
		$end   = strtotime( $today . ' 00:00:00 UTC' );
		$start = gmdate( 'Y-m-d', $end - ( $days - 1 ) * DAY_IN_SECONDS );
		$table = Tables::name( 'log' );
		$in    = implode( ',', array_fill( 0, count( self::DEMAND ), '%s' ) );
		$vals  = array_merge( array( (int) $pool_id, $start . ' 00:00:00', $today . ' 23:59:59' ), self::DEMAND );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(created_at) AS d, SUM(delta) AS s FROM {$table} WHERE pool_id = %d AND created_at BETWEEN %s AND %s AND reason IN ({$in}) GROUP BY DATE(created_at)", $vals ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$map   = array();
		foreach ( (array) $rows as $row ) {
			$map[ $row['d'] ] = max( 0, -1 * (float) $row['s'] );
		}
		$out = array();
		for ( $i = 0; $i < $days; $i++ ) {
			$d         = gmdate( 'Y-m-d', strtotime( $start . ' 00:00:00 UTC' ) + $i * DAY_IN_SECONDS );
			$out[ $d ] = isset( $map[ $d ] ) ? round( $map[ $d ], 4 ) : 0.0;
		}
		return $out;
	}

	/**
	 * Date of the first log row for a pool (how much history exists).
	 *
	 * @param int $pool_id Pool id.
	 * @return string|null Y-m-d.
	 */
	public static function first_date( $pool_id ) {
		global $wpdb;
		$table = Tables::name( 'log' );
		$d     = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(DATE(created_at)) FROM {$table} WHERE pool_id = %d", (int) $pool_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $d ? (string) $d : null;
	}

	/**
	 * Rows of one order (tests / order screen).
	 *
	 * @param int $order_id Order id.
	 * @return array[]
	 */
	public static function for_order( $order_id ) {
		return self::query(
			array(
				'order_id' => (int) $order_id,
				'per_page' => 500,
			)
		)['rows'];
	}

	/**
	 * Rows for CSV export (newest first, capped).
	 *
	 * @param array $args Filters.
	 * @return array[]
	 */
	public static function export_rows( array $args = array() ) {
		return self::query(
			array_merge(
				$args,
				array(
					'per_page'     => 10000,
					'max_per_page' => 10000,
				)
			)
		)['rows'];
	}
}
