<?php
/**
 * Known AI assistants (referrers and UTM values) and AI crawlers / agents (user agents).
 *
 * This class has no WordPress dependencies apart from optional filters, so it can be unit tested.
 *
 * @package AIRevenueTracker
 */

defined( 'ABSPATH' ) || exit;

final class AIRT_Sources {

	/** @var array|null */
	private static $assistants = null;

	/** @var array|null */
	private static $bots = null;

	/**
	 * AI assistants a shopper can click through from.
	 *
	 * domains: referrer hosts (subdomains match too).
	 * utm:     utm_source values the assistant appends to links (exact, case-insensitive).
	 *
	 * @return array
	 */
	public static function assistants() {
		if ( null === self::$assistants ) {
			$list = array(
				'chatgpt'    => array(
					'label'   => 'ChatGPT',
					'domains' => array( 'chatgpt.com', 'chat.openai.com' ),
					'utm'     => array( 'chatgpt.com', 'chatgpt', 'openai' ),
				),
				'perplexity' => array(
					'label'   => 'Perplexity',
					'domains' => array( 'perplexity.ai' ),
					'utm'     => array( 'perplexity', 'perplexity.ai' ),
				),
				'gemini'     => array(
					'label'   => 'Gemini',
					'domains' => array( 'gemini.google.com', 'bard.google.com' ),
					'utm'     => array( 'gemini', 'gemini.google.com', 'bard' ),
				),
				'claude'     => array(
					'label'   => 'Claude',
					'domains' => array( 'claude.ai' ),
					'utm'     => array( 'claude', 'claude.ai', 'anthropic' ),
				),
				'copilot'    => array(
					'label'   => 'Microsoft Copilot',
					'domains' => array( 'copilot.microsoft.com', 'copilot.cloud.microsoft' ),
					'utm'     => array( 'copilot', 'copilot.com', 'copilot.microsoft.com' ),
				),
				'meta_ai'    => array(
					'label'   => 'Meta AI',
					'domains' => array( 'meta.ai' ),
					'utm'     => array( 'meta.ai', 'metaai' ),
				),
				'grok'       => array(
					'label'   => 'Grok',
					'domains' => array( 'grok.com' ),
					'utm'     => array( 'grok', 'grok.com' ),
				),
				'deepseek'   => array(
					'label'   => 'DeepSeek',
					'domains' => array( 'chat.deepseek.com' ),
					'utm'     => array( 'deepseek' ),
				),
				'mistral'    => array(
					'label'   => 'Mistral Le Chat',
					'domains' => array( 'chat.mistral.ai' ),
					'utm'     => array( 'mistral', 'lechat' ),
				),
				'you'        => array(
					'label'   => 'You.com',
					'domains' => array( 'you.com' ),
					'utm'     => array( 'you.com' ),
				),
				'other_ai'   => array(
					'label'   => 'Other AI assistant',
					'domains' => array(),
					'utm'     => array(),
				),
			);

			self::$assistants = function_exists( 'apply_filters' ) ? apply_filters( 'airt_assistants', $list ) : $list;
		}
		return self::$assistants;
	}

	/**
	 * AI crawlers and agents, matched by user-agent substring in this order.
	 *
	 * type: search   = builds an AI search index (can lead to recommendations)
	 *       agent    = fetching a page live because a person asked the AI
	 *       training = collecting data for model training
	 * assistant: which assistant an agent fetch belongs to.
	 * ranges: published IP range files used to verify the bot is genuine.
	 *
	 * @return array
	 */
	public static function bots() {
		if ( null === self::$bots ) {
			$list = array(
				'oai-searchbot'        => array( 'ua' => 'OAI-SearchBot', 'label' => 'OAI-SearchBot (ChatGPT search)', 'type' => 'search', 'assistant' => 'chatgpt', 'ranges' => array( 'https://openai.com/searchbot.json' ) ),
				'chatgpt-user'         => array( 'ua' => 'ChatGPT-User', 'label' => 'ChatGPT-User (live request)', 'type' => 'agent', 'assistant' => 'chatgpt', 'ranges' => array( 'https://openai.com/chatgpt-user.json' ) ),
				'gptbot'               => array( 'ua' => 'GPTBot', 'label' => 'GPTBot (OpenAI training)', 'type' => 'training', 'assistant' => 'chatgpt', 'ranges' => array( 'https://openai.com/gptbot.json' ) ),
				'claude-searchbot'     => array( 'ua' => 'Claude-SearchBot', 'label' => 'Claude-SearchBot', 'type' => 'search', 'assistant' => 'claude', 'ranges' => array() ),
				'claude-user'          => array( 'ua' => 'Claude-User', 'label' => 'Claude-User (live request)', 'type' => 'agent', 'assistant' => 'claude', 'ranges' => array() ),
				'claudebot'            => array( 'ua' => 'ClaudeBot', 'label' => 'ClaudeBot (Anthropic training)', 'type' => 'training', 'assistant' => 'claude', 'ranges' => array() ),
				'perplexity-user'      => array( 'ua' => 'Perplexity-User', 'label' => 'Perplexity-User (live request)', 'type' => 'agent', 'assistant' => 'perplexity', 'ranges' => array( 'https://www.perplexity.com/perplexity-user.json' ) ),
				'perplexitybot'        => array( 'ua' => 'PerplexityBot', 'label' => 'PerplexityBot', 'type' => 'search', 'assistant' => 'perplexity', 'ranges' => array( 'https://www.perplexity.com/perplexitybot.json' ) ),
				'mistralai-user'       => array( 'ua' => 'MistralAI-User', 'label' => 'MistralAI-User (live request)', 'type' => 'agent', 'assistant' => 'mistral', 'ranges' => array() ),
				'duckassistbot'        => array( 'ua' => 'DuckAssistBot', 'label' => 'DuckAssistBot', 'type' => 'search', 'assistant' => 'other_ai', 'ranges' => array() ),
				'meta-externalfetcher' => array( 'ua' => 'meta-externalfetcher', 'label' => 'Meta AI (live request)', 'type' => 'agent', 'assistant' => 'meta_ai', 'ranges' => array() ),
				'meta-externalagent'   => array( 'ua' => 'meta-externalagent', 'label' => 'Meta AI crawler', 'type' => 'training', 'assistant' => 'meta_ai', 'ranges' => array() ),
				'amazonbot'            => array( 'ua' => 'Amazonbot', 'label' => 'Amazonbot (Alexa, Rufus)', 'type' => 'search', 'assistant' => 'other_ai', 'ranges' => array() ),
				'applebot'             => array( 'ua' => 'Applebot', 'label' => 'Applebot (Siri, Apple Intelligence)', 'type' => 'search', 'assistant' => 'other_ai', 'ranges' => array() ),
				'bytespider'           => array( 'ua' => 'Bytespider', 'label' => 'Bytespider (ByteDance)', 'type' => 'training', 'assistant' => 'other_ai', 'ranges' => array() ),
				'ccbot'                => array( 'ua' => 'CCBot', 'label' => 'CCBot (Common Crawl)', 'type' => 'training', 'assistant' => 'other_ai', 'ranges' => array() ),
				'cohere-ai'            => array( 'ua' => 'cohere-ai', 'label' => 'Cohere', 'type' => 'training', 'assistant' => 'other_ai', 'ranges' => array() ),
			);

			self::$bots = function_exists( 'apply_filters' ) ? apply_filters( 'airt_bots', $list ) : $list;
		}
		return self::$bots;
	}

