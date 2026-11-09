<?php
/**
 * Uninstall. Data is only removed when the owner ticked "Delete data on uninstall".
 *
 * @package AIRevenueTracker
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'airt_daily' );
wp_clear_scheduled_hook( 'airt_backfill' );

$airt_settings = get_option( 'airt_settings', array() );
if ( empty( $airt_settings['delete_data'] ) ) {
	return;
}

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}airt_daily" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$airt_keys = array( '_airt_source', '_airt_channel', '_airt_confidence', '_airt_click_source', '_airt_click_landing', '_airt_click_ts', '_airt_agent_source', '_airt_agent_signal', '_airt_survey' );
$airt_in   = implode( ',', array_fill( 0, count( $airt_keys ), '%s' ) );

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ({$airt_in})", $airt_keys ) );

$airt_hpos_meta = $wpdb->prefix . 'wc_orders_meta';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $airt_hpos_meta ) ) === $airt_hpos_meta ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$airt_hpos_meta} WHERE meta_key IN ({$airt_in})", $airt_keys ) );
}
// phpcs:enable

foreach ( array( 'airt_settings', 'airt_db_version', 'airt_ip_ranges', 'airt_ranges_updated', 'airt_backfill_status' ) as $airt_option ) {
	delete_option( $airt_option );
}
delete_transient( 'airt_store_totals' );
