<?php
/**
 * One-click "How did you find us?" question on the order confirmation page.
 * Catches AI-influenced sales that left no digital trace.
 *
 * @package AIRevenueTracker
 */

defined( 'ABSPATH' ) || exit;

final class AIRT_Survey {

	/**
	 * @return array answer key => label
	 */
	public static function options() {
		$options = array(
			'google'     => __( 'Google search', 'ai-revenue-tracker' ),
			'chatgpt'    => 'ChatGPT',
			'gemini'     => 'Gemini',
			'perplexity' => 'Perplexity',
			'claude'     => 'Claude',
			'copilot'    => 'Copilot',
			'other_ai'   => __( 'Another AI assistant', 'ai-revenue-tracker' ),
			'social'     => __( 'Social media', 'ai-revenue-tracker' ),
			'friend'     => __( 'Friend or family', 'ai-revenue-tracker' ),
			'other'      => __( 'Other', 'ai-revenue-tracker' ),
		);
		return apply_filters( 'airt_survey_options', $options );
	}

	public static function init() {
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'render' ), 5 );
		add_action( 'wp_ajax_airt_survey', array( __CLASS__, 'save' ) );
		add_action( 'wp_ajax_nopriv_airt_survey', array( __CLASS__, 'save' ) );
	}

	/**
	 * @param int $order_id Order ID.
	 */
	public static function render( $order_id ) {
		if ( ! AIRT_Settings::get( 'survey' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( '_airt_survey' ) ) {
			return;
		}
		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			return;
		}

		echo '<section class="airt-survey" style="margin:1.5em 0;padding:1em 1.25em;border:1px solid rgba(0,0,0,.12);border-radius:8px">';
		echo '<p style="margin:0 0 .75em;font-weight:600">' . esc_html__( 'Quick question: how did you find us?', 'ai-revenue-tracker' ) . '</p>';
		echo '<div class="airt-survey-options" style="display:flex;flex-wrap:wrap;gap:.5em">';
		foreach ( self::options() as $value => $label ) {
			printf(
				'<button type="button" class="button airt-survey-btn" data-value="%1$s">%2$s</button>',
				esc_attr( $value ),
				esc_html( $label )
			);
		}
		echo '</div></section>';

		$data = array(
			'url'     => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'airt_survey' ),
			'orderId' => $order->get_id(),
			'key'     => $order->get_order_key(),
			'thanks'  => __( 'Thanks, that really helps us!', 'ai-revenue-tracker' ),
		);

		wp_register_script( 'airt-survey', false, array(), AIRT_VERSION, true );
		wp_enqueue_script( 'airt-survey' );
		wp_add_inline_script(
			'airt-survey',
			'(function(d){var c=' . wp_json_encode( $data ) . ';document.querySelectorAll(".airt-survey-btn").forEach(function(b){b.addEventListener("click",function(){var f=new FormData();f.append("action","airt_survey");f.append("nonce",c.nonce);f.append("order_id",c.orderId);f.append("key",c.key);f.append("answer",b.getAttribute("data-value"));fetch(c.url,{method:"POST",body:f,credentials:"same-origin"});var s=b.closest(".airt-survey");s.innerHTML="";var p=d.createElement("p");p.style.margin="0";p.textContent=c.thanks;s.appendChild(p);});});})(document);'
		);
	}

	public static function save() {
		check_ajax_referer( 'airt_survey', 'nonce' );

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$key      = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		$answer   = isset( $_POST['answer'] ) ? sanitize_key( wp_unslash( $_POST['answer'] ) ) : '';

		$order = wc_get_order( $order_id );
		if ( ! $order || '' === $key || ! hash_equals( $order->get_order_key(), $key ) || ! array_key_exists( $answer, self::options() ) ) {
			wp_send_json_error( null, 400 );
		}

		if ( ! $order->get_meta( '_airt_survey' ) ) {
			$order->update_meta_data( '_airt_survey', $answer );
			$order->save_meta_data();
			if ( $order->is_paid() ) {
				AIRT_Orders::classify( $order );
			}
		}
		wp_send_json_success();
	}
}
