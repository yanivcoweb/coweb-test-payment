<?php
/**
 * Plugin Name: Coweb Test Payment
 * Description: שער תשלום מדומה לבדיקות. מסמן הזמנה כשולמה בלי לפנות לשום סליקה חיצונית. מקומי: פתוח לכולם. staging: מנהלים בלבד. live: כבוי עד שמנהל מפעיל אותו ל-60 דקות, וגם אז רק למנהלים.
 * Version:     1.0.0
 * Author:      coweb
 * Requires Plugins: woocommerce
 * Update URI:  false
 */

defined( 'ABSPATH' ) || exit;

define( 'COWEB_TP_VERSION', '1.0.0' );
define( 'COWEB_TP_FILE', __FILE__ );
define( 'COWEB_TP_ID', 'coweb_test_payment' );
define( 'COWEB_TP_TEST_ORDER_META', '_coweb_test_order' );
define( 'COWEB_TP_ARMED_OPTION', 'coweb_test_payment_armed_until' );
define( 'COWEB_TP_OLD_PLUGIN', 'local-test-payment/local-test-payment.php' );

register_activation_hook( __FILE__, 'coweb_tp_activate' );

// התוסף הישן עדיין פעיל: לא נטענים, כדי שלא יהיו שני שערי בדיקה במקביל.
if ( in_array( COWEB_TP_OLD_PLUGIN, (array) get_option( 'active_plugins', array() ), true ) ) {
	add_action( 'admin_notices', function () {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>Coweb Test Payment לא נטען</strong> — התוסף הישן Local Test Payment עדיין פעיל. כבה אותו, או כבה והפעל מחדש את Coweb Test Payment והוא יעביר את ההגדרות ויכבה את הישן.</p></div>';
	} );

	return;
}

/**
 * מזהה את הסביבה: local / staging / live.
 * הדומיין קובע ראשון: דומיין ציבורי לעולם לא ייחשב local, גם אם WP_ENVIRONMENT_TYPE טוען אחרת.
 */
function coweb_tp_environment() {
	$host     = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$declared = defined( 'WP_ENVIRONMENT_TYPE' ) && WP_ENVIRONMENT_TYPE ? WP_ENVIRONMENT_TYPE : getenv( 'WP_ENVIRONMENT_TYPE' );

	if ( preg_match( '/(\.local|\.test|\.localhost|\.localsite\.io|^localhost|^127\.0\.0\.1)$/', $host ) ) {
		$environment = 'local';
	} elseif ( 'production' === $declared ) {
		// wp_get_environment_type() מחזיר production גם כשלא הוגדר כלום, לכן בודקים את ההגדרה עצמה.
		$environment = 'live';
	} elseif ( in_array( wp_get_environment_type(), array( 'staging', 'development' ), true ) ) {
		$environment = 'staging';
	} elseif ( preg_match( '/^(staging|stage|dev|test|qa)\.[^.]+\.[^.]+|\.cloudwaysapps\.com$/', $host ) ) {
		$environment = 'staging';
	} else {
		$environment = 'live';
	}

	$environment = apply_filters( 'coweb_test_payment_environment', $environment, $host );

	// ערך לא מוכר מהפילטר נחשב live, האפשרות המחמירה ביותר.
	return in_array( $environment, array( 'local', 'staging', 'live' ), true ) ? $environment : 'live';
}

function coweb_tp_setting( $key, $default ) {
	$settings = get_option( 'woocommerce_' . COWEB_TP_ID . '_settings', array() );

	return $settings[ $key ] ?? $default;
}

/**
 * התפוגה היא השוואת זמן בלבד, בלי cron: אחרי ה-timestamp השער פשוט לא נפתח.
 */
function coweb_tp_is_armed() {
	return time() < (int) get_option( COWEB_TP_ARMED_OPTION, 0 );
}

function coweb_tp_armed_label() {
	$until = (int) get_option( COWEB_TP_ARMED_OPTION, 0 );

	return sprintf( 'נותרו %d דקות (עד %s)', (int) ceil( ( $until - time() ) / MINUTE_IN_SECONDS ), wp_date( 'H:i', $until ) );
}

/**
 * מי רשאי להשתמש בשער בבקשה הנוכחית. זה השומר היחיד: תצוגה, בלוקים ו-process_payment עוברים כולם דרכו.
 */
