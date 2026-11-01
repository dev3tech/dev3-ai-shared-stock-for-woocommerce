<?php
/**
 * Plugin tables: pools, links, stock log, alerts, purchase orders.
 *
 * @package Dev3\AiSharedStock
 */

namespace Dev3\AiSharedStock\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Table names + dbDelta SQL (hooked into dev3ss_schema_sql).
 */
class Tables {

	/**
	 * Table name with the WP prefix.
	 *
	 * @param string $name pools|links|log|alerts|orders.
	 * @return string
	 */
	public static function name( $name ) {
		global $wpdb;
		$map = array(
			'pools'  => 'dev3ss_pools',
			'links'  => 'dev3ss_pool_links',
			'log'    => 'dev3ss_stock_log',
			'alerts' => 'dev3ss_alerts',
			'orders' => 'dev3ss_purchase_orders',
		);
		return $wpdb->prefix . $map[ $name ];
	}

	/**
	 * All plugin table names.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array_map( array( __CLASS__, 'name' ), array( 'pools', 'links', 'log', 'alerts', 'orders' ) );
	}

	/**
	 * Adds CREATE TABLE statements.
	 *
	 * @param array  $sql     Statements.
	 * @param string $charset Charset collate.
	 * @return array
	 */
	public static function schema( $sql, $charset ) {
		$pools  = self::name( 'pools' );
		$links  = self::name( 'links' );
		$log    = self::name( 'log' );
		$alerts = self::name( 'alerts' );
		$orders = self::name( 'orders' );

		$sql[] = "CREATE TABLE {$pools} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL DEFAULT '',
			sku varchar(100) NOT NULL DEFAULT '',
			stock decimal(18,4) NOT NULL DEFAULT 0,
			unit varchar(30) NOT NULL DEFAULT '',
			low_threshold decimal(18,4) NOT NULL DEFAULT 0,
			backorders tinyint(1) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'active',
			supplier_name varchar(190) NOT NULL DEFAULT '',
			supplier_email varchar(190) NOT NULL DEFAULT '',
			lead_time_days smallint(5) unsigned NOT NULL DEFAULT 7,
			unit_cost decimal(18,4) NOT NULL DEFAULT 0,
			notes text NOT NULL,
			forecast longtext NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY sku (sku),
			KEY status (status)
		) {$charset};";

		$sql[] = "CREATE TABLE {$links} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			pool_id bigint(20) unsigned NOT NULL DEFAULT 0,
			product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			attr_key varchar(100) NOT NULL DEFAULT '',
			attr_value varchar(190) NOT NULL DEFAULT '',
			qty decimal(18,4) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY pool (pool_id),
			KEY product (product_id),
			KEY attr (attr_key,attr_value)
		) {$charset};";

		$sql[] = "CREATE TABLE {$log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			pool_id bigint(20) unsigned NOT NULL DEFAULT 0,
			delta decimal(18,4) NOT NULL DEFAULT 0,
			stock_after decimal(18,4) NOT NULL DEFAULT 0,
			reason varchar(20) NOT NULL DEFAULT '',
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			item_id bigint(20) unsigned NOT NULL DEFAULT 0,
			product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			note varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY pool_created (pool_id,created_at),
			KEY reason (reason),
			KEY order_id (order_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$alerts} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			pool_id bigint(20) unsigned NOT NULL DEFAULT 0,
			type varchar(30) NOT NULL DEFAULT '',
			severity varchar(10) NOT NULL DEFAULT 'info',
			message varchar(500) NOT NULL DEFAULT '',
			detail longtext NOT NULL,
			fingerprint char(40) NOT NULL DEFAULT '',
			status varchar(10) NOT NULL DEFAULT 'open',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status_created (status,created_at),
			KEY fingerprint (fingerprint),
			KEY pool (pool_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$orders} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			pool_id bigint(20) unsigned NOT NULL DEFAULT 0,
			supplier_name varchar(190) NOT NULL DEFAULT '',
			supplier_email varchar(190) NOT NULL DEFAULT '',
			qty decimal(18,4) NOT NULL DEFAULT 0,
			unit_cost decimal(18,4) NOT NULL DEFAULT 0,
			expected_date date NULL,
			subject varchar(255) NOT NULL DEFAULT '',
			body longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'draft',
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			sent_at datetime NULL,
			received_at datetime NULL,
			PRIMARY KEY  (id),
			KEY pool (pool_id),
			KEY status (status)
		) {$charset};";

		return $sql;
	}

	/**
	 * Drops plugin tables (uninstall / tests).
	 */
	public static function drop() {
		global $wpdb;
		foreach ( self::all() as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}
}
