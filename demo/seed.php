<?php
/**
 * Demo data for AI Revenue Tracker.
 *
 * Creates a sample home-goods store with 90 days of orders, a realistic mix of
 * traffic sources (including AI click-throughs, AI agent orders and survey answers),
 * AI visit counts and AI crawler activity. AI's share grows over the period, the way
 * it does for real stores.
 *
 * Usage, with WordPress loaded and both WooCommerce and AI Revenue Tracker active:
 *   airt_demo_seed();
 *
 * Demo only. Never run this on a live store.
 *
 * @package AIRevenueTracker
 */

defined( 'ABSPATH' ) || exit;

/**
 * @param int $days Days of history.
 * @return string Result message.
 */
function airt_demo_seed( $days = 90 ) {
	if ( get_option( 'airt_demo_seeded' ) ) {
		return 'Already seeded.';
	}
	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'AIRT_Orders' ) ) {
		return 'WooCommerce and AI Revenue Tracker must be active.';
	}

	mt_srand( 42 );
	wp_set_current_user( 1 );
	add_filter( 'pre_wp_mail', '__return_false' ); // No emails while seeding.

	update_option( 'blogname', 'Linen & Loom (demo)' );
	update_option( 'woocommerce_currency', 'USD' );
	update_option( 'woocommerce_default_country', 'US:FL' );
	update_option( 'woocommerce_onboarding_profile', array( 'skipped' => true ) );
	update_option( 'woocommerce_coming_soon', 'no' );

	$products = airt_demo_products();
	$orders   = airt_demo_orders( $products, $days );
	airt_demo_activity( $products, $days );

	update_option(
		'airt_backfill_status',
		array(
			'state'     => 'done',
			'processed' => $orders,
			'finished'  => time(),
		),
		false
	);
	delete_transient( 'airt_store_totals' );
	update_option( 'airt_demo_seeded', time() );

	return sprintf( 'Seeded %d products and %d orders.', count( $products ), $orders );
}

/**
 * @return int[] Product IDs.
 */
function airt_demo_products() {
	$catalog = array(
		'Bedding' => array(
			array( 'Stonewashed Linen Sheet Set', 189, 'Breathable French flax linen, stonewashed for softness. Fits mattresses up to 16 inches deep.' ),
			array( 'Linen Duvet Cover', 229, 'Pure linen duvet cover with hidden button closure and corner ties.' ),
			array( 'Waffle Weave Blanket', 139, 'Lightweight cotton waffle blanket for warm sleepers.' ),
			array( 'Organic Cotton Percale Sheets', 149, 'Crisp, cool GOTS-certified organic cotton percale.' ),
			array( 'Linen Pillowcase Pair', 59, 'Two linen pillowcases with envelope closure.' ),
			array( 'Down-Alternative Pillow', 79, 'Hypoallergenic pillow with adjustable fill.' ),
		),
		'Bath'    => array(
			array( 'Turkish Cotton Bath Towel Set', 98, 'Long-staple Turkish cotton, plush and fast drying.' ),
			array( 'Linen Bath Towel', 48, 'Quick-drying linen towel that gets softer with every wash.' ),
			array( 'Waffle Bathrobe', 112, 'Lightweight waffle-knit cotton robe.' ),
			array( 'Cotton Bath Mat', 39, 'Thick, absorbent tufted cotton bath mat.' ),
		),
		'Kitchen' => array(
			array( 'Linen Tablecloth', 89, 'Washed linen tablecloth in six sizes.' ),
			array( 'Linen Napkins, Set of 4', 42, 'Soft washed linen napkins.' ),
			array( 'Linen Apron', 54, 'Cross-back apron with deep front pockets.' ),
			array( 'Linen Tea Towels, Set of 3', 32, 'Absorbent, lint-free linen kitchen towels.' ),
		),
		'Living'  => array(
			array( 'Chunky Knit Throw', 129, 'Hand-knit merino wool throw.' ),
			array( 'Linen Throw Pillow Cover', 38, 'Washed linen cover with hidden zipper.' ),
			array( 'Linen Curtain Panel', 96, 'Light-filtering linen curtain with rod pocket.' ),
			array( 'Wool Area Rug 5x8', 349, 'Hand-loomed New Zealand wool rug.' ),
		),
	);

	$ids = array();
	$sku = 1000;
	foreach ( $catalog as $cat_name => $items ) {
		$term   = term_exists( $cat_name, 'product_cat' );
		$term   = $term ? $term : wp_insert_term( $cat_name, 'product_cat' );
		$cat_id = (int) ( is_array( $term ) ? $term['term_id'] : $term );

		foreach ( $items as $item ) {
			$product = new WC_Product_Simple();
			$product->set_name( $item[0] );
			$product->set_regular_price( (string) $item[1] );
			$product->set_short_description( $item[2] );
			$product->set_description( $item[2] );
			$product->set_sku( 'LL-' . ( ++$sku ) );
			$product->set_category_ids( array( $cat_id ) );
			$product->set_status( 'publish' );
			$ids[] = $product->save();
		}
	}
	return $ids;
}