function coweb_tp_can_use() {
	switch ( coweb_tp_environment() ) {
		case 'local':
			return true;

		case 'staging':
			return current_user_can( 'manage_woocommerce' ) || 'yes' === coweb_tp_setting( 'staging_public', 'no' );

		default:
			return coweb_tp_is_armed() && current_user_can( 'manage_woocommerce' );
	}
}

/**
 * הזמנת בדיקה היא הזמנה שסומנה וגם עדיין משויכת לשער הזה.
 * אם ניסיון שנכשל חזר ושולם בסליקה אמיתית, המטא נשאר אבל ההזמנה כבר לא הזמנת בדיקה.
 */
function coweb_tp_is_test_order( $order ) {
	return $order instanceof WC_Order
		&& 'yes' === $order->get_meta( COWEB_TP_TEST_ORDER_META )
		&& COWEB_TP_ID === $order->get_payment_method();
}

function coweb_tp_audit_label() {
	$user = wp_get_current_user();
	$who  = $user->exists() ? sprintf( '%s (#%d)', $user->user_login, $user->ID ) : 'אורח';

	return sprintf( 'סביבה: %s. משתמש: %s.', coweb_tp_environment(), $who );
}

function coweb_tp_settings_url() {
	return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . COWEB_TP_ID );
}

function coweb_tp_action_url( $action ) {
	return wp_nonce_url( admin_url( 'admin-post.php?action=' . $action ), $action );
}

add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', COWEB_TP_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', COWEB_TP_FILE, true );
	}
} );

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	require_once __DIR__ . '/includes/class-wc-gateway-coweb-test-payment.php';

	add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
		$gateways[] = 'WC_Gateway_Coweb_Test_Payment';

		return $gateways;
	} );
} );

add_action( 'woocommerce_blocks_loaded', function () {
	if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		return;
	}

	require_once __DIR__ . '/includes/class-coweb-test-payment-blocks.php';

	add_action( 'woocommerce_blocks_payment_method_type_registration', function ( $registry ) {
		$registry->register( new Coweb_Test_Payment_Blocks() );
	} );
} );

/**
 * חסימת מיילים של WooCommerce על הזמנות בדיקה. המחלקה נטענת רק כשצריך מיילים,
 * לכן נרשמים על woocommerce_email ולא טוענים את WC()->mailer() בכל בקשה.
 */
add_action( 'woocommerce_email', function ( $mailer ) {
	// מייל ההחזר החלקי מחליף את ה-id שלו רק בזמן השליחה, ולכן לא מופיע ברשימה.
	$ids = array( 'customer_partially_refunded_order' );

	foreach ( $mailer->get_emails() as $email ) {
		$ids[] = $email->id;
	}

	foreach ( array_unique( $ids ) as $id ) {
		add_filter( 'woocommerce_email_enabled_' . $id, 'coweb_tp_filter_email_enabled', 10, 2 );
	}
} );

function coweb_tp_filter_email_enabled( $enabled, $object ) {
	if ( $enabled && coweb_tp_is_test_order( $object ) && 'yes' !== coweb_tp_setting( 'send_emails', 'no' ) ) {
		return false;
	}

	return $enabled;
}

/**
 * סימון 🧪 ברשימת ההזמנות בניהול.
 */
add_filter( 'woocommerce_admin_order_buyer_name', function ( $buyer, $order ) {
	return coweb_tp_is_test_order( $order ) ? '🧪 ' . $buyer : $buyer;
}, 10, 2 );

/**
 * הפעלה זמנית באתר חי: כפתורי "הפעל ל-60 דקות" ו"כבה" במסך ההגדרות של השער.
 */
add_action( 'admin_post_coweb_test_payment_arm', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'אין לך הרשאה לפעולה הזו.', '', array( 'response' => 403 ) );
	}

	check_admin_referer( 'coweb_test_payment_arm' );

	if ( 'live' === coweb_tp_environment() ) {
		update_option( COWEB_TP_ARMED_OPTION, time() + HOUR_IN_SECONDS, false );
	}

	wp_safe_redirect( coweb_tp_settings_url() );
	exit;
} );

