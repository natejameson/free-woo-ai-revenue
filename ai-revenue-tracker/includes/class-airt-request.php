<?php
/**
 * Request helpers.
 *
 * @package AIRevenueTracker
 */

defined( 'ABSPATH' ) || exit;

final class AIRT_Request {

	/**
	 * Visitor IP. Only trusts a proxy header when the store owner picked one in settings.
	 *
	 * @return string
	 */
	public static function ip() {
		$header = AIRT_Settings::get( 'ip_header' );
		$map    = array(
			'cf'    => 'HTTP_CF_CONNECTING_IP',
			'xff'   => 'HTTP_X_FORWARDED_FOR',
			'xreal' => 'HTTP_X_REAL_IP',
		);

		$candidate = '';
		if ( isset( $map[ $header ] ) && ! empty( $_SERVER[ $map[ $header ] ] ) ) {
			$raw       = sanitize_text_field( wp_unslash( $_SERVER[ $map[ $header ] ] ) );
			$parts     = explode( ',', $raw );
			$candidate = trim( $parts[0] );
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$candidate = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		$ip = filter_var( $candidate, FILTER_VALIDATE_IP ) ? $candidate : '';
		return (string) apply_filters( 'airt_client_ip', $ip );
	}

	/**
	 * @return string
	 */
	public static function user_agent() {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 ) : '';
	}

	/**
	 * @return string
	 */
	public static function home_host() {
		return AIRT_Sources::host( home_url() );
	}

	/**
	 * Respects the WP Consent API when a consent plugin provides it.
	 *
	 * @return bool
	 */
	public static function tracking_allowed() {
		$allowed = true;
		if ( function_exists( 'wp_has_consent' ) ) {
			$allowed = (bool) wp_has_consent( 'statistics' );
		}
		return (bool) apply_filters( 'airt_tracking_allowed', $allowed );
	}
}
