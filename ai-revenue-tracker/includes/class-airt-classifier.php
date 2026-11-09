<?php
/**
 * Decides whether an order came from AI, and how sure we are.
 *
 * Pure logic: it receives the order's signals and returns a result, so it can be unit tested.
 *
 * @package AIRevenueTracker
 */

defined( 'ABSPATH' ) || exit;

final class AIRT_Classifier {

	const CHANNEL_AGENT  = 'agent';  // An AI agent placed the order (API / agent checkout).
	const CHANNEL_CLICK  = 'click';  // Shopper clicked through from an AI assistant.
	const CHANNEL_SURVEY = 'survey'; // Shopper told us after purchase.
	const CHANNEL_LIKELY = 'likely'; // Estimate only; never counted in confirmed totals.

	/**
	 * Signals (all optional):
	 *  agent_source      Assistant key when an AI agent created the order.
	 *  cookie_source     Assistant key from our first-party click cookie.
	 *  wc_referrer       WooCommerce order attribution referrer URL.
	 *  wc_utm_source     WooCommerce order attribution utm_source.
	 *  wc_source_type    WooCommerce order attribution source type (typein, referral, utm, organic...).
	 *  wc_pages          Pages viewed in the session.
	 *  entry_is_product  Session started on a product page.
	 *  is_returning      Customer has ordered before.
	 *  survey            Post-purchase answer key.
	 *  likely_enabled    Whether to produce estimates.
	 *  home_host         Store host.
	 *
	 * @param array $s Signals.
	 * @return array|null { source, channel, confidence } or null when not AI.
	 */
	public static function classify( array $s ) {
		$own = isset( $s['home_host'] ) ? (string) $s['home_host'] : '';

		if ( ! empty( $s['agent_source'] ) ) {
			$src = AIRT_Sources::is_assistant( $s['agent_source'] ) ? $s['agent_source'] : 'other_ai';
			return self::result( $src, self::CHANNEL_AGENT, 'confirmed' );
		}

		if ( ! empty( $s['cookie_source'] ) && AIRT_Sources::is_assistant( $s['cookie_source'] ) ) {
			return self::result( $s['cookie_source'], self::CHANNEL_CLICK, 'confirmed' );
		}

		$src = AIRT_Sources::detect(
			isset( $s['wc_referrer'] ) ? $s['wc_referrer'] : '',
			isset( $s['wc_utm_source'] ) ? $s['wc_utm_source'] : '',
			$own
		);
		if ( $src ) {
			return self::result( $src, self::CHANNEL_CLICK, 'confirmed' );
		}

		if ( ! empty( $s['survey'] ) && AIRT_Sources::is_assistant( $s['survey'] ) ) {
			return self::result( $s['survey'], self::CHANNEL_SURVEY, 'self_reported' );
		}

		// Estimate: a new customer who arrived with no referrer, straight onto a product page,
		// and bought after a short session. This is how AI-recommended shoppers often look
		// when the AI app strips the referrer. Reported separately, never as confirmed.
		$pages = isset( $s['wc_pages'] ) ? (int) $s['wc_pages'] : 0;
		if ( ! empty( $s['likely_enabled'] )
			&& isset( $s['wc_source_type'] ) && 'typein' === $s['wc_source_type']
			&& ! empty( $s['entry_is_product'] )
			&& empty( $s['is_returning'] )
			&& $pages > 0 && $pages <= 3
		) {
			return self::result( 'unknown_ai', self::CHANNEL_LIKELY, 'estimate' );
		}

		return null;
	}

	/**
	 * @param string $source     Assistant key.
	 * @param string $channel    Channel.
	 * @param string $confidence Confidence.
	 * @return array
	 */
	private static function result( $source, $channel, $confidence ) {
		return array(
			'source'     => $source,
			'channel'    => $channel,
			'confidence' => $confidence,
		);
	}

	/**
	 * @return array Channel labels.
	 */
	public static function channel_labels() {
		return array(
			self::CHANNEL_AGENT  => 'AI agent placed the order',
			self::CHANNEL_CLICK  => 'Clicked through from AI',
			self::CHANNEL_SURVEY => 'Customer said AI',
			self::CHANNEL_LIKELY => 'Likely AI (estimate)',
		);
	}
}