add_action( 'admin_post_coweb_test_payment_disarm', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'אין לך הרשאה לפעולה הזו.', '', array( 'response' => 403 ) );
	}

	check_admin_referer( 'coweb_test_payment_disarm' );

	delete_option( COWEB_TP_ARMED_OPTION );

	wp_safe_redirect( coweb_tp_settings_url() );
	exit;
} );

/**
 * באנר תזכורת בדשבורד, כדי שלא תשכח שהשער דלוק.
 */
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) || 'yes' !== coweb_tp_setting( 'enabled', 'yes' ) ) {
		return;
	}

	$environment = coweb_tp_environment();

	if ( 'live' === $environment ) {
		if ( coweb_tp_is_armed() ) {
			printf(
				'<div class="notice notice-error"><p><strong>שער הבדיקות מופעל באתר החי</strong> — %s. רק משתמשים עם הרשאת ניהול חנות רואים אותו. <a href="%s">כבה עכשיו</a></p></div>',
				esc_html( coweb_tp_armed_label() ),
				esc_url( coweb_tp_action_url( 'coweb_test_payment_disarm' ) )
			);
		}

		return;
	}

	$scope = 'הזמנות באתר הזה מסומנות כשולמו בלי סליקה אמיתית.';
	if ( 'staging' === $environment ) {
		$scope .= 'yes' === coweb_tp_setting( 'staging_public', 'no' ) ? ' כרגע פתוח לכל מבקר.' : ' רק מנהלים רואים אותו.';
	}

	echo '<div class="notice notice-warning"><p><strong>שער תשלום מדומה פעיל</strong> — ' . esc_html( $scope ) . '</p></div>';
} );

/**
 * באנר למנהל בעמוד התשלום באתר חי, בשני סוגי התשלום: הקלאסי והבלוק.
 */
function coweb_tp_checkout_banner() {
	if ( 'live' !== coweb_tp_environment() || 'yes' !== coweb_tp_setting( 'enabled', 'yes' ) || ! coweb_tp_can_use() ) {
		return '';
	}

	return '<div class="woocommerce-info">שער הבדיקות מופעל באתר החי — ' . esc_html( coweb_tp_armed_label() ) . '. רק מנהלים רואים אותו, והזמנה דרכו לא נסלקת.</div>';
}

add_action( 'woocommerce_before_checkout_form', function () {
	echo coweb_tp_checkout_banner(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the function.
}, 5 );

add_filter( 'render_block_woocommerce/checkout', function ( $content ) {
	return coweb_tp_checkout_banner() . $content;
} );

/**
 * מיגרציה מ-Local Test Payment: מעתיק הגדרות, מעדכן סדר שערים ומכבה את התוסף הישן.
 * בטוח להרצה חוזרת: הגדרות קיימות של השער החדש לא נדרסות.
 */
function coweb_tp_activate() {
	$old_settings = get_option( 'woocommerce_local_test_payment_settings' );

	if ( is_array( $old_settings ) && false === get_option( 'woocommerce_' . COWEB_TP_ID . '_settings' ) ) {
		if ( '🧪 תשלום בדיקה (מקומי)' === ( $old_settings['title'] ?? '' ) ) {
			$old_settings['title'] = '🧪 תשלום בדיקה';
		}

		if ( 'שער בדיקות מקומי. לא מבוצעת סליקה אמיתית ולא נגבה כסף.' === ( $old_settings['description'] ?? '' ) ) {
			$old_settings['description'] = 'שער בדיקות. לא מבוצעת סליקה אמיתית ולא נגבה כסף.';
		}

		update_option( 'woocommerce_' . COWEB_TP_ID . '_settings', $old_settings );
	}

	$gateway_order = get_option( 'woocommerce_gateway_order' );

	if ( is_array( $gateway_order ) && isset( $gateway_order['local_test_payment'] ) ) {
		$gateway_order[ COWEB_TP_ID ] = $gateway_order['local_test_payment'];
		unset( $gateway_order['local_test_payment'] );
		update_option( 'woocommerce_gateway_order', $gateway_order );
	}

	if ( in_array( COWEB_TP_OLD_PLUGIN, (array) get_option( 'active_plugins', array() ), true ) ) {
		deactivate_plugins( COWEB_TP_OLD_PLUGIN );
	}
}
