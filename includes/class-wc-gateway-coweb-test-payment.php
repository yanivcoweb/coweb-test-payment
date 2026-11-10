<?php

defined( 'ABSPATH' ) || exit;

class WC_Gateway_Coweb_Test_Payment extends WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = COWEB_TP_ID;
		$this->method_title       = 'Coweb Test Payment';
		$this->method_description = 'שער בדיקות — מסמן את ההזמנה כשולמה בלי סליקה אמיתית. מי שרואה אותו תלוי בסביבה שבה האתר רץ.';
		$this->has_fields         = false;
		// tokenization + coweb_recurring: כרטיס מדומה נשמר בתשלום הראשון, ו-Coweb Licensing מחייב אותו כל חודש.
		$this->supports           = array( 'products', 'refunds', 'tokenization', 'coweb_recurring' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init_form_fields() {
		$statuses = array( 'default' => 'ברירת מחדל של WooCommerce (processing / completed)' );
		foreach ( wc_get_order_statuses() as $slug => $label ) {
			$statuses[ str_replace( 'wc-', '', $slug ) ] = $label;
		}

		$this->form_fields = array(
			'enabled'        => array(
				'title'   => 'הפעלה',
				'type'    => 'checkbox',
				'label'   => 'הפעל את שער הבדיקות',
				'default' => 'yes',
			),
			'title'          => array(
				'title'   => 'כותרת בעמוד התשלום',
				'type'    => 'text',
				'default' => '🧪 תשלום בדיקה',
			),
			'description'    => array(
				'title'   => 'תיאור בעמוד התשלום',
				'type'    => 'textarea',
				'default' => 'שער בדיקות. לא מבוצעת סליקה אמיתית ולא נגבה כסף.',
			),
			'result'         => array(
				'title'       => 'תוצאת הסליקה המדומה',
				'type'        => 'select',
				'default'     => 'success',
				'description' => 'בחר "כשל" כדי לבדוק את מסלול התשלום הכושל.',
				'desc_tip'    => true,
				'options'     => array(
					'success' => 'תשלום מוצלח',
					'failure' => 'תשלום נכשל',
				),
			),
			'renewal_result' => array(
				'title'       => 'תוצאת החיוב החודשי המדומה',
				'type'        => 'select',
				'default'     => 'success',
				'description' => 'לחידושים של Coweb Licensing. "תוצאה לא ידועה" מדמה תקלת תקשורת עם חברת הסליקה: המנוי עוצר לבדיקה ולא מחויב שוב.',
				'desc_tip'    => true,
				'options'     => array(
					'success'   => 'חיוב מוצלח',
					'failure'   => 'חיוב נכשל',
					'uncertain' => 'תוצאה לא ידועה',
				),
			),
			'order_status'   => array(
				'title'       => 'סטטוס הזמנה אחרי תשלום מוצלח',
				'type'        => 'select',
				'default'     => 'default',
				'description' => 'השאר בברירת מחדל כדי לקבל בדיוק את אותו מסלול כמו סליקה אמיתית.',
				'desc_tip'    => true,
				'options'     => $statuses,
			),
			'staging_public' => array(
				'title'       => 'בדיקות לקוח',
				'type'        => 'checkbox',
				'label'       => 'פתח את השער לכל מבקר בסביבת staging',
				'default'     => 'no',
				'description' => 'ברירת המחדל ב-staging היא מנהלים בלבד. האפשרות לא משפיעה על live.',
			),
			'send_emails'    => array(
				'title'       => 'מיילים',
				'type'        => 'checkbox',
				'label'       => 'שלח מיילים של WooCommerce גם על הזמנות בדיקה',
				'default'     => 'no',
				'description' => 'ברירת המחדל היא לא לשלוח. אם תסמן, המיילים יגיעו לנמענים האמיתיים של החנות והלקוח.',
			),
		);
	}

	/**
	 * השער רשום בכל סביבה כדי שמסך ההגדרות יהיה קיים, אבל זמין רק למי שמורשה.
	 */
	public function is_available() {
		return parent::is_available() && coweb_tp_can_use();
	}

	public function admin_options() {
		$environment = coweb_tp_environment();

		if ( 'local' === $environment ) {
			$access = 'השער פתוח לכל מבקר.';
		} elseif ( 'staging' === $environment ) {
			$access = 'yes' === $this->get_option( 'staging_public' )
				? 'השער פתוח כרגע לכל מבקר (בדיקות לקוח).'
				: 'השער פתוח רק למשתמש מחובר עם הרשאת ניהול חנות.';
		} else {
			$access = 'השער כבוי עד שמנהל מפעיל אותו ל-60 דקות, וגם אז רק משתמשים עם הרשאת ניהול חנות רואים אותו.';
		}

		echo '<div class="notice notice-info inline"><p><strong>סביבה שזוהתה: ' . esc_html( $environment ) . '.</strong> ' . esc_html( $access ) . '</p>';

		if ( 'live' === $environment ) {
			echo '<p><strong>מצב: ' . esc_html( coweb_tp_is_armed() ? 'מופעל, ' . coweb_tp_armed_label() : 'כבוי' ) . '.</strong></p>';
			echo '<p>';
			printf( '<a class="button button-primary" href="%s">הפעל ל-60 דקות</a> ', esc_url( coweb_tp_action_url( 'coweb_test_payment_arm' ) ) );
			printf( '<a class="button" href="%s">כבה</a>', esc_url( coweb_tp_action_url( 'coweb_test_payment_disarm' ) ) );
			echo '</p>';
		}

		echo '</div>';

		echo '<div class="notice notice-warning inline"><p><strong>חשוב לדעת:</strong> השער מדלג רק על הסליקה. הפחתת מלאי, נתוני Analytics ואינטגרציות חשבונית/ERP רצים על הזמנת בדיקה כמו על הזמנה אמיתית. התראות מלאי ומיילים שלא קשורים להזמנה (למשל חשבון חדש) ממשיכים להישלח גם כשמיילי ההזמנה חסומים.</p></div>';

		parent::admin_options();
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}

		// בדיקה חוזרת בצד השרת: לא סומכים על כך שהשער הוצג בעמוד התשלום.
		if ( 'yes' !== $this->enabled || ! coweb_tp_can_use() ) {
			$order->add_order_note( 'תשלום בדיקה נדחה — אין הרשאה לשער הבדיקות. ' . coweb_tp_audit_label() );
			$this->add_error_notice( 'שער הבדיקות אינו זמין.' );

			return array( 'result' => 'failure' );
		}

		// חייב לקרות לפני שינוי הסטטוס, אחרת המיילים כבר יצאו בלי לדעת שזו הזמנת בדיקה.
		$order->update_meta_data( COWEB_TP_TEST_ORDER_META, 'yes' );
		$order->save();

		if ( 'failure' === $this->get_option( 'result' ) ) {
			$order->update_status( 'failed', 'סימולציית כשל סליקה — שער הבדיקות. ' . coweb_tp_audit_label() );
			$this->add_error_notice( 'סימולציית כשל בסליקה (שער הבדיקות).' );

			return array( 'result' => 'failure' );
		}

		$order->add_order_note( 'שולם דרך שער הבדיקות — ללא סליקה אמיתית. ' . coweb_tp_audit_label() );

		// לקוח מחובר מקבל "כרטיס" מדומה, כדי שאפשר יהיה לחייב אותו בחידוש הבא.
		$this->save_test_token( $order );

		// זה מה שמפעיל את כל מה שקורה אחרי תשלום מוצלח:
		// הפחתת מלאי, woocommerce_payment_complete, אינטגרציות חשבונית/ERP.
		$order->payment_complete( 'TEST-' . $order->get_id() . '-' . time() );

		$target = $this->get_option( 'order_status' );
		if ( $target && 'default' !== $target ) {
			$order->update_status( $target, 'סטטוס נכפה על ידי שער הבדיקות.' );
		}

		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );

		if ( $order ) {
			$order->add_order_note(
				sprintf( 'החזר מדומה בסך %s — שער הבדיקות. %s', wc_price( $amount ), $reason )
			);
		}

		return true;
	}

	/**
	 * שומר כרטיס מדומה ללקוח (או משתמש בזה שכבר יש לו), ומסמן אותו בהזמנה
	 * ב-_cwl_payment_token_id, כפי ש-Coweb Licensing מצפה.
	 */
	private function save_test_token( WC_Order $order ) {
		$user = $order->get_customer_id();
		if ( ! $user ) {
			return;
		}

		$token = null;
		foreach ( WC_Payment_Tokens::get_customer_tokens( $user, $this->id ) as $existing ) {
			$token = $existing;
			break;
		}

		if ( ! $token ) {
			$token = new WC_Payment_Token_CC();
			$token->set_token( 'test_' . bin2hex( random_bytes( 12 ) ) );
			$token->set_gateway_id( $this->id );
			$token->set_card_type( 'visa' );
			$token->set_last4( '4242' );
			$token->set_expiry_month( '12' );
			$token->set_expiry_year( (string) ( (int) gmdate( 'Y' ) + 3 ) );
			$token->set_user_id( $user );
			$token->set_default( true );
			$token->save();
		}

		$order->update_meta_data( '_cwl_payment_token_id', $token->get_id() );
		$order->save();
	}

	/**
	 * חיוב חודשי מדומה עבור Coweb Licensing. לא קורא ל-payment_complete: את זה עושה המנוע.
	 *
	 * @return array result = success | failure | uncertain
	 */
	public function coweb_charge_renewal( WC_Order $order, WC_Payment_Token $token ) {
		if ( ! coweb_tp_can_charge_renewal() || $token->get_gateway_id() !== $this->id ) {
			$order->add_order_note( 'חיוב חודשי מדומה נדחה — שער הבדיקות לא מחייב חידושים בסביבה הזו. ' . coweb_tp_audit_label() );

			return array( 'result' => 'failure', 'error' => 'test_gateway_not_allowed' );
		}

		// כמו בתשלום רגיל: הסימון קודם, כדי שמיילים של ההזמנה יידעו שזו בדיקה.
		$order->update_meta_data( COWEB_TP_TEST_ORDER_META, 'yes' );
		$order->save();

		$result = $this->get_option( 'renewal_result', 'success' );

		if ( 'failure' === $result ) {
			$order->add_order_note( 'סימולציית כשל בחיוב חודשי — שער הבדיקות.' );

			return array( 'result' => 'failure', 'error' => 'test_declined' );
		}

		if ( 'uncertain' === $result ) {
			$order->add_order_note( 'סימולציית תוצאה לא ידועה בחיוב חודשי — שער הבדיקות.' );

			return array( 'result' => 'uncertain', 'error' => 'test_timeout' );
		}

		$order->add_order_note( 'חיוב חודשי דרך שער הבדיקות — ללא סליקה אמיתית.' );

		return array( 'result' => 'success', 'transaction_id' => 'TEST-R-' . $order->get_id() . '-' . time() );
	}

	private function add_error_notice( $message ) {
		if ( WC()->session ) {
			wc_add_notice( $message, 'error' );
		}
	}
}
