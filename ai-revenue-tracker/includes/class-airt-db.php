<?php
/**
 * Daily counters table. Stores aggregated counts only (no IPs, no personal data).
 *
 * kind: visit   = shopper arrived from an AI assistant (source = assistant key)
 *       bot_v   = AI bot visit, verified against published IP ranges (source = bot key)
 *       bot_u   = AI bot visit, owner publishes no ranges so it could not be checked
 *       bot_x   = claimed to be an AI bot but failed IP verification (likely fake)
 *
 * @package AIRevenueTracker
 */

defined( 'ABSPATH' ) || exit;

final class AIRT_DB {

	/**
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'airt_daily';
	}

	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
  day date NOT NULL,
  kind varchar(16) NOT NULL,
  source varchar(40) NOT NULL,
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  hits int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (day,kind,source,product_id),
  KEY kind_day (kind,day)
) {$collate};"
		);
	}

	/**
	 * @param string $kind       Counter kind.
	 * @param string $source     Assistant or bot key.
	 * @param int    $product_id Product, or 0.
	 */
	public static function bump( $kind, $source, $product_id = 0 ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (day, kind, source, product_id, hits) VALUES (%s, %s, %s, %d, 1) ON DUPLICATE KEY UPDATE hits = hits + 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'Y-m-d' ),
				substr( (string) $kind, 0, 16 ),
				substr( (string) $source, 0, 40 ),
				(int) $product_id
			)
		);
	}

	/**
	 * @param int $days Keep this many days.
	 */
	public static function prune( $days ) {
		global $wpdb;
		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d', current_time( 'timestamp' ) - ( (int) $days * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE day < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Totals grouped by source and kind.
	 *
	 * @param string[] $kinds Kinds.
	 * @param string   $from  Y-m-d.
	 * @param string   $to    Y-m-d.
	 * @return array[] rows with source, kind, hits
	 */
	public static function by_source( array $kinds, $from, $to ) {
		global $wpdb;
		$table = self::table();
		$in    = implode( ',', array_fill( 0, count( $kinds ), '%s' ) );
		$args  = array_merge( $kinds, array( $from, $to ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT source, kind, SUM(hits) AS hits FROM {$table} WHERE kind IN ({$in}) AND day BETWEEN %s AND %s GROUP BY source, kind ORDER BY hits DESC", $args ), ARRAY_A );
	}

	/**
	 * Top products by hits.
	 *
	 * @param string[] $kinds Kinds.
	 * @param string   $from  Y-m-d.
	 * @param string   $to    Y-m-d.
	 * @param int      $limit Rows.
	 * @return array[] rows with product_id, hits
	 */
	public static function top_products( array $kinds, $from, $to, $limit = 10 ) {
		global $wpdb;
		$table = self::table();
		$in    = implode( ',', array_fill( 0, count( $kinds ), '%s' ) );
		$args  = array_merge( $kinds, array( $from, $to, (int) $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT product_id, SUM(hits) AS hits FROM {$table} WHERE kind IN ({$in}) AND product_id > 0 AND day BETWEEN %s AND %s GROUP BY product_id ORDER BY hits DESC LIMIT %d", $args ), ARRAY_A );
	}
}
