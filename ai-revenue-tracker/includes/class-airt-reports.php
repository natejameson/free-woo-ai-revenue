<?php
/**
 * Dashboard numbers.
 *
 * @package AIRevenueTracker
 */

defined( 'ABSPATH' ) || exit;

final class AIRT_Reports {

	const MAX_ORDERS = 5000;

	/**
	 * @return bool
	 */
	private static function hpos() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * @return string[] e.g. wc-processing
	 */
	private static function db_statuses() {
		return array_map(
			function ( $s ) {
				return 'wc-' . $s;
			},
			AIRT_Orders::paid_statuses()
		);
	}

	/**
	 * IDs of paid AI-attributed orders in a GMT range.
	 *
	 * @param int $from Timestamp.
	 * @param int $to   Timestamp.
	 * @return int[]
	 */
	public static function ai_order_ids( $from, $to ) {
		global $wpdb;
		$statuses = self::db_statuses();
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$args     = array_merge( $statuses, array( gmdate( 'Y-m-d H:i:s', $from ), gmdate( 'Y-m-d H:i:s', $to ), self::MAX_ORDERS ) );

		if ( self::hpos() ) {
			$orders = $wpdb->prefix . 'wc_orders';
			$meta   = $wpdb->prefix . 'wc_orders_meta';
			$sql    = "SELECT o.id FROM {$orders} o INNER JOIN {$meta} m ON m.order_id = o.id AND m.meta_key = '_airt_channel'
				WHERE o.type = 'shop_order' AND o.status IN ({$in}) AND o.date_created_gmt >= %s AND o.date_created_gmt <= %s
				ORDER BY o.date_created_gmt DESC LIMIT %d";
		} else {
			$sql = "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_airt_channel'
				WHERE p.post_type = 'shop_order' AND p.post_status IN ({$in}) AND p.post_date_gmt >= %s AND p.post_date_gmt <= %s
				ORDER BY p.post_date_gmt DESC LIMIT %d";
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) );
	}

	/**
	 * All paid store revenue and orders in a GMT range.
	 *
	 * @param int $from Timestamp.
	 * @param int $to   Timestamp.
	 * @return array { revenue, orders }
	 */
	private static function store_totals( $from, $to ) {
		global $wpdb;
		$statuses = self::db_statuses();
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$args     = array_merge( $statuses, array( gmdate( 'Y-m-d H:i:s', $from ), gmdate( 'Y-m-d H:i:s', $to ) ) );

		if ( self::hpos() ) {
			$orders = $wpdb->prefix . 'wc_orders';
			$sql    = "SELECT COUNT(*) AS orders, COALESCE(SUM(total_amount),0) AS revenue FROM {$orders}
				WHERE type = 'shop_order' AND status IN ({$in}) AND date_created_gmt >= %s AND date_created_gmt <= %s";
		} else {
			$sql = "SELECT COUNT(*) AS orders, COALESCE(SUM(pm.meta_value),0) AS revenue FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_order_total'
				WHERE p.post_type = 'shop_order' AND p.post_status IN ({$in}) AND p.post_date_gmt >= %s AND p.post_date_gmt <= %s";
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $args ), ARRAY_A );
		return array(
			'revenue' => $row ? (float) $row['revenue'] : 0.0,
			'orders'  => $row ? (int) $row['orders'] : 0,
		);
	}

	/**
	 * Date ranges the dashboard offers. Pro adds longer ones.
	 *
	 * @return int[]
	 */
	public static function ranges() {
		$ranges = array_values( array_unique( array_filter( array_map( 'absint', (array) apply_filters( 'airt_dashboard_ranges', array( 7, 30 ) ) ) ) ) );
		sort( $ranges );
		return $ranges ? $ranges : array( 30 );
	}

	/**
	 * @param int $days Days; must be one of ranges().
	 * @return array
	 */
	public static function summary( $days ) {
		$allowed  = self::ranges();
		$days     = in_array( (int) $days, $allowed, true ) ? (int) $days : ( in_array( 30, $allowed, true ) ? 30 : $allowed[0] );
		$to       = time();
		$from     = $to - $days * DAY_IN_SECONDS;
		$from_day = wp_date( 'Y-m-d', $from );
		$to_day   = wp_date( 'Y-m-d', $to );

		$data = array(
			'days'       => $days,
			'confirmed'  => array( 'revenue' => 0.0, 'orders' => 0 ),
			'likely'     => array( 'revenue' => 0.0, 'orders' => 0 ),
			'by_source'  => array(),
			'by_channel' => array(),
			'daily'      => array(),
			'products'   => array(),
			'recent'     => array(),
			'visits'     => 0,
			'bots'       => array(),
		);

		for ( $t = $from; $t <= $to + 1; $t += DAY_IN_SECONDS ) {
			$data['daily'][ wp_date( 'Y-m-d', $t ) ] = 0.0;
		}

		foreach ( self::ai_order_ids( $from, $to ) as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order ) {
				continue;
			}
			$source  = (string) $order->get_meta( '_airt_source' );
			$channel = (string) $order->get_meta( '_airt_channel' );
			$total   = (float) $order->get_total(); // Gross, to match the store total it is compared with.

			if ( ! isset( $data['by_channel'][ $channel ] ) ) {
				$data['by_channel'][ $channel ] = array( 'revenue' => 0.0, 'orders' => 0 );
			}
			$data['by_channel'][ $channel ]['revenue'] += $total;
			++$data['by_channel'][ $channel ]['orders'];

			if ( AIRT_Classifier::CHANNEL_LIKELY === $channel ) {
				$data['likely']['revenue'] += $total;
				++$data['likely']['orders'];
				continue; // Estimates never enter confirmed totals.
			}

			$data['confirmed']['revenue'] += $total;
			++$data['confirmed']['orders'];

			if ( ! isset( $data['by_source'][ $source ] ) ) {
				$data['by_source'][ $source ] = array( 'revenue' => 0.0, 'orders' => 0, 'visits' => 0 );
			}
			$data['by_source'][ $source ]['revenue'] += $total;
			++$data['by_source'][ $source ]['orders'];

			$created = $order->get_date_created();
			if ( $created ) {
				$day = $created->date( 'Y-m-d' );
				if ( isset( $data['daily'][ $day ] ) ) {
					$data['daily'][ $day ] += $total;
				}
			}

			foreach ( $order->get_items() as $item ) {
				$pid = $item->get_product_id();
				if ( ! isset( $data['products'][ $pid ] ) ) {
					$data['products'][ $pid ] = array( 'name' => $item->get_name(), 'qty' => 0, 'revenue' => 0.0 );
				}
				$data['products'][ $pid ]['qty']     += (int) $item->get_quantity();
				$data['products'][ $pid ]['revenue'] += (float) $item->get_total();
			}

			if ( count( $data['recent'] ) < 10 ) {
				$data['recent'][] = $order;
			}
		}

		uasort(
			$data['products'],
			function ( $a, $b ) {
				return $b['revenue'] <=> $a['revenue'];
			}
		);
		$data['products'] = array_slice( $data['products'], 0, 10, true );

		foreach ( AIRT_DB::by_source( array( 'visit' ), $from_day, $to_day ) as $row ) {
			$src = $row['source'];
			if ( ! isset( $data['by_source'][ $src ] ) ) {
				$data['by_source'][ $src ] = array( 'revenue' => 0.0, 'orders' => 0, 'visits' => 0 );
			}
			$data['by_source'][ $src ]['visits'] = (int) $row['hits'];
			$data['visits']                     += (int) $row['hits'];
		}

		uasort(
			$data['by_source'],
			function ( $a, $b ) {
				return array( $b['revenue'], $b['visits'] ) <=> array( $a['revenue'], $a['visits'] );
			}
		);

		foreach ( AIRT_DB::by_source( array( 'bot_v', 'bot_u', 'bot_x' ), $from_day, $to_day ) as $row ) {
			if ( ! isset( $data['bots'][ $row['source'] ] ) ) {
				$data['bots'][ $row['source'] ] = array( 'bot_v' => 0, 'bot_u' => 0, 'bot_x' => 0 );
			}
			$data['bots'][ $row['source'] ][ $row['kind'] ] = (int) $row['hits'];
		}
		$data['bot_products'] = AIRT_DB::top_products( array( 'bot_v', 'bot_u' ), $from_day, $to_day, 10 );

		$cache = get_transient( 'airt_store_totals' );
		$cache = is_array( $cache ) ? $cache : array();
		if ( ! isset( $cache[ $days ] ) ) {
			$cache[ $days ] = self::store_totals( $from, $to );
			set_transient( 'airt_store_totals', $cache, 10 * MINUTE_IN_SECONDS );
		}
		$data['store'] = $cache[ $days ];

		return $data;
	}
}
