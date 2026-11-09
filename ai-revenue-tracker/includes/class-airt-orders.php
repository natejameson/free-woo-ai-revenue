<?php
/**
 * Writes AI attribution onto orders.
 *
 * Order meta written:
 *  _airt_click_source / _airt_click_landing / _airt_click_ts  from our cookie at checkout
 *  _airt_agent_source / _airt_agent_signal                     when an AI agent created the order
 *  _airt_survey                                                post-purchase answer
 *  _airt_source / _airt_channel / _airt_confidence             final classification (paid orders)
 *
 * @package AIRevenueTracker
 */

defined( 'ABSPATH' ) || exit;

final class AIRT_Orders {

	const BACKFILL_DAYS  = 180;
	const BACKFILL_BATCH = 100;

	public static function init() {
		add_action( 'woocommerce_new_order', array( __CLASS__, 'on_new_order' ), 20, 2 );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'stamp_click' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'stamp_click' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 20, 4 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'classify_by_id' ), 20 );
		add_action( 'airt_backfill', array( __CLASS__, 'backfill' ), 10, 1 );
	}

	/**
	 * The "likely AI" estimate is a Pro feature; Pro turns it on with this filter.
	 *
	 * @return bool
	 */
	public static function likely_enabled() {
		return (bool) apply_filters( 'airt_likely_enabled', false );
	}

	/**
	 * @return string[]
	 */
	public static function paid_statuses() {
		return function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : array( 'processing', 'completed' );
	}

	/**
	 * @param int      $order_id Order ID.
	 * @param WC_Order $order    Order.
	 */
	public static function on_new_order( $order_id, $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$agent = AIRT_Agent_Orders::from_request();
		if ( $agent ) {
			$order->update_meta_data( '_airt_agent_source', $agent['source'] );
			$order->update_meta_data( '_airt_agent_signal', $agent['signal'] );
			$order->save_meta_data();
		}

		if ( $order->is_paid() ) {
			self::classify( $order );
		}
	}

	/**
	 * Copy the AI click cookie onto the order at checkout.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function stamp_click( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$click = AIRT_Tracker::read_cookie();
		if ( ! $click ) {
			return;
		}
		$order->update_meta_data( '_airt_click_source', $click['s'] );
		$order->update_meta_data( '_airt_click_landing', $click['lp'] );
		$order->update_meta_data( '_airt_click_ts', $click['ts'] );
		$order->save_meta_data();

		if ( $order->is_paid() ) {
			self::classify( $order );
		}
	}

	/**
	 * @param int      $order_id Order ID.
	 * @param string   $from     Old status.
	 * @param string   $to       New status.
	 * @param WC_Order $order    Order.
	 */
	public static function on_status_changed( $order_id, $from, $to, $order = null ) {
		if ( in_array( $to, self::paid_statuses(), true ) ) {
			self::classify( $order instanceof WC_Order ? $order : wc_get_order( $order_id ) );
		}
	}

	/**
	 * @param int $order_id Order ID.
	 */
	public static function classify_by_id( $order_id ) {
		self::classify( wc_get_order( $order_id ) );
	}

	/**
	 * Decide and store the order's AI attribution.
	 *
	 * @param WC_Order|false $order Order.
	 * @return array|null
	 */
	public static function classify( $order ) {
		if ( ! $order instanceof WC_Order || 'shop_order_refund' === $order->get_type() ) {
			return null;
		}

		$agent = $order->get_meta( '_airt_agent_source' );
		if ( ! $agent ) {
			$agent = AIRT_Agent_Orders::from_order( $order );
		}

		$likely      = self::likely_enabled();
		$source_type = (string) $order->get_meta( '_wc_order_attribution_source_type' );

		$signals = array(
			'agent_source'   => $agent,
			'cookie_source'  => $order->get_meta( '_airt_click_source' ),
			'wc_referrer'    => $order->get_meta( '_wc_order_attribution_referrer' ),
			'wc_utm_source'  => $order->get_meta( '_wc_order_attribution_utm_source' ),
			'wc_source_type' => $source_type,
			'wc_pages'       => (int) $order->get_meta( '_wc_order_attribution_session_pages' ),
			'survey'         => $order->get_meta( '_airt_survey' ),
			'likely_enabled' => $likely,
			'home_host'      => AIRT_Request::home_host(),
		);

		// Only do the costlier lookups when the estimate could apply.
		if ( $likely && 'typein' === $source_type ) {
			$signals['entry_is_product'] = self::entry_is_product( (string) $order->get_meta( '_wc_order_attribution_session_entry' ) );
			$signals['is_returning']     = self::is_returning( $order );
		}

		$result = AIRT_Classifier::classify( apply_filters( 'airt_order_signals', $signals, $order ) );
		$result = apply_filters( 'airt_order_classification', $result, $order, $signals );

		if ( is_array( $result ) && ! empty( $result['source'] ) ) {
			$order->update_meta_data( '_airt_source', $result['source'] );
			$order->update_meta_data( '_airt_channel', $result['channel'] );
			$order->update_meta_data( '_airt_confidence', $result['confidence'] );
		} else {
			$order->delete_meta_data( '_airt_source' );
			$order->delete_meta_data( '_airt_channel' );
			$order->delete_meta_data( '_airt_confidence' );
			$result = null;
		}
		$order->save_meta_data();
		delete_transient( 'airt_store_totals' );

		return $result;
	}

	/**
	 * @param string $url Session entry URL.
	 * @return bool
	 */
	private static function entry_is_product( $url ) {
		if ( '' === $url ) {
			return false;
		}
		$post_id = url_to_postid( $url );
		return $post_id && 'product' === get_post_type( $post_id );
	}

	/**
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private static function is_returning( $order ) {
		$args = array(
			'limit'        => 1,
			'return'       => 'ids',
			'exclude'      => array( $order->get_id() ),
			'date_created' => '<' . ( $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time() ),
			'status'       => self::paid_statuses(),
		);
		if ( $order->get_customer_id() ) {
			$args['customer_id'] = $order->get_customer_id();
		} elseif ( $order->get_billing_email() ) {
			$args['billing_email'] = $order->get_billing_email();
		} else {
			return false;
		}
		return ! empty( wc_get_orders( $args ) );
	}

	/**
	 * Classify the last 180 days of paid orders in batches, using WooCommerce's own
	 * order attribution data, so the dashboard has history from day one.
	 *
	 * @param int $page Page.
	 */
	public static function backfill( $page = 1 ) {
		$page = max( 1, (int) $page );
		$ids  = wc_get_orders(
			array(
				'limit'        => self::BACKFILL_BATCH,
				'page'         => $page,
				'type'         => 'shop_order',
				'status'       => self::paid_statuses(),
				'date_created' => '>' . ( time() - self::BACKFILL_DAYS * DAY_IN_SECONDS ),
				'orderby'      => 'ID',
				'order'        => 'ASC',
				'return'       => 'ids',
			)
		);

		foreach ( $ids as $id ) {
			self::classify( wc_get_order( $id ) );
		}

		$status              = get_option( 'airt_backfill_status', array() );
		$status              = is_array( $status ) ? $status : array();
		$status['processed'] = ( isset( $status['processed'] ) ? (int) $status['processed'] : 0 ) + count( $ids );

		if ( count( $ids ) === self::BACKFILL_BATCH ) {
			$status['state'] = 'running';
			update_option( 'airt_backfill_status', $status, false );
			AIRT_Install::schedule_backfill( $page + 1 );
		} else {
			$status['state']    = 'done';
			$status['finished'] = time();
			update_option( 'airt_backfill_status', $status, false );
		}
	}
}
