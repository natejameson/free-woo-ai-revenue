<?php
/**
 * Plugin settings.
 *
 * @package AIRevenueTracker
 */

defined( 'ABSPATH' ) || exit;

final class AIRT_Settings {

	const OPTION = 'airt_settings';

	/**
	 * @return array
	 */
	public static function defaults() {
		return array(
			'survey'         => 1,
			'bot_log'        => 1,
			'visits'         => 1,
			'cookie_days'    => 30,
			'retention_days' => 365,
			'ip_header'      => '',
			'agent_users'    => '',
			'delete_data'    => 0,
		);
	}

	/**
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * @param mixed $input Raw form input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = array();

		foreach ( array( 'survey', 'bot_log', 'visits', 'delete_data' ) as $flag ) {
			$out[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
		}

		$out['cookie_days']    = max( 1, min( 90, absint( isset( $input['cookie_days'] ) ? $input['cookie_days'] : 30 ) ) );
		$out['retention_days'] = max( 30, min( 1825, absint( isset( $input['retention_days'] ) ? $input['retention_days'] : 365 ) ) );

		$header           = isset( $input['ip_header'] ) ? sanitize_key( $input['ip_header'] ) : '';
		$out['ip_header'] = in_array( $header, array( '', 'cf', 'xff', 'xreal' ), true ) ? $header : '';

		$lines = isset( $input['agent_users'] ) ? explode( "\n", (string) $input['agent_users'] ) : array();
		$clean = array();
		foreach ( $lines as $line ) {
			$line = sanitize_text_field( $line );
			if ( '' !== $line ) {
				$clean[] = $line;
			}
		}
		$out['agent_users'] = implode( "\n", $clean );

		return $out;
	}

	/**
	 * Users whose API keys are used by AI agents, mapped to an assistant.
	 * One per line: "username = chatgpt" (assistant optional, defaults to other_ai).
	 *
	 * @return array username => assistant key
	 */
	public static function agent_users() {
		$map = array();
		foreach ( explode( "\n", (string) self::get( 'agent_users' ) ) as $line ) {
			$parts = array_map( 'trim', explode( '=', $line, 2 ) );
			if ( '' === $parts[0] ) {
				continue;
			}
			$src                         = isset( $parts[1] ) ? strtolower( $parts[1] ) : 'other_ai';
			$map[ strtolower( $parts[0] ) ] = AIRT_Sources::is_assistant( $src ) ? $src : 'other_ai';
		}
		return $map;
	}
}
