<?php
/**
 * מיילים נלווים להזמנות בדיקה (1.3.0): מיילים ש-WooCommerce שולח בעקבות הזמנת בדיקה, אבל לא על ההזמנה עצמה,
 * ולכן החסימה של 1.2.0 (לפי ההזמנה שבמייל) לא תופסת אותם:
 *
 * - "חשבון חדש" כשחשבון נפתח בקופה ששולמה בשער הבדיקות. החשבון נוצר לפני התשלום, כשההזמנה עוד לא מסומנת
 *   כהזמנת בדיקה, והמייל (WC_Email_Customer_New_Account) שייך למשתמש, לא להזמנה.
 * - התראות מלאי: מלאי נמוך, אזל מהמלאי, הזמנה מראש. wc_reduce_stock_levels() / wc_increase_stock_levels()
 *   מפעילים אותן, ו-WooCommerce 11 שולח אותן ישירות ב-wp_mail (WC_Emails::low_stock() וכו'),
 *   בלי WC_Email ובלי woocommerce_mail_callback.
 *
 * לכן החסימה לפי הקשר: בזמן שהקשר של הזמנת בדיקה פתוח, כל מייל נעצר באותו מנגנון של 1.2.0. הוא עובר דרך wp_mail,
 * כדי שתוספי תיעוד (CoWeb Outgoing Mail Archive) ירשמו אותו, pre_wp_mail ב-PHP_INT_MIN עוצר אותו, ו-phpmailer_init
 * הוא רשת הביטחון. בהזמנה נרשמת הערה. מיילים שלא נגרמו מהזמנת בדיקה (לקוח אמיתי שנרשם, איפוס סיסמה) לא נחסמים,
 * גם כשהשער מופעל באתר חי. ההגדרה חלה בכל הסביבות.
 *
 * ההקשרים:
 * - checkout: קופה (קלאסית או בלוק) שמשלמת בשער הבדיקות, מאחרי הבדיקות של WooCommerce ועד שההזמנה נוצרה,
 *   לפני התשלום. מהתשלום והלאה ההזמנה מסומנת, והמיילים שלה שייכים לחסימה של 1.2.0.
 * - stock: הפחתה או החזרה של המלאי של הזמנת בדיקה, כולל החזרת המלאי במחיקה מעמוד ההגדרות.
 *
 * לא מכוסה: כשהתכונה "Deferred emails" של WooCommerce פעילה (כבויה כברירת מחדל), המיילים נשלחים אחר כך ב-Action Scheduler,
 * מחוץ להקשר, ולא נחסמים.
 */

defined( 'ABSPATH' ) || exit;

function coweb_tp_blocks_side_emails() {
	return 'no' !== get_option( COWEB_TP_BLOCK_SIDE_EMAILS_OPTION, 'yes' );
}

/**
 * ההקשרים הפתוחים לפי סוג. כל הקשר זוכר את ההזמנה. בקופה הקלאסית החשבון נוצר לפני ההזמנה,
 * ולכן ההערות מחכות בהקשר עד שההזמנה נוצרת.
 *
 * @return array<string, array{order: WC_Order|null, notes: string[]}>
 */
function &coweb_tp_side_contexts() {
	static $contexts = array();

	return $contexts;
}

function coweb_tp_open_side_context( $type, $order = null ) {
	$contexts = &coweb_tp_side_contexts();

	$contexts[ $type ] = array(
		'order' => $order instanceof WC_Order ? $order : null,
		'notes' => array(),
	);
}

/**
 * סוגר הקשר. הערות שחיכו להזמנה נרשמות בה עכשיו.
 */
function coweb_tp_close_side_context( $type, $order = null ) {
	$contexts = &coweb_tp_side_contexts();

	if ( ! isset( $contexts[ $type ] ) ) {
		return;
	}

	$context = $contexts[ $type ];
	unset( $contexts[ $type ] );

	$order = $context['order'] ?: $order;

	if ( $order instanceof WC_Order ) {
		foreach ( $context['notes'] as $note ) {
			$order->add_order_note( $note );
		}
	}
}

/**
 * האם מייל שנשלח עכשיו הוא מייל נלווה להזמנת בדיקה שצריך לחסום.
 */
function coweb_tp_in_side_context() {
	return ! empty( coweb_tp_side_contexts() ) && coweb_tp_blocks_side_emails();
}

/**
 * רושם בהזמנה של ההקשר האחרון שנפתח הערה על מייל שנחסם.
 */
