<?php
/**
 * Activation, upgrades and background jobs.
 *
 * @package AIRevenueTracker
 */

defined( 'ABSPATH' ) || exit;

final class AIRT_Install {

	const DB_VERSION = '1';

	public static function activate() {
		AIRT_DB::create_table();
		add_option( AIRT_Settings::OPTION, AIRT_Settings::defaults() );
		update_option( 'airt_db_version', self::DB_VERSION );

		if ( ! wp_next_scheduled( 'airt_daily' ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'daily', 'airt_daily' );
		}

		// Scan recent orders so the dashboard shows past AI sales right away.
		self::schedule_backfill( 1 );
		set_transient( 'airt_activated', 1, HOUR_IN_SECONDS );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'airt_daily' );
		wp_clear_scheduled_hook( 'airt_backfill' );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'airt_backfill' );
		}
	}

	public static function maybe_upgrade() {
		if ( get_option( 'airt_db_version' ) !== self::DB_VERSION ) {
			AIRT_DB::create_table();
			update_option( 'airt_db_version', self::DB_VERSION );
		}
	}

	/**
	 * @param int $page Page of orders to process.
	 */
	public static function schedule_backfill( $page ) {
		$page = max( 1, (int) $page );
		if ( 1 === $page ) {
			update_option(
				'airt_backfill_status',
				array(
					'state'     => 'running',
					'processed' => 0,
					'started'   => time(),
				),
				false
			);
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'airt_backfill', array( 'page' => $page ), 'ai-revenue-tracker' );
		} else {
			wp_schedule_single_event( time() + 5, 'airt_backfill', array( $page ) );
		}
	}
}