/**
 * Weighted random pick.
 *
 * @param array $weights key => weight.
 * @return string
 */
function airt_demo_pick( array $weights ) {
	$roll = mt_rand( 1, (int) array_sum( $weights ) );
	foreach ( $weights as $key => $weight ) {
		$roll -= $weight;
		if ( $roll <= 0 ) {
			return $key;
		}
	}
	return (string) key( $weights );
}

/**
 * @param int[] $products Product IDs.
 * @param int   $days     Days.
 * @return int Orders created.
 */
function airt_demo_orders( array $products, $days ) {
	$first  = array( 'Ava', 'Liam', 'Maya', 'Noah', 'Zoe', 'Ethan', 'Chloe', 'Lucas', 'Isla', 'Owen', 'Nora', 'Eli', 'Ruby', 'Jack', 'Leah', 'Sam' );
	$last   = array( 'Carter', 'Nguyen', 'Patel', 'Rivera', 'Brooks', 'Kim', 'Hughes', 'Foster', 'Lopez', 'Reed', 'Shaw', 'Ortiz' );
	$emails = array();
	$count  = 0;
	$now    = time();

	$ai_mix = array( 'chatgpt' => 62, 'perplexity' => 16, 'gemini' => 12, 'claude' => 6, 'copilot' => 4 );
	$ai_ref = array(
		'chatgpt'    => 'https://chatgpt.com/',
		'perplexity' => 'https://www.perplexity.ai/',
		'gemini'     => 'https://gemini.google.com/',
		'claude'     => 'https://claude.ai/',
		'copilot'    => 'https://copilot.microsoft.com/',
	);

	for ( $d = $days; $d >= 0; $d-- ) {
		$progress = 1 - ( $d / $days );                 // 0 at the start, 1 today.
		$per_day  = mt_rand( 2, 4 ) + ( mt_rand( 0, 9 ) < 3 ? 1 : 0 );
		$ai_share = (int) round( 6 + 16 * $progress ); // AI grows from ~6% to ~22% of orders.

		for ( $n = 0; $n < $per_day; $n++ ) {
			$ts = $now - $d * DAY_IN_SECONDS - mt_rand( 0, DAY_IN_SECONDS - 1 );
			if ( $ts > $now ) {
				$ts = $now - mt_rand( 60, 3600 );
			}

			$channel = airt_demo_pick(
				array(
					'ai_click'  => $ai_share,
					'ai_agent'  => (int) round( 1 + 3 * $progress ),
					'organic'   => 34,
					'email'     => 16,
					'social'    => 10,
					'likely'    => 4,
					'direct'    => 12,
				)
			);

			// Customer: 20% returning.
			if ( $emails && mt_rand( 1, 100 ) <= 20 && 'likely' !== $channel ) {
				$customer = $emails[ array_rand( $emails ) ];
			} else {
				$customer = array(
					'first' => $first[ array_rand( $first ) ],
					'last'  => $last[ array_rand( $last ) ],
				);
				$customer['email'] = strtolower( $customer['first'] . '.' . $customer['last'] . mt_rand( 10, 9999 ) ) . '@example.com';
				$emails[]          = $customer;
			}

			$order = wc_create_order(
				array(
					'status'      => 'pending',
					'created_via' => 'ai_agent' === $channel ? 'rest-api' : 'checkout',
				)
			);

			$lines = mt_rand( 1, 100 ) <= 70 ? 1 : mt_rand( 2, 3 );
			$picked = (array) array_rand( array_flip( $products ), $lines );
			foreach ( $picked as $pid ) {
				$order->add_product( wc_get_product( $pid ), mt_rand( 1, 100 ) <= 85 ? 1 : 2 );
			}
			$main_product = (int) $picked[0];

			$order->set_billing_first_name( $customer['first'] );
			$order->set_billing_last_name( $customer['last'] );
			$order->set_billing_email( $customer['email'] );
			$order->set_billing_country( 'US' );
			$order->set_payment_method( 'stripe' );
			$order->set_payment_method_title( 'Credit card' );
			$order->calculate_totals();
			$order->set_date_created( $ts );
			$order->set_date_paid( $ts );
			$order->set_date_completed( $ts + DAY_IN_SECONDS );

			$entry = get_permalink( $main_product );
			$attr  = array(
				'source_type'   => 'typein',
				'referrer'      => '',
				'utm_source'    => '',
				'session_entry' => home_url( '/' ),
				'session_pages' => mt_rand( 3, 9 ),
			);

			switch ( $channel ) {
				case 'ai_click':
					$ai                    = airt_demo_pick( $ai_mix );
					$attr['source_type']   = 'referral';
					$attr['referrer']      = $ai_ref[ $ai ];
					$attr['session_entry'] = $entry;
					$attr['session_pages'] = mt_rand( 1, 4 );
					if ( mt_rand( 0, 1 ) ) {
						// Caught by the plugin's own click cookie.
						$order->update_meta_data( '_airt_click_source', $ai );
						$order->update_meta_data( '_airt_click_landing', (string) wp_parse_url( $entry, PHP_URL_PATH ) );
						$order->update_meta_data( '_airt_click_ts', $ts - mt_rand( 300, 3 * DAY_IN_SECONDS ) );
						if ( 'chatgpt' === $ai && mt_rand( 0, 1 ) ) {
							// ChatGPT often strips the referrer; only the cookie saw it.
							$attr['source_type'] = 'typein';
							$attr['referrer']    = '';
						}
					}
					break;

				case 'ai_agent':
					$agent = airt_demo_pick( array( 'chatgpt' => 7, 'perplexity' => 2, 'gemini' => 1 ) );
					$order->update_meta_data( '_airt_agent_source', $agent );
					$order->update_meta_data( '_airt_agent_signal', 'demo:agent-checkout' );
					$attr = null; // Agent orders have no browser session.
					break;

				case 'organic':
					$attr['source_type'] = 'organic';
					$attr['referrer']    = 'https://www.google.com/';
					$attr['utm_source']  = 'google';
					break;

				case 'email':
					$attr['source_type'] = 'utm';
					$attr['utm_source']  = 'newsletter';
					break;

				case 'social':
					$attr['source_type'] = 'referral';
					$attr['referrer']    = airt_demo_pick( array( 'https://www.instagram.com/' => 6, 'https://www.facebook.com/' => 3, 'https://www.pinterest.com/' => 4 ) );
					break;

				case 'likely':
					$attr['session_entry'] = $entry;
					$attr['session_pages'] = mt_rand( 1, 3 );
					break;
			}

			if ( $attr ) {
				foreach ( $attr as $key => $value ) {
					if ( '' !== $value ) {
						$order->update_meta_data( '_wc_order_attribution_' . $key, $value );
					}
				}
			}

			// About 1 in 4 customers answer the survey; some untracked ones credit AI.
			if ( 'ai_agent' !== $channel && mt_rand( 1, 100 ) <= 25 ) {
				if ( in_array( $channel, array( 'direct', 'likely', 'social' ), true ) && mt_rand( 1, 100 ) <= 30 ) {
					$answer = airt_demo_pick( array( 'chatgpt' => 6, 'gemini' => 2, 'perplexity' => 1, 'other_ai' => 1 ) );
				} elseif ( 'organic' === $channel ) {
					$answer = 'google';
				} else {
					$answer = airt_demo_pick( array( 'friend' => 3, 'social' => 3, 'google' => 2, 'other' => 1 ) );
				}
				$order->update_meta_data( '_airt_survey', $answer );
			}

			$order->set_status( 'completed' );
			$order->save();
			AIRT_Orders::classify( $order );
			++$count;
		}
	}

	return $count;
}

