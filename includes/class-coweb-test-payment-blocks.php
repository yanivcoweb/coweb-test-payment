<?php

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class Coweb_Test_Payment_Blocks extends AbstractPaymentMethodType {

	protected $name = COWEB_TP_ID;

	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . COWEB_TP_ID . '_settings', array() );
	}

	/**
	 * אותם תנאים של is_available() בשער. לא טוענים כאן את אובייקט השער
	 * כי הפונקציה נקראת גם ב-init ובעמודים שאינם עמוד תשלום.
	 */
	public function is_active() {
		return 'yes' === $this->get_setting( 'enabled', 'yes' ) && coweb_tp_can_use();
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			'coweb-test-payment-blocks',
			plugins_url( 'assets/blocks.js', COWEB_TP_FILE ),
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			COWEB_TP_VERSION,
			true
		);

		return array( 'coweb-test-payment-blocks' );
	}

	public function get_payment_method_data() {
		return array(
			'title'       => $this->get_setting( 'title', '🧪 תשלום בדיקה' ),
			'description' => $this->get_setting( 'description', 'שער בדיקות. לא מבוצעת סליקה אמיתית ולא נגבה כסף.' ),
			'supports'    => array( 'products' ),
		);
	}
}
