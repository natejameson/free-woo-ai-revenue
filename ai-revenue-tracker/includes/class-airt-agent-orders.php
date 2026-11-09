<?php
/**
 * Recognises orders placed by AI agents (API / agent checkout), which never load a
 * thank-you page and are invisible to browser analytics.
 *
 * Signals, strongest first:
 *  1. The request creating the order came from a known AI agent user agent.
 *  2. The order was created over the API by a user the owner marked as an AI agent integration.
 *  3. The order's "created via" value or meta keys come from an agentic checkout integration.
 *
 * Integrations can tag orders directly with the airt_agent_order_source filter.
 *
 * @package AIRevenueTracker
 */

defined( 'ABSPATH' ) || exit;

final class AIRT_Agent_Orders {

	/**
	 * Called while the order is being created.
	 *
	 * @return array|null { source, signal }
	 */
	public static function from_request() {
		$bot_key = AIRT_Sources::detect_bot( AIRT_Request::user_agent() );
		if ( $bot_key ) {
			$bots = AIRT_Sources::bots();
			if ( 'agent' === $bots[ $bot_key ]['type'] ) {
				return array(
					'source' => $bots[ $bot_key ]['assistant'],
					'signal' => 'user-agent:' . $bot_key,
				);
			}
		}

		$is_api = ( defined( 'REST_REQUEST' ) && REST_REQUEST );
		if ( $is_api && is_user_logged_in() ) {
			$map  = AIRT_Settings::agent_users();
			$user = wp_get_current_user();
			$name = strtolower( $user->user_login );
			if ( isset( $map[ $name ] ) ) {
				return array(
					'source' => $map[ $name ],
					'signal' => 'api-user:' . $name,
				);
			}
		}

		return null;
	}

	/**
	 * Looks at the stored order for agentic checkout fingerprints.
	 *
	 * @param WC_Order $order Order.
	 * @return string|null Assistant key.
	 */
	public static function from_order( $order ) {
		$source = null;

		$via = strtolower( (string) $order->get_created_via() );
		if ( '' !== $via && preg_match( '/(^|[^a-z])(acp|ucp|agentic|ai[-_]?agent|chatgpt|openai|gemini|perplexity)([^a-z]|$)/', $via ) ) {
			$source = self::guess_assistant( $via );
		}

		if ( ! $source ) {
			foreach ( $order->get_meta_data() as $meta ) {
				$key = strtolower( (string) $meta->key );
				if ( preg_match( '/(agentic_commerce|agentic_checkout|_acp_|^acp_|shared_payment_token|_ucp_|^ucp_)/', $key ) ) {
					$source = self::guess_assistant( $key . ' ' . ( is_scalar( $meta->value ) ? strtolower( (string) $meta->value ) : '' ) );
					break;
				}
			}
		}

		$source = apply_filters( 'airt_agent_order_source', $source, $order );
		return AIRT_Sources::is_assistant( $source ) ? $source : ( $source ? 'other_ai' : null );
	}

	/**
	 * @param string $text Text that may name an assistant.
	 * @return string
	 */
	private static function guess_assistant( $text ) {
		$hints = array(
			'chatgpt'    => array( 'chatgpt', 'openai' ),
			'gemini'     => array( 'gemini', 'google' ),
			'perplexity' => array( 'perplexity' ),
			'claude'     => array( 'claude', 'anthropic' ),
			'copilot'    => array( 'copilot', 'microsoft' ),
		);
		foreach ( $hints as $key => $words ) {
			foreach ( $words as $word ) {
				if ( false !== strpos( $text, $word ) ) {
					return $key;
				}
			}
		}
		return 'other_ai';
	}
}
