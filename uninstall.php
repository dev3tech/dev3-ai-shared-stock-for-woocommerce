<?php
/**
 * Uninstall: removes data only when "delete data on uninstall" is enabled.
 *
 * @package Dev3\AiSharedStock
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$dev3ss_settings = get_option( 'dev3ss_settings', array() );
if ( empty( $dev3ss_settings['delete_on_uninstall'] ) ) {
	return;
}
global $wpdb;
foreach ( array( 'dev3ss_usage', 'dev3ss_core_cache', 'dev3ss_core_jobs', 'dev3ss_core_embeddings', 'dev3ss_pools', 'dev3ss_pool_links', 'dev3ss_stock_log', 'dev3ss_alerts', 'dev3ss_purchase_orders' ) as $dev3ss_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$dev3ss_table}" );
}
foreach ( array( 'dev3ss_settings', 'dev3ss_license', 'dev3ss_db_version' ) as $dev3ss_option ) {
	delete_option( $dev3ss_option );
}
delete_transient( 'dev3ss_models' );
delete_site_transient( 'dev3ss_update_info' );
wp_unschedule_hook( 'dev3ss_run_job' );
wp_unschedule_hook( 'dev3ss_daily' );
