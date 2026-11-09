<?php
/**
 * Plugin Name:          AI Revenue Tracker for WooCommerce
 * Plugin URI:           https://example.com/ai-revenue-tracker
 * Description:          See how much ChatGPT, Perplexity, Gemini, Claude, Copilot and AI shopping agents sell for your WooCommerce store. Server-side AI sales attribution, AI agent order tagging and an AI crawler log.
 * Version:              0.1.0
 * Requires at least:    6.4
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               AI Revenue Tracker
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          ai-revenue-tracker
 * WC requires at least: 8.5
 * WC tested up to:      11.2
 *
 * @package AIRevenueTracker
 */

defined( 'ABSPATH' ) || exit;

define( 'AIRT_VERSION', '0.1.0' );
define( 'AIRT_FILE', __FILE__ );
define( 'AIRT_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIRT_URL', plugin_dir_url( __FILE__ ) );

/*
 * Link for the paid (cloud) upgrade or waitlist. Leave empty to show a
 * "coming soon" note instead of a button on the dashboard.
 */
if ( ! defined( 'AIRT_PRO_URL' ) ) {
	define( 'AIRT_PRO_URL', '' );
}

require_once AIRT_DIR . 'includes/class-airt-sources.php';
require_once AIRT_DIR . 'includes/class-airt-ip.php';
require_once AIRT_DIR . 'includes/class-airt-classifier.php';
require_once AIRT_DIR . 'includes/class-airt-settings.php';
require_once AIRT_DIR . 'includes/class-airt-request.php';
require_once AIRT_DIR . 'includes/class-airt-db.php';
require_once AIRT_DIR . 'includes/class-airt-install.php';
require_once AIRT_DIR . 'includes/class-airt-tracker.php';
require_once AIRT_DIR . 'includes/class-airt-agent-orders.php';
require_once AIRT_DIR . 'includes/class-airt-orders.php';
require_once AIRT_DIR . 'includes/class-airt-bot-log.php';
require_once AIRT_DIR . 'includes/class-airt-survey.php';
require_once AIRT_DIR . 'includes/class-airt-reports.php';
require_once AIRT_DIR . 'includes/class-airt-admin.php';

register_activation_hook( __FILE__, array( 'AIRT_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AIRT_Install', 'deactivate' ) );

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'AI Revenue Tracker needs WooCommerce to be installed and active.', 'ai-revenue-tracker' ) . '</p></div>';
				}
			);
			return;
		}

		AIRT_Install::maybe_upgrade();
		AIRT_Tracker::init();
		AIRT_Orders::init();
		AIRT_Bot_Log::init();
		AIRT_Survey::init();

		if ( is_admin() ) {
			AIRT_Admin::init();
		}
	}
);
