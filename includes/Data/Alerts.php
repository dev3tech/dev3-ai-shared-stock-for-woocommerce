<?php
/**
 * Alerts: low stock, out of stock, anomalies, forecast risk.
 *
 * @package Dev3\AiSharedStock
 */

namespace Dev3\AiSharedStock\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Alert repository (de-duplicated by fingerprint while open).
 */
class Alerts {

	const TYPES = array( 'low_stock', 'out_of_stock', 'anomaly', 'forecast' );

	/**
	 * Adds an alert unless an open one with the same fingerprint exists.
	 *
	 * @param int    $pool_id     Pool id.
	 * @param string $type        Type.
	 * @param string $severity    info|warning|danger.
	 * @param string $message     Message.
	 * @param array  $detail      Extra data.
	 * @param string $fingerprint De-dup key (defaults to type + pool).
	 * @return int New alert id, or 0 when de-duplicated / on failure.
	 */
	public static function add( $pool_id, $type, $severity, $message, array $detail = array(), $fingerprint = '' ) {
		global $wpdb;
		$table = Tables::name( 'alerts' );
		// A custom fingerprint marks a one-off event (never raised twice); the default one repeats once resolved.
		$once        = '' !== $fingerprint;
		$fingerprint = sha1( $once ? $fingerprint : $type . ':' . (int) $pool_id );
		$existing    = $once
			? (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE fingerprint = %s LIMIT 1", $fingerprint ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			: (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE fingerprint = %s AND status = 'open' LIMIT 1", $fingerprint ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $existing ) {
			return 0;
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'pool_id'     => (int) $pool_id,
				'type'        => in_array( $type, self::TYPES, true ) ? $type : 'anomaly',
				'severity'    => in_array( $severity, array( 'info', 'warning', 'danger' ), true ) ? $severity : 'info',
				'message'     => mb_substr( (string) $message, 0, 500 ),
				'detail'      => wp_json_encode( $detail ),
				'fingerprint' => $fingerprint,
				'status'      => 'open',
				'created_at'  => Pools::now(),
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( $id ) {
			/**
			 * An alert was raised.
			 *
			 * @param int   $id     Alert id.
			 * @param array $alert  Alert.
			 */
			do_action( 'dev3ss_alert_raised', $id, self::get( $id ) );
		}
		return $id;
	}

	/**
	 * Normalises a row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function format( array $row ) {
		$detail = json_decode( (string) $row['detail'], true );
		return array(
			'id'         => (int) $row['id'],
			'pool_id'    => (int) $row['pool_id'],
			'pool_name'  => isset( $row['pool_name'] ) ? (string) $row['pool_name'] : '',
			'type'       => (string) $row['type'],
			'severity'   => (string) $row['severity'],
			'message'    => (string) $row['message'],
			'detail'     => is_array( $detail ) ? $detail : array(),
			'status'     => (string) $row['status'],
			'created_at' => (string) $row['created_at'],
		);
	}

	/**
	 * One alert.
	 *
	 * @param int $id Alert id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = Tables::name( 'alerts' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::format( $row ) : null;
	}

	/**
	 * Lists alerts.
	 *
	 * @param string $status open|dismissed|all.
	 * @param int    $limit  Max rows.
	 * @param string $type   Optional type filter.
	 * @return array[]
	 */
	public static function query( $status = 'open', $limit = 100, $type = '' ) {
		global $wpdb;
		$table = Tables::name( 'alerts' );
		$pools = Tables::name( 'pools' );
		$where = 'a.id > %d';
		$vals  = array( 0 );
		if ( in_array( $status, array( 'open', 'dismissed' ), true ) ) {
			$where .= ' AND a.status = %s';
			$vals[] = $status;
		}
		if ( in_array( $type, self::TYPES, true ) ) {
			$where .= ' AND a.type = %s';
			$vals[] = $type;
		}
		$vals[] = min( 500, max( 1, (int) $limit ) );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT a.*, p.name AS pool_name FROM {$table} a LEFT JOIN {$pools} p ON p.id = a.pool_id WHERE {$where} ORDER BY a.created_at DESC, a.id DESC LIMIT %d", $vals ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( __CLASS__, 'format' ), (array) $rows );
	}

	/**
	 * Open alert count.
	 *
	 * @return int
	 */
	public static function open_count() {
		global $wpdb;
		$table = Tables::name( 'alerts' );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'open'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Sets an alert's status.
	 *
	 * @param int    $id     Alert id.
	 * @param string $status open|dismissed.
	 * @return bool
	 */
	public static function set_status( $id, $status ) {
		global $wpdb;
		return (bool) $wpdb->update( Tables::name( 'alerts' ), array( 'status' => 'open' === $status ? 'open' : 'dismissed' ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Merges data into an alert's detail.
	 *
	 * @param int   $id    Alert id.
	 * @param array $patch Detail fields.
	 */
	public static function update_detail( $id, array $patch ) {
		global $wpdb;
		$alert = self::get( $id );
		if ( $alert ) {
			$wpdb->update( Tables::name( 'alerts' ), array( 'detail' => wp_json_encode( array_merge( $alert['detail'], $patch ) ) ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Closes open alerts of a type for a pool (stock recovered).
	 *
	 * @param int    $pool_id Pool id.
	 * @param string $type    Type.
	 */
	public static function resolve( $pool_id, $type ) {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Tables::name( 'alerts' ),
			array( 'status' => 'dismissed' ),
			array(
				'pool_id' => (int) $pool_id,
				'type'    => $type,
				'status'  => 'open',
			)
		);
	}
}
