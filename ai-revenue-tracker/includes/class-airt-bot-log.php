<?php
/**
 * Counts AI crawler and agent visits per product, verified against published IP ranges.
 *
 * @package AIRevenueTracker
 */

defined( 'ABSPATH' ) || exit;

final class AIRT_Bot_Log {

	const RANGES_OPTION = 'airt_ip_ranges';

	public static function init() {
		if ( AIRT_Settings::get( 'bot_log' ) ) {
			add_action( 'template_redirect', array( __CLASS__, 'log' ), 1 );
		}
		add_action( 'airt_daily', array( __CLASS__, 'daily' ) );
	}

	public static function log() {
		$bot = AIRT_Sources::detect_bot( AIRT_Request::user_agent() );
		if ( ! $bot ) {
			return;
		}

		$verified = self::verify( $bot, AIRT_Request::ip() );
		$kind     = 1 === $verified ? 'bot_v' : ( 0 === $verified ? 'bot_x' : 'bot_u' );
		$product  = is_singular( 'product' ) ? (int) get_queried_object_id() : 0;

		AIRT_DB::bump( $kind, $bot, $product );
	}

	/**
	 * @param string $bot Bot key.
	 * @param string $ip  Visitor IP.
	 * @return int 1 verified, 0 failed (likely fake), 2 cannot verify
	 */
	public static function verify( $bot, $ip ) {
		$ranges = get_option( self::RANGES_OPTION, array() );
		if ( '' === $ip || ! is_array( $ranges ) || empty( $ranges[ $bot ] ) ) {
			return 2;
		}
		return AIRT_IP::in_any( $ip, $ranges[ $bot ] ) ? 1 : 0;
	}

	public static function daily() {
		self::refresh_ranges();
		AIRT_DB::prune( (int) AIRT_Settings::get( 'retention_days' ) );
	}

	/**
	 * Download the IP range files that AI companies publish for their bots.
	 */
	public static function refresh_ranges() {
		$stored = get_option( self::RANGES_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		foreach ( AIRT_Sources::bots() as $key => $bot ) {
			if ( empty( $bot['ranges'] ) ) {
				continue;
			}
			$cidrs = array();
			foreach ( $bot['ranges'] as $url ) {
				$response = wp_remote_get( $url, array( 'timeout' => 10 ) );
				if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
					continue;
				}
				$cidrs = array_merge( $cidrs, AIRT_IP::extract_cidrs( json_decode( wp_remote_retrieve_body( $response ), true ) ) );
			}
			// Keep the last good list if a download fails.
			if ( ! empty( $cidrs ) ) {
				$stored[ $key ] = array_values( array_unique( $cidrs ) );
			}
		}

		update_option( self::RANGES_OPTION, $stored, false );
		update_option( 'airt_ranges_updated', time(), false );
	}
}