	/**
	 * Clear cached lists (used by tests and after filters change).
	 */
	public static function reset() {
		self::$assistants = null;
		self::$bots       = null;
	}

	/**
	 * Human label for an assistant key.
	 *
	 * @param string $key Assistant key.
	 * @return string
	 */
	public static function label( $key ) {
		$all = self::assistants();
		if ( isset( $all[ $key ] ) ) {
			return $all[ $key ]['label'];
		}
		if ( 'unknown_ai' === $key ) {
			return 'Likely AI (estimate)';
		}
		return (string) $key;
	}

	/**
	 * @param mixed $key Candidate assistant key.
	 * @return bool
	 */
	public static function is_assistant( $key ) {
		if ( ! is_string( $key ) || '' === $key ) {
			return false;
		}
		$all = self::assistants();
		return isset( $all[ $key ] );
	}

	/**
	 * @param string $utm utm_source value.
	 * @return string|null Assistant key.
	 */
	public static function from_utm( $utm ) {
		$utm = strtolower( trim( (string) $utm ) );
		if ( '' === $utm ) {
			return null;
		}
		foreach ( self::assistants() as $key => $a ) {
			if ( in_array( $utm, $a['utm'], true ) ) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * @param string $url      Referrer URL.
	 * @param string $own_host The store's own host, ignored as a referrer.
	 * @return string|null Assistant key.
	 */
	public static function from_referrer( $url, $own_host = '' ) {
		$host = self::host( $url );
		if ( '' === $host ) {
			return null;
		}
		$own = self::normalize_host( $own_host );
		if ( '' !== $own && $host === $own ) {
			return null;
		}
		foreach ( self::assistants() as $key => $a ) {
			foreach ( $a['domains'] as $domain ) {
				if ( self::host_matches( $host, $domain ) ) {
					return $key;
				}
			}
		}
		return null;
	}

	/**
	 * UTM wins over referrer because AI apps often strip the referrer but keep the tag.
	 *
	 * @param string $referrer Referrer URL.
	 * @param string $utm      utm_source value.
	 * @param string $own_host Store host.
	 * @return string|null
	 */
	public static function detect( $referrer, $utm, $own_host = '' ) {
		$source = self::from_utm( $utm );
		return $source ? $source : self::from_referrer( $referrer, $own_host );
	}

	/**
	 * @param string $user_agent User agent string.
	 * @return string|null Bot key.
	 */
	public static function detect_bot( $user_agent ) {
		$ua = strtolower( (string) $user_agent );
		if ( '' === $ua ) {
			return null;
		}
		foreach ( self::bots() as $key => $bot ) {
			if ( false !== strpos( $ua, strtolower( $bot['ua'] ) ) ) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * @param string $url URL or bare host.
	 * @return string Normalized host, or '' when none.
	 */
	public static function host( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( false === strpos( $url, '//' ) ) {
			$url = 'https://' . $url;
		}
		$host = parse_url( $url, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- kept WordPress-free for tests.
		return self::normalize_host( is_string( $host ) ? $host : '' );
	}

	/**
	 * @param string $host Host.
	 * @return string
	 */
	public static function normalize_host( $host ) {
		$host = strtolower( trim( (string) $host ) );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}
		return rtrim( $host, '.' );
	}

	/**
	 * @param string $host   Normalized host.
	 * @param string $domain Domain to match, including subdomains.
	 * @return bool
	 */
	public static function host_matches( $host, $domain ) {
		$domain = strtolower( $domain );
		if ( $host === $domain ) {
			return true;
		}
		$suffix = '.' . $domain;
		return strlen( $host ) > strlen( $suffix ) && substr( $host, -strlen( $suffix ) ) === $suffix;
	}
}
