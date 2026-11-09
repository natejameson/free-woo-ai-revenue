<?php
/**
 * Admin: dashboard, settings, order list column and order meta box.
 *
 * @package AIRevenueTracker
 */

defined( 'ABSPATH' ) || exit;

final class AIRT_Admin {

	const SLUG = 'airt';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'activation_notice' ) );
		add_action( 'admin_post_airt_rescan', array( __CLASS__, 'rescan' ) );

		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'render_column_hpos' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_column_legacy' ), 10, 2 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );

		add_filter( 'plugin_action_links_' . plugin_basename( AIRT_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * @param string $tab Tab.
	 * @return string
	 */
	private static function url( $tab = 'dashboard' ) {
		return admin_url( 'admin.php?page=' . self::SLUG . ( 'dashboard' === $tab ? '' : '&tab=' . $tab ) );
	}

	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'AI Revenue', 'ai-revenue-tracker' ),
			__( 'AI Revenue', 'ai-revenue-tracker' ),
			'view_woocommerce_reports',
			self::SLUG,
			array( __CLASS__, 'page' )
		);
	}

	public static function register_settings() {
		register_setting(
			'airt_group',
			AIRT_Settings::OPTION,
			array( 'sanitize_callback' => array( 'AIRT_Settings', 'sanitize' ) )
		);
	}

	/**
	 * @param string $hook Admin page hook.
	 */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'airt-admin', AIRT_URL . 'assets/admin.css', array(), AIRT_VERSION );
	}

	/**
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Dashboard', 'ai-revenue-tracker' ) . '</a>' );
		return $links;
	}

	public static function activation_notice() {
		if ( ! get_transient( 'airt_activated' ) || ! current_user_can( 'view_woocommerce_reports' ) ) {
			return;
		}
		delete_transient( 'airt_activated' );
		printf(
			'<div class="notice notice-success is-dismissible"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html__( 'AI Revenue Tracker is live and is scanning your last 180 days of orders for AI sales.', 'ai-revenue-tracker' ),
			esc_url( self::url() ),
			esc_html__( 'See what AI sold for you', 'ai-revenue-tracker' )
		);
	}

	public static function rescan() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'ai-revenue-tracker' ) );
		}
		check_admin_referer( 'airt_rescan' );
		AIRT_Install::schedule_backfill( 1 );
		wp_safe_redirect( add_query_arg( 'rescan', '1', self::url() ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Page                                                                */
	/* ------------------------------------------------------------------ */

	public static function page() {
		if ( ! current_user_can( 'view_woocommerce_reports' ) ) {
			return;
		}
		$tab = isset( $_GET['tab'] ) && 'settings' === $_GET['tab'] ? 'settings' : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap airt">';
		echo '<h1 class="airt-title">' . esc_html__( 'AI Revenue', 'ai-revenue-tracker' ) . '</h1>';
		echo '<nav class="nav-tab-wrapper">';
		printf( '<a class="nav-tab %1$s" href="%2$s">%3$s</a>', 'dashboard' === $tab ? 'nav-tab-active' : '', esc_url( self::url() ), esc_html__( 'Dashboard', 'ai-revenue-tracker' ) );
		if ( current_user_can( 'manage_woocommerce' ) ) {
			printf( '<a class="nav-tab %1$s" href="%2$s">%3$s</a>', 'settings' === $tab ? 'nav-tab-active' : '', esc_url( self::url( 'settings' ) ), esc_html__( 'Settings', 'ai-revenue-tracker' ) );
		}
		echo '</nav>';

		if ( 'settings' === $tab && current_user_can( 'manage_woocommerce' ) ) {
			self::settings_page();
		} else {
			self::dashboard();
		}
		echo '</div>';
	}

	/**
	 * @param float $amount Amount.
	 * @return string Safe HTML.
	 */
	private static function money( $amount ) {
		return wp_kses_post( wc_price( $amount ) );
	}

	/**
	 * @param float $part  Part.
	 * @param float $whole Whole.
	 * @return string
	 */
	private static function pct( $part, $whole ) {
		return $whole > 0 ? number_format_i18n( ( $part / $whole ) * 100, 1 ) . '%' : '–';
	}

	private static function dashboard() {
		$days = isset( $_GET['range'] ) ? absint( $_GET['range'] ) : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$d    = AIRT_Reports::summary( $days );
		$days = $d['days'];

		echo '<div class="airt-range">';
		foreach ( array( 7, 30, 90 ) as $r ) {
			printf(
				'<a class="button %1$s" href="%2$s">%3$s</a>',
				$r === $days ? 'button-primary' : '',
				esc_url( add_query_arg( 'range', $r, self::url() ) ),
				/* translators: %d: number of days */
				esc_html( sprintf( __( 'Last %d days', 'ai-revenue-tracker' ), $r ) )
			);
		}
		echo '</div>';

		$backfill = get_option( 'airt_backfill_status', array() );
		if ( is_array( $backfill ) && isset( $backfill['state'] ) && 'running' === $backfill['state'] ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html(
				sprintf(
					/* translators: %d: orders scanned */
					__( 'Scanning past orders for AI sales… %d checked so far. Refresh in a minute.', 'ai-revenue-tracker' ),
					(int) $backfill['processed']
				)
			) . '</p></div>';
		}

		$c     = $d['confirmed'];
		$store = $d['store'];

		// Hero.
		echo '<section class="airt-hero">';
		echo '<p class="airt-hero-line">' . sprintf(
			/* translators: 1: amount, 2: number of days */
			esc_html__( 'AI sold %1$s for you in the last %2$s days.', 'ai-revenue-tracker' ),
			'<strong>' . self::money( $c['revenue'] ) . '</strong>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in money().
			esc_html( number_format_i18n( $days ) )
		) . '</p>';
		$sub = array(
			/* translators: %d: orders */
			sprintf( _n( '%d order', '%d orders', $c['orders'], 'ai-revenue-tracker' ), $c['orders'] ),
			/* translators: %s: percent */
			sprintf( __( '%s of store revenue', 'ai-revenue-tracker' ), self::pct( $c['revenue'], $store['revenue'] ) ),
		);
		echo '<p class="airt-hero-sub">' . esc_html( implode( ' · ', $sub ) );
		if ( $d['likely']['orders'] > 0 ) {
			echo ' · <span class="airt-estimate">+ ' . self::money( $d['likely']['revenue'] ) . ' ' . esc_html__( 'likely from AI (estimate, not included)', 'ai-revenue-tracker' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</p>';
		if ( 0 === $c['orders'] ) {
			echo '<p class="airt-muted">' . esc_html__( 'No confirmed AI sales in this period yet. Tracking is live from now on, and past orders are checked using WooCommerce order attribution.', 'ai-revenue-tracker' ) . '</p>';
		}
		echo '</section>';

		// KPIs.
		$ai_aov    = $c['orders'] ? $c['revenue'] / $c['orders'] : 0;
		$store_aov = $store['orders'] ? $store['revenue'] / $store['orders'] : 0;
		$conv      = $d['visits'] ? self::pct( $c['orders'] - ( isset( $d['by_channel']['agent'] ) ? $d['by_channel']['agent']['orders'] : 0 ), $d['visits'] ) : '–';

		echo '<div class="airt-kpis">';
		self::kpi( __( 'AI orders', 'ai-revenue-tracker' ), esc_html( number_format_i18n( $c['orders'] ) ), sprintf( /* translators: %s: store orders */ __( 'of %s store orders', 'ai-revenue-tracker' ), number_format_i18n( $store['orders'] ) ) );
		self::kpi( __( 'AI average order', 'ai-revenue-tracker' ), self::money( $ai_aov ), wp_strip_all_tags( sprintf( /* translators: %s: amount */ __( 'store average %s', 'ai-revenue-tracker' ), html_entity_decode( wp_strip_all_tags( wc_price( $store_aov ) ) ) ) ) );
		self::kpi( __( 'Visits from AI', 'ai-revenue-tracker' ), esc_html( number_format_i18n( $d['visits'] ) ), __( 'shoppers who clicked from an AI app', 'ai-revenue-tracker' ) );
		self::kpi( __( 'AI visit conversion', 'ai-revenue-tracker' ), esc_html( $conv ), __( 'click-through orders ÷ AI visits', 'ai-revenue-tracker' ) );
		echo '</div>';

		self::daily_chart( $d['daily'] );

		echo '<div class="airt-grid">';

		// By assistant.
		echo '<section class="airt-card"><h2>' . esc_html__( 'By AI assistant', 'ai-revenue-tracker' ) . '</h2>';
		if ( empty( $d['by_source'] ) ) {
			echo '<p class="airt-muted">' . esc_html__( 'Nothing yet.', 'ai-revenue-tracker' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Assistant', 'ai-revenue-tracker' ) . '</th><th class="num">' . esc_html__( 'Visits', 'ai-revenue-tracker' ) . '</th><th class="num">' . esc_html__( 'Orders', 'ai-revenue-tracker' ) . '</th><th class="num">' . esc_html__( 'Revenue', 'ai-revenue-tracker' ) . '</th></tr></thead><tbody>';
			foreach ( $d['by_source'] as $src => $row ) {
				echo '<tr><td>' . esc_html( AIRT_Sources::label( $src ) ) . '</td><td class="num">' . esc_html( number_format_i18n( $row['visits'] ) ) . '</td><td class="num">' . esc_html( number_format_i18n( $row['orders'] ) ) . '</td><td class="num">' . self::money( $row['revenue'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</tbody></table>';
		}
		echo '</section>';

		// How we know.
		echo '<section class="airt-card"><h2>' . esc_html__( 'How we know', 'ai-revenue-tracker' ) . '</h2>';
		$help = array(
			'agent'  => __( 'An AI agent placed the order through your store API or an agent checkout. Browser analytics never see these.', 'ai-revenue-tracker' ),
			'click'  => __( 'The shopper arrived from an AI app (referrer or tracking tag) before buying.', 'ai-revenue-tracker' ),
			'survey' => __( 'The customer picked an AI assistant in the "How did you find us?" question.', 'ai-revenue-tracker' ),
			'likely' => __( 'New customer, no referrer, landed straight on a product page and bought quickly. Shown separately and never counted above.', 'ai-revenue-tracker' ),
		);
		echo '<table class="widefat striped"><tbody>';
		foreach ( AIRT_Classifier::channel_labels() as $ch => $label ) {
			$row = isset( $d['by_channel'][ $ch ] ) ? $d['by_channel'][ $ch ] : array( 'revenue' => 0, 'orders' => 0 );
			echo '<tr><td><strong>' . esc_html( $label ) . '</strong><br><span class="airt-muted">' . esc_html( $help[ $ch ] ) . '</span></td><td class="num">' . esc_html( number_format_i18n( $row['orders'] ) ) . '</td><td class="num">' . self::money( $row['revenue'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</tbody></table></section>';

		// Top products.
		echo '<section class="airt-card"><h2>' . esc_html__( 'Products AI sold', 'ai-revenue-tracker' ) . '</h2>';
		if ( empty( $d['products'] ) ) {
			echo '<p class="airt-muted">' . esc_html__( 'Nothing yet.', 'ai-revenue-tracker' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Product', 'ai-revenue-tracker' ) . '</th><th class="num">' . esc_html__( 'Qty', 'ai-revenue-tracker' ) . '</th><th class="num">' . esc_html__( 'Revenue', 'ai-revenue-tracker' ) . '</th></tr></thead><tbody>';
			foreach ( $d['products'] as $pid => $p ) {
				$link = $pid ? get_edit_post_link( $pid ) : '';
				$name = $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $p['name'] ) . '</a>' : esc_html( $p['name'] );
				echo '<tr><td>' . $name . '</td><td class="num">' . esc_html( number_format_i18n( $p['qty'] ) ) . '</td><td class="num">' . self::money( $p['revenue'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</tbody></table>';
		}
		echo '</section>';

		// Bots.
		self::bots_card( $d );

		echo '</div>';

		self::recent_orders( $d['recent'] );
		self::pro_box();

		if ( current_user_can( 'manage_woocommerce' ) ) {
			printf(
				'<form method="post" action="%1$s" class="airt-rescan"><input type="hidden" name="action" value="airt_rescan">%2$s<button class="button-link">%3$s</button></form>',
				esc_url( admin_url( 'admin-post.php' ) ),
				wp_nonce_field( 'airt_rescan', '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html__( 'Re-scan the last 180 days of orders', 'ai-revenue-tracker' )
			);
		}
	}

	/**
	 * @param string $label Label.
	 * @param string $value Safe HTML value.
	 * @param string $note  Note.
	 */
	private static function kpi( $label, $value, $note ) {
		echo '<div class="airt-kpi"><div class="airt-kpi-label">' . esc_html( $label ) . '</div><div class="airt-kpi-value">' . $value . '</div><div class="airt-kpi-note">' . esc_html( $note ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * @param array $daily Y-m-d => revenue.
	 */
	private static function daily_chart( array $daily ) {
		$max = max( array_merge( array( 0 ), array_values( $daily ) ) );
		$n   = count( $daily );
		if ( $n < 1 ) {
			return;
		}
		$w   = 800;
		$h   = 140;
		$gap = 2;
		$bw  = max( 1, ( $w - $gap * ( $n - 1 ) ) / $n );

		echo '<section class="airt-card airt-chart"><h2>' . esc_html__( 'AI revenue per day', 'ai-revenue-tracker' ) . '</h2>';
		echo '<svg viewBox="0 0 ' . (int) $w . ' ' . (int) $h . '" preserveAspectRatio="none" role="img" aria-label="' . esc_attr__( 'Daily AI revenue', 'ai-revenue-tracker' ) . '">';
		$i = 0;
		foreach ( $daily as $day => $value ) {
			$bh = $max > 0 ? max( $value > 0 ? 2 : 0, ( $value / $max ) * ( $h - 4 ) ) : 0;
			$x  = $i * ( $bw + $gap );
			printf(
				'<rect x="%1$s" y="%2$s" width="%3$s" height="%4$s" rx="2"><title>%5$s: %6$s</title></rect>',
				esc_attr( round( $x, 2 ) ),
				esc_attr( round( $h - $bh, 2 ) ),
				esc_attr( round( $bw, 2 ) ),
				esc_attr( round( max( $bh, 1 ), 2 ) ),
				esc_html( $day ),
				esc_html( html_entity_decode( wp_strip_all_tags( wc_price( $value ) ) ) )
			);
			++$i;
		}
		echo '</svg>';
		$keys = array_keys( $daily );
		echo '<div class="airt-axis"><span>' . esc_html( reset( $keys ) ) . '</span><span>' . esc_html( end( $keys ) ) . '</span></div>';
		echo '</section>';
	}

	/**
	 * @param array $d Summary.
	 */
	private static function bots_card( array $d ) {
		$types = array(
			'search'   => __( 'AI search index', 'ai-revenue-tracker' ),
			'agent'    => __( 'Live request for a user', 'ai-revenue-tracker' ),
			'training' => __( 'Model training', 'ai-revenue-tracker' ),
		);
		$bots  = AIRT_Sources::bots();

		echo '<section class="airt-card"><h2>' . esc_html__( 'AI crawlers reading your store', 'ai-revenue-tracker' ) . '</h2>';
		if ( ! AIRT_Settings::get( 'bot_log' ) ) {
			echo '<p class="airt-muted">' . esc_html__( 'The AI crawler log is turned off in Settings.', 'ai-revenue-tracker' ) . '</p></section>';
			return;
		}
		if ( empty( $d['bots'] ) ) {
			echo '<p class="airt-muted">' . esc_html__( 'No AI crawler visits recorded yet. Visits to cached pages may not be counted.', 'ai-revenue-tracker' ) . '</p></section>';
			return;
		}

		$fake = 0;
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Bot', 'ai-revenue-tracker' ) . '</th><th>' . esc_html__( 'Purpose', 'ai-revenue-tracker' ) . '</th><th class="num">' . esc_html__( 'Visits', 'ai-revenue-tracker' ) . '</th></tr></thead><tbody>';
		foreach ( $d['bots'] as $key => $counts ) {
			$fake += $counts['bot_x'];
			$real  = $counts['bot_v'] + $counts['bot_u'];
			if ( ! $real ) {
				continue;
			}
			$label = isset( $bots[ $key ] ) ? $bots[ $key ]['label'] : $key;
			$type  = isset( $bots[ $key ], $types[ $bots[ $key ]['type'] ] ) ? $types[ $bots[ $key ]['type'] ] : '';
			$badge = $counts['bot_v'] ? ' <span class="airt-badge" title="' . esc_attr__( 'Checked against the IP ranges this company publishes', 'ai-revenue-tracker' ) . '">' . esc_html__( 'verified', 'ai-revenue-tracker' ) . '</span>' : '';
			echo '<tr><td>' . esc_html( $label ) . $badge . '</td><td>' . esc_html( $type ) . '</td><td class="num">' . esc_html( number_format_i18n( $real ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</tbody></table>';
		if ( $fake ) {
			/* translators: %s: number of visits */
			echo '<p class="airt-muted">' . esc_html( sprintf( __( '%s visits pretended to be AI bots but came from the wrong IP addresses. They are excluded above.', 'ai-revenue-tracker' ), number_format_i18n( $fake ) ) ) . '</p>';
		}

		if ( ! empty( $d['bot_products'] ) ) {
			echo '<h3>' . esc_html__( 'Most-read products', 'ai-revenue-tracker' ) . '</h3><ol class="airt-list">';
			foreach ( $d['bot_products'] as $row ) {
				$title = get_the_title( (int) $row['product_id'] );
				echo '<li>' . esc_html( $title ? $title : '#' . $row['product_id'] ) . ' <span class="airt-muted">(' . esc_html( number_format_i18n( (int) $row['hits'] ) ) . ')</span></li>';
			}
			echo '</ol>';
		}
		echo '</section>';
	}

	/**
	 * @param WC_Order[] $orders Orders.
	 */
	private static function recent_orders( array $orders ) {
		echo '<section class="airt-card"><h2>' . esc_html__( 'Recent AI orders', 'ai-revenue-tracker' ) . '</h2>';
		if ( empty( $orders ) ) {
			echo '<p class="airt-muted">' . esc_html__( 'Nothing yet.', 'ai-revenue-tracker' ) . '</p></section>';
			return;
		}
		$labels = AIRT_Classifier::channel_labels();
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Order', 'ai-revenue-tracker' ) . '</th><th>' . esc_html__( 'Date', 'ai-revenue-tracker' ) . '</th><th>' . esc_html__( 'Assistant', 'ai-revenue-tracker' ) . '</th><th>' . esc_html__( 'How we know', 'ai-revenue-tracker' ) . '</th><th class="num">' . esc_html__( 'Total', 'ai-revenue-tracker' ) . '</th></tr></thead><tbody>';
		foreach ( $orders as $order ) {
			$ch = (string) $order->get_meta( '_airt_channel' );
			printf(
				'<tr><td><a href="%1$s">#%2$s</a></td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td class="num">%6$s</td></tr>',
				esc_url( $order->get_edit_order_url() ),
				esc_html( $order->get_order_number() ),
				esc_html( $order->get_date_created() ? wc_format_datetime( $order->get_date_created() ) : '' ),
				esc_html( AIRT_Sources::label( (string) $order->get_meta( '_airt_source' ) ) ),
				esc_html( isset( $labels[ $ch ] ) ? $labels[ $ch ] : $ch ),
				wp_kses_post( $order->get_formatted_order_total() )
			);
		}
		echo '</tbody></table></section>';
	}

	private static function pro_box() {
		echo '<section class="airt-card airt-pro"><h2>' . esc_html__( 'Next: see why AI picks you, or your competitors', 'ai-revenue-tracker' ) . '</h2>';
		echo '<ul class="airt-list"><li>' . esc_html__( 'Track the buyer questions where ChatGPT, Gemini and Perplexity recommend your products, and who beats you.', 'ai-revenue-tracker' ) . '</li>';
		echo '<li>' . esc_html__( 'Get alerted when AI quotes the wrong price, stock or details.', 'ai-revenue-tracker' ) . '</li>';
		echo '<li>' . esc_html__( 'Send AI orders to GA4 and Meta server-side.', 'ai-revenue-tracker' ) . '</li></ul>';
		if ( AIRT_PRO_URL ) {
			echo '<p><a class="button button-primary" href="' . esc_url( AIRT_PRO_URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'Join the Pro waitlist', 'ai-revenue-tracker' ) . '</a></p>';
		} else {
			echo '<p class="airt-muted">' . esc_html__( 'Coming soon.', 'ai-revenue-tracker' ) . '</p>';
		}
		echo '</section>';
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	private static function settings_page() {
		$s   = AIRT_Settings::all();
		$opt = AIRT_Settings::OPTION;

		echo '<form method="post" action="options.php" class="airt-settings">';
		settings_fields( 'airt_group' );
		echo '<table class="form-table" role="presentation"><tbody>';

		self::checkbox_row( $opt, 'survey', $s, __( 'Post-purchase question', 'ai-revenue-tracker' ), __( 'Ask "How did you find us?" on the order confirmation page. Catches AI-influenced sales that left no trace.', 'ai-revenue-tracker' ) );
		self::checkbox_row( $opt, 'likely', $s, __( 'Likely-AI estimate', 'ai-revenue-tracker' ), __( 'Flag untracked orders that look like AI referrals. Always shown separately, never added to confirmed totals.', 'ai-revenue-tracker' ) );
		self::checkbox_row( $opt, 'visits', $s, __( 'Count AI visits', 'ai-revenue-tracker' ), __( 'Count shoppers arriving from AI apps (aggregated per day, no personal data).', 'ai-revenue-tracker' ) );
		self::checkbox_row( $opt, 'bot_log', $s, __( 'AI crawler log', 'ai-revenue-tracker' ), __( 'Count visits from AI crawlers and agents per product, verified against published IP ranges where available.', 'ai-revenue-tracker' ) );

		printf(
			'<tr><th scope="row"><label for="airt-cookie">%1$s</label></th><td><input id="airt-cookie" type="number" min="1" max="90" name="%2$s[cookie_days]" value="%3$d" class="small-text"> %4$s<p class="description">%5$s</p></td></tr>',
			esc_html__( 'Attribution window', 'ai-revenue-tracker' ),
			esc_attr( $opt ),
			(int) $s['cookie_days'],
			esc_html__( 'days', 'ai-revenue-tracker' ),
			esc_html__( 'An order counts as AI when the shopper came from AI within this many days.', 'ai-revenue-tracker' )
		);

		printf(
			'<tr><th scope="row"><label for="airt-retention">%1$s</label></th><td><input id="airt-retention" type="number" min="30" max="1825" name="%2$s[retention_days]" value="%3$d" class="small-text"> %4$s</td></tr>',
			esc_html__( 'Keep visit and crawler counts for', 'ai-revenue-tracker' ),
			esc_attr( $opt ),
			(int) $s['retention_days'],
			esc_html__( 'days', 'ai-revenue-tracker' )
		);

		$headers = array(
			''      => __( 'None, use the direct connection (default)', 'ai-revenue-tracker' ),
			'cf'    => __( 'Cloudflare (CF-Connecting-IP)', 'ai-revenue-tracker' ),
			'xff'   => __( 'Load balancer (X-Forwarded-For)', 'ai-revenue-tracker' ),
			'xreal' => __( 'Nginx proxy (X-Real-IP)', 'ai-revenue-tracker' ),
		);
		echo '<tr><th scope="row"><label for="airt-ip">' . esc_html__( 'Visitor IP comes from', 'ai-revenue-tracker' ) . '</label></th><td><select id="airt-ip" name="' . esc_attr( $opt ) . '[ip_header]">';
		foreach ( $headers as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $s['ip_header'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'Only change this if your site sits behind a proxy or CDN, otherwise bot verification sees the proxy address.', 'ai-revenue-tracker' ) . '</p></td></tr>';

		printf(
			'<tr><th scope="row"><label for="airt-agents">%1$s</label></th><td><textarea id="airt-agents" name="%2$s[agent_users]" rows="3" class="large-text code" placeholder="chatgpt-checkout = chatgpt">%3$s</textarea><p class="description">%4$s</p></td></tr>',
			esc_html__( 'AI agent API users', 'ai-revenue-tracker' ),
			esc_attr( $opt ),
			esc_textarea( $s['agent_users'] ),
			esc_html__( 'If an AI agent integration places orders through a WooCommerce API key, enter the username that owns the key, one per line, optionally with the assistant: "username = chatgpt". Orders it creates are tagged as AI agent orders.', 'ai-revenue-tracker' )
		);

		self::checkbox_row( $opt, 'delete_data', $s, __( 'Delete data on uninstall', 'ai-revenue-tracker' ), __( 'Remove all counts, settings and AI tags on orders when the plugin is deleted.', 'ai-revenue-tracker' ) );

		echo '</tbody></table>';
		submit_button();
		echo '</form>';
	}

	/**
	 * @param string $opt   Option name.
	 * @param string $key   Setting key.
	 * @param array  $s     Settings.
	 * @param string $label Label.
	 * @param string $desc  Description.
	 */
	private static function checkbox_row( $opt, $key, array $s, $label, $desc ) {
		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="%2$s[%3$s]" value="1" %4$s> %5$s</label></td></tr>',
			esc_html( $label ),
			esc_attr( $opt ),
			esc_attr( $key ),
			checked( ! empty( $s[ $key ] ), true, false ),
			esc_html( $desc )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Orders screen                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function add_column( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$out['airt_source'] = __( 'AI source', 'ai-revenue-tracker' );
			}
		}
		if ( ! isset( $out['airt_source'] ) ) {
			$out['airt_source'] = __( 'AI source', 'ai-revenue-tracker' );
		}
		return $out;
	}

	/**
	 * @param string   $column Column.
	 * @param WC_Order $order  Order.
	 */
	public static function render_column_hpos( $column, $order ) {
		if ( 'airt_source' === $column ) {
			self::column_cell( $order );
		}
	}

	/**
	 * @param string $column  Column.
	 * @param int    $post_id Post ID.
	 */
	public static function render_column_legacy( $column, $post_id ) {
		if ( 'airt_source' === $column ) {
			self::column_cell( wc_get_order( $post_id ) );
		}
	}

	/**
	 * @param WC_Order|false $order Order.
	 */
	private static function column_cell( $order ) {
		if ( ! $order ) {
			return;
		}
		$source = (string) $order->get_meta( '_airt_source' );
		if ( '' === $source ) {
			echo '<span class="airt-muted">–</span>';
			return;
		}
		$labels = AIRT_Classifier::channel_labels();
		$ch     = (string) $order->get_meta( '_airt_channel' );
		echo esc_html( AIRT_Sources::label( $source ) ) . '<br><small class="airt-muted">' . esc_html( isset( $labels[ $ch ] ) ? $labels[ $ch ] : $ch ) . '</small>';
	}

	public static function meta_box() {
		$screen = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';
		add_meta_box( 'airt-order', __( 'AI attribution', 'ai-revenue-tracker' ), array( __CLASS__, 'render_meta_box' ), $screen, 'side', 'default' );
	}

	/**
	 * @param WP_Post|WC_Order $object Post or order.
	 */
	public static function render_meta_box( $object ) {
		$order = $object instanceof WC_Order ? $object : wc_get_order( $object->ID );
		if ( ! $order ) {
			return;
		}
		$labels = AIRT_Classifier::channel_labels();
		$source = (string) $order->get_meta( '_airt_source' );
		$ch     = (string) $order->get_meta( '_airt_channel' );

		if ( '' === $source ) {
			echo '<p>' . esc_html__( 'Not attributed to AI.', 'ai-revenue-tracker' ) . '</p>';
		} else {
			echo '<p><strong>' . esc_html( AIRT_Sources::label( $source ) ) . '</strong><br>' . esc_html( isset( $labels[ $ch ] ) ? $labels[ $ch ] : $ch ) . '</p>';
		}

		$rows = array(
			__( 'Landing page', 'ai-revenue-tracker' )      => $order->get_meta( '_airt_click_landing' ),
			__( 'First AI visit', 'ai-revenue-tracker' )    => $order->get_meta( '_airt_click_ts' ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $order->get_meta( '_airt_click_ts' ) ) : '',
			__( 'Agent signal', 'ai-revenue-tracker' )      => $order->get_meta( '_airt_agent_signal' ),
			__( 'Customer answered', 'ai-revenue-tracker' ) => $order->get_meta( '_airt_survey' ) ? AIRT_Survey::options()[ $order->get_meta( '_airt_survey' ) ] ?? $order->get_meta( '_airt_survey' ) : '',
		);
		foreach ( $rows as $label => $value ) {
			if ( '' !== (string) $value ) {
				echo '<p class="airt-muted" style="margin:.25em 0">' . esc_html( $label ) . ': ' . esc_html( (string) $value ) . '</p>';
			}
		}
	}
}