function coweb_tp_note_side_email( $subject, $to ) {
	$contexts = &coweb_tp_side_contexts();

	if ( ! $contexts ) {
		return;
	}

	$type = array_key_last( $contexts );
	$note = sprintf( 'המייל "%s" אל %s לא נשלח: הוא נשלח בעקבות הזמנת בדיקה, ומיילים נלווים להזמנות בדיקה חסומים.', $subject, coweb_tp_recipients_label( $to ) );

	if ( $contexts[ $type ]['order'] ) {
		$contexts[ $type ]['order']->add_order_note( $note );
	} else {
		$contexts[ $type ]['notes'][] = $note;
	}
}

function coweb_tp_checkout_uses_gateway( $payment_method ) {
	return COWEB_TP_ID === $payment_method && coweb_tp_is_enabled() && coweb_tp_can_use();
}

// קופה קלאסית: woocommerce_checkout_process רץ אחרי הבדיקות ולפני ש-WC_Checkout::process_customer() יוצר את החשבון.
add_action( 'woocommerce_checkout_process', function () {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce בודק את ה-nonce של הקופה לפני ה-hook.
	$payment_method = isset( $_POST['payment_method'] ) ? wc_clean( wp_unslash( $_POST['payment_method'] ) ) : '';

	if ( coweb_tp_checkout_uses_gateway( $payment_method ) ) {
		coweb_tp_open_side_context( 'checkout' );
	}
} );

// ההזמנה נוצרה וכל מי שמאזין ל-hook סיים. ההקשר נסגר לפני process_payment().
add_action( 'woocommerce_checkout_order_processed', function ( $order_id, $posted_data, $order ) {
	coweb_tp_close_side_context( 'checkout', $order instanceof WC_Order ? $order : wc_get_order( $order_id ) );
}, PHP_INT_MAX, 3 );

// קופת הבלוק (Store API): אמצעי התשלום כבר נקבע בהזמנה, והחשבון נוצר מיד אחרי זה. רק בבקשת התשלום (POST),
// לא בעדכוני הטיוטה.
add_action( 'woocommerce_store_api_checkout_update_order_from_request', function ( $order, $request ) {
	if ( $request instanceof WP_REST_Request && 'POST' === $request->get_method() && coweb_tp_checkout_uses_gateway( $order->get_payment_method() ) ) {
		coweb_tp_open_side_context( 'checkout', $order );
	}
}, PHP_INT_MAX, 2 );

add_action( 'woocommerce_store_api_checkout_order_processed', function ( $order ) {
	coweb_tp_close_side_context( 'checkout', $order );
}, PHP_INT_MAX );

// שינוי מלאי של הזמנת בדיקה. ה-filter רץ בתחילת wc_reduce_stock_levels() / wc_increase_stock_levels(),
// וה-action בסוף, אחרי ההתראות.
foreach ( array( 'reduce', 'restore' ) as $coweb_tp_stock_change ) {
	add_filter( "woocommerce_can_{$coweb_tp_stock_change}_order_stock", function ( $can, $order ) {
		if ( $can && coweb_tp_is_test_order( $order ) ) {
			coweb_tp_open_side_context( 'stock', $order );
		}

		return $can;
	}, PHP_INT_MAX, 2 );

	add_action( "woocommerce_{$coweb_tp_stock_change}_order_stock", function () {
		coweb_tp_close_side_context( 'stock' );
	}, PHP_INT_MAX );
}
unset( $coweb_tp_stock_change );

// wp_mail שהוחלף על ידי תוסף אחר לא בהכרח מפעיל את pre_wp_mail, ואז אי אפשר לעצור התראת מלאי שנשלחת ישירות ב-wp_mail.
// במקרה הזה עוצרים אותה לפני שהיא נבנית (בלי רישום בתוסף התיעוד), כמו ש-1.2.0 לא קורא ל-wp_mail כזה בכלל.
foreach ( array( 'low_stock' => 'מלאי נמוך', 'no_stock' => 'אזל מהמלאי', 'backorder' => 'הזמנה מראש' ) as $coweb_tp_notification => $coweb_tp_label ) {
	add_filter( "woocommerce_should_send_{$coweb_tp_notification}_notification", function ( $send ) use ( $coweb_tp_label ) {
		if ( $send && coweb_tp_in_side_context() && ! coweb_tp_wp_mail_is_core() ) {
			coweb_tp_note_side_email( 'התראת מלאי: ' . $coweb_tp_label, get_option( 'woocommerce_stock_email_recipient' ) );

			return false;
		}

		return $send;
	}, PHP_INT_MAX );
}
unset( $coweb_tp_notification, $coweb_tp_label );