/**
 * AI visit counts and AI crawler activity, written straight into the daily counters.
 *
 * @param int[] $products Product IDs.
 * @param int   $days     Days.
 */
function airt_demo_activity( array $products, $days ) {
	global $wpdb;
	$table = AIRT_DB::table();
	$rows  = array();

	$visits = array( 'chatgpt' => 14, 'perplexity' => 4, 'gemini' => 3, 'claude' => 2, 'copilot' => 1 );
	$bots   = array(
		'oai-searchbot'  => array( 9, 'bot_v' ),
		'chatgpt-user'   => array( 6, 'bot_v' ),
		'gptbot'         => array( 12, 'bot_v' ),
		'perplexitybot'  => array( 5, 'bot_v' ),
		'claudebot'      => array( 7, 'bot_u' ),
		'claude-user'    => array( 2, 'bot_u' ),
		'applebot'       => array( 4, 'bot_u' ),
		'amazonbot'      => array( 3, 'bot_u' ),
	);

	// A few products AI "likes" more.
	$favorites = array_slice( $products, 0, 5 );

	for ( $d = $days; $d >= 0; $d-- ) {
		$progress = 1 - ( $d / $days );
		$day      = wp_date( 'Y-m-d', time() - $d * DAY_IN_SECONDS );

		foreach ( $visits as $src => $base ) {
			$n = (int) round( $base * ( 0.3 + 0.9 * $progress ) * mt_rand( 70, 130 ) / 100 );
			for ( $i = 0; $i < $n; $i++ ) {
				$pid                                 = mt_rand( 1, 100 ) <= 60 ? $favorites[ array_rand( $favorites ) ] : $products[ array_rand( $products ) ];
				$key                                 = "$day|visit|$src|$pid";
				$rows[ $key ]                        = isset( $rows[ $key ] ) ? $rows[ $key ] + 1 : 1;
			}
		}

		foreach ( $bots as $bot => $cfg ) {
			$n = (int) round( $cfg[0] * ( 0.6 + 0.6 * $progress ) * mt_rand( 60, 140 ) / 100 );
			for ( $i = 0; $i < $n; $i++ ) {
				$pid          = mt_rand( 1, 100 ) <= 75 ? $products[ array_rand( $products ) ] : 0;
				$key          = "$day|{$cfg[1]}|$bot|$pid";
				$rows[ $key ] = isset( $rows[ $key ] ) ? $rows[ $key ] + 1 : 1;
			}
		}

		// Scrapers pretending to be GPTBot.
		if ( mt_rand( 1, 100 ) <= 35 ) {
			$key          = "$day|bot_x|gptbot|0";
			$rows[ $key ] = mt_rand( 3, 15 );
		}
	}

	foreach ( $rows as $key => $hits ) {
		list( $day, $kind, $source, $pid ) = explode( '|', $key );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (day, kind, source, product_id, hits) VALUES (%s, %s, %s, %d, %d) ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$day,
				$kind,
				$source,
				(int) $pid,
				(int) $hits
			)
		);
	}
}
