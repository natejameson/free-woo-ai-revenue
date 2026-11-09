<?php
/**
 * Captures shoppers arriving from AI assistants.
 *
 * Two layers so page caching can't hide AI traffic:
 *  1. PHP on uncached requests sets a first-party cookie.
 *  2. A tiny script does the same in the browser on cached pages and counts the visit.
 *
 * @package AIRevenueTracker
 */

defined( 'ABSPATH' ) || exit;

final class AIRT_Tracker {

	const COOKIE = 'airt_src';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'capture' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * @return bool
	 */
	private static function is_frontend_request() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		return false === strpos( $uri, '/wp-json/' ) && false === strpos( $uri, 'rest_route=' );
	}

	public static function capture() {
		if ( ! self::is_frontend_request() || headers_sent() || ! AIRT_Request::tracking_allowed() ) {
			return;
		}

		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$utm      = isset( $_GET['utm_source'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_source'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$source = AIRT_Sources::detect( $referrer, $utm, AIRT_Request::home_host() );
		if ( ! $source ) {
			return;
		}

		$existing = self::read_cookie();
		$ts       = ( $existing && $existing['s'] === $source ) ? $existing['ts'] : time();
		$uri      = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path     = (string) wp_parse_url( $uri, PHP_URL_PATH );

		$value = wp_json_encode(
			array(
				's'  => $source,
				'lp' => substr( $path, 0, 200 ),
				'ts' => (int) $ts,
			)
		);

		setcookie(
			self::COOKIE,
			$value,
			array(
				'expires'  => time() + DAY_IN_SECONDS * (int) AIRT_Settings::get( 'cookie_days' ),
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => false, // The browser script reads it to avoid double-writing.
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ self::COOKIE ] = $value;
	}

	/**
	 * @return array|null { s, lp, ts }
	 */
	public static function read_cookie() {
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
			return null;
		}
		$data = json_decode( wp_unslash( $_COOKIE[ self::COOKIE ] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated field by field below.
		if ( ! is_array( $data ) || empty( $data['s'] ) || ! AIRT_Sources::is_assistant( $data['s'] ) ) {
			return null;
		}
		return array(
			's'  => $data['s'],
			'lp' => isset( $data['lp'] ) ? substr( sanitize_text_field( (string) $data['lp'] ), 0, 200 ) : '',
			'ts' => isset( $data['ts'] ) ? absint( $data['ts'] ) : 0,
		);
	}

	public static function enqueue() {
		if ( is_admin() ) {
			return;
		}

		$assistants = array();
		foreach ( AIRT_Sources::assistants() as $key => $a ) {
			if ( ! empty( $a['domains'] ) || ! empty( $a['utm'] ) ) {
				$assistants[ $key ] = array(
					'd' => array_values( $a['domains'] ),
					'u' => array_values( $a['utm'] ),
				);
			}
		}

		wp_enqueue_script(
			'airt-track',
			AIRT_URL . 'assets/airt-track.js',
			array(),
			AIRT_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		$config = array(
			'a'      => $assistants,
			'rest'   => esc_url_raw( rest_url( 'airt/v1/visit' ) ),
			'days'   => (int) AIRT_Settings::get( 'cookie_days' ),
			'visits' => (bool) AIRT_Settings::get( 'visits' ),
		);
		wp_add_inline_script( 'airt-track', 'window.airtCfg=' . wp_json_encode( $config ) . ';', 'before' );
	}

	public static function routes() {
		register_rest_route(
			'airt/v1',
			'/visit',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'record_visit' ),
				'permission_callback' => '__return_true', // Public analytics ping; input is validated and rate limited.
				'args'                => array(
					'source' => array(
						'type'     => 'string',
						'required' => true,
					),
					'path'   => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function record_visit( $request ) {
		$source = sanitize_key( (string) $request->get_param( 'source' ) );
		if ( ! AIRT_Settings::get( 'visits' ) || ! AIRT_Sources::is_assistant( $source ) || ! AIRT_Request::tracking_allowed() ) {
			return new WP_REST_Response( null, 204 );
		}

		// Light rate limit: 20 pings per visitor per 10 minutes.
		$key   = 'airt_rl_' . md5( AIRT_Request::ip() . wp_salt( 'nonce' ) );
		$count = (int) get_transient( $key );
		if ( $count >= 20 ) {
			return new WP_REST_Response( null, 204 );
		}
		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );

		$product_id = 0;
		$path       = '/' . ltrim( substr( sanitize_text_field( (string) $request->get_param( 'path' ) ), 0, 200 ), '/' );
		if ( '/' !== $path ) {
			$post_id = url_to_postid( home_url( $path ) );
			if ( $post_id && 'product' === get_post_type( $post_id ) ) {
				$product_id = $post_id;
			}
		}

		AIRT_DB::bump( 'visit', $source, $product_id );
		return new WP_REST_Response( null, 204 );
	}
}
