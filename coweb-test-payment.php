<?php
/**
 * Plugin Name: Coweb Test Payment
 * Description: שער תשלום מדומה לבדיקות. מסמן הזמנה כשולמה בלי לפנות לשום סליקה חיצונית. מקומי: פתוח לכולם. staging: מנהלים בלבד. live: כבוי עד שמנהל מפעיל אותו ל-60 דקות, וגם אז רק למנהלים.
 * Version:     1.2.0
 * Author:      coweb
 * Requires Plugins: woocommerce
 * Update URI:  false
 */

defined( 'ABSPATH' ) || exit;

define( 'COWEB_TP_VERSION', '1.2.0' );
define( 'COWEB_TP_FILE', __FILE__ );
define( 'COWEB_TP_ID', 'coweb_test_payment' );
define( 'COWEB_TP_TEST_ORDER_META', '_coweb_test_order' );
define( 'COWEB_TP_ARMED_OPTION', 'coweb_test_payment_armed_until' );
define( 'COWEB_TP_BLOCK_EMAILS_OPTION', 'coweb_tp_block_emails' );
define( 'COWEB_TP_PAGE', 'coweb-test-payment' );
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
 * האם מותר לחייב חידוש חודשי מדומה. החיוב רץ ברקע, בלי משתמש מחובר, ולכן הכלל לפי סביבה בלבד:
 * מקומי ו-staging מותר, live אף פעם. כך מנוי שנפתח בבדיקה באתר חי לא יסומן כשולם בלי כסף.
 */
function coweb_tp_can_charge_renewal() {
	return in_array( coweb_tp_environment(), array( 'local', 'staging' ), true );
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

function coweb_tp_page_url() {
	return admin_url( 'admin.php?page=' . COWEB_TP_PAGE );
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
 * האם לחסום מיילים אמיתיים בהזמנות בדיקה. ההגדרה בעמוד WooCommerce ← תשלום בדיקה.
 * עד 1.2.0 זו הייתה תיבת "מיילים" במסך השער (ברירת מחדל: לא לשלוח). כל עוד לא נשמרה ההגדרה החדשה,
 * היא נגזרת מהישנה: מי שסימן "שלח מיילים" ממשיך לשלוח, כל השאר חסומים.
 */
function coweb_tp_blocks_emails() {
	$value = get_option( COWEB_TP_BLOCK_EMAILS_OPTION, null );

	if ( null === $value ) {
		return 'yes' !== coweb_tp_setting( 'send_emails', 'no' );
	}

	return 'yes' === $value;
}

/**
 * חסימת מיילים של WooCommerce בהזמנות בדיקה. WooCommerce בונה את המייל כרגיל, והמייל עובר דרך wp_mail
 * כדי שתוספי תיעוד (למשל CoWeb Outgoing Mail Archive) יראו אותו. pre_wp_mail עוצר אותו לפני השליחה,
 * כך ששום שרת דואר לא מקבל אותו.
 */
add_filter( 'woocommerce_mail_callback', function ( $callback, $email ) {
	if ( ! $email instanceof WC_Email || ! coweb_tp_is_test_order( $email->object ) || ! coweb_tp_blocks_emails() ) {
		return $callback;
	}

	return function ( ...$args ) use ( $email ) {
		return coweb_tp_send_blocked_email( $email, $args );
	};
}, PHP_INT_MAX, 2 );

function coweb_tp_send_blocked_email( WC_Email $email, array $args ) {
	$to = $args[0] ?? '';

	$email->object->add_order_note(
		sprintf( 'המייל "%s" אל %s לא נשלח: זו הזמנת בדיקה, ומיילים אמיתיים בהזמנות בדיקה חסומים.', $email->get_title(), is_array( $to ) ? implode( ', ', $to ) : $to )
	);

	// wp_mail שהוחלף על ידי תוסף אחר לא בהכרח מפעיל את pre_wp_mail, ואז אי אפשר לעצור אותו. לא קוראים לו בכלל.
	if ( ! coweb_tp_wp_mail_is_core() ) {
		return coweb_tp_blocked_result( $email, true );
	}

	coweb_tp_mail_blocking( true );

	try {
		return coweb_tp_blocked_result( $email, (bool) wp_mail( ...$args ) );
	} finally {
		coweb_tp_mail_blocking( false );
	}
}

/**
 * זוכר את התוצאה של מייל שנחסם עד ש-WooCommerce מסיים לטפל בו. בלי ערך: מחזיר את התוצאה ושוכח אותה.
 */
function coweb_tp_blocked_result( WC_Email $email, $result = null ) {
	static $results = array();

	$key = spl_object_id( $email );

	if ( null !== $result ) {
		$results[ $key ] = $result;

		return $result;
	}

	$stored = $results[ $key ] ?? null;
	unset( $results[ $key ] );

	return $stored;
}

// WooCommerce מוסיף להזמנה "האימייל נשלח", וזה סותר את ההערה שלנו על מייל שנחסם. הערת כישלון (רשת הביטחון) נשארת.
add_filter( 'woocommerce_email_log_add_order_note', function ( $enabled, $email_id, $email ) {
	return $email instanceof WC_Email && true === coweb_tp_blocked_result( $email ) ? false : $enabled;
}, 10, 3 );

add_action( 'woocommerce_email_sent', function ( $success, $email_id, $email ) {
	if ( $email instanceof WC_Email ) {
		coweb_tp_blocked_result( $email );
	}
}, PHP_INT_MAX, 3 );

function coweb_tp_mail_blocking( $set = null ) {
	static $blocking = false;

	if ( null !== $set ) {
		$blocking = (bool) $set;
	}

	return $blocking;
}

function coweb_tp_wp_mail_is_core() {
	$file = ( new ReflectionFunction( 'wp_mail' ) )->getFileName();

	return wp_normalize_path( (string) realpath( $file ) ) === wp_normalize_path( (string) realpath( ABSPATH . WPINC . '/pluggable.php' ) );
}

// ראשון בתור: מחזיר true ו-wp_mail חוזר בלי לשלוח. תוספי תיעוד שרצים אחריו רושמים את הניסיון.
add_filter( 'pre_wp_mail', function ( $return ) {
	return coweb_tp_mail_blocking() ? true : $return;
}, PHP_INT_MIN );

// רשת ביטחון: אם תוסף אחר ביטל את העצירה, בלי נמענים PHPMailer נכשל עוד לפני שהוא מתחבר לשרת.
add_action( 'phpmailer_init', function ( $phpmailer ) {
	if ( coweb_tp_mail_blocking() ) {
		$phpmailer->clearAllRecipients();
	}
}, PHP_INT_MAX );

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

	// חוזרים למסך שממנו לחצו: מסך השער או עמוד ההגדרות.
	wp_safe_redirect( wp_get_referer() ?: coweb_tp_settings_url() );
	exit;
} );

add_action( 'admin_post_coweb_test_payment_disarm', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'אין לך הרשאה לפעולה הזו.', '', array( 'response' => 403 ) );
	}

	check_admin_referer( 'coweb_test_payment_disarm' );

	delete_option( COWEB_TP_ARMED_OPTION );

	wp_safe_redirect( wp_get_referer() ?: coweb_tp_settings_url() );
	exit;
} );

/**
 * מצב השער לפי הסביבה, עם כפתורי ההפעלה באתר חי. מוצג במסך השער ובעמוד ההגדרות.
 */
function coweb_tp_render_status() {
	$environment = coweb_tp_environment();

	if ( 'local' === $environment ) {
		$access = 'השער פתוח לכל מבקר.';
	} elseif ( 'staging' === $environment ) {
		$access = 'yes' === coweb_tp_setting( 'staging_public', 'no' )
			? 'השער פתוח כרגע לכל מבקר (בדיקות לקוח).'
			: 'השער פתוח רק למשתמש מחובר עם הרשאת ניהול חנות.';
	} else {
		$access = 'השער כבוי עד שמנהל מפעיל אותו ל-60 דקות, וגם אז רק משתמשים עם הרשאת ניהול חנות רואים אותו.';
	}

	echo '<div class="notice notice-info inline"><p><strong>סביבה שזוהתה: ' . esc_html( $environment ) . '.</strong> ' . esc_html( $access ) . '</p>';

	if ( 'yes' !== coweb_tp_setting( 'enabled', 'yes' ) ) {
		echo '<p><strong>השער כבוי בהגדרות השער.</strong></p>';
	}

	if ( 'live' === $environment ) {
		echo '<p><strong>מצב: ' . esc_html( coweb_tp_is_armed() ? 'מופעל, ' . coweb_tp_armed_label() : 'כבוי' ) . '.</strong></p>';
		echo '<p>';
		printf( '<a class="button button-primary" href="%s">הפעל ל-60 דקות</a> ', esc_url( coweb_tp_action_url( 'coweb_test_payment_arm' ) ) );
		printf( '<a class="button" href="%s">כבה</a>', esc_url( coweb_tp_action_url( 'coweb_test_payment_disarm' ) ) );
		echo '</p>';
	}

	echo '</div>';
}

/**
 * עמוד ההגדרות: WooCommerce ← תשלום בדיקה.
 */
add_action( 'admin_menu', function () {
	add_submenu_page( 'woocommerce', 'Coweb Test Payment', 'תשלום בדיקה', 'manage_woocommerce', COWEB_TP_PAGE, 'coweb_tp_render_settings_page' );
}, 60 );

add_action( 'admin_init', function () {
	register_setting(
		'coweb_test_payment',
		COWEB_TP_BLOCK_EMAILS_OPTION,
		array(
			'type'              => 'string',
			'default'           => 'yes',
			'sanitize_callback' => function ( $value ) {
				return 'yes' === $value ? 'yes' : 'no';
			},
		)
	);
} );

// options.php דורש manage_options כברירת מחדל. גם מנהל חנות צריך לשמור את העמוד.
add_filter( 'option_page_capability_coweb_test_payment', function () {
	return 'manage_woocommerce';
} );

add_filter( 'plugin_action_links_' . plugin_basename( COWEB_TP_FILE ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( coweb_tp_page_url() ) . '">הגדרות</a>' );

	return $links;
} );

function coweb_tp_render_settings_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	echo '<div class="wrap"><h1>Coweb Test Payment</h1>';
	settings_errors();
	coweb_tp_render_status();

	echo '<form method="post" action="options.php">';
	settings_fields( 'coweb_test_payment' );
	echo '<table class="form-table" role="presentation"><tr><th scope="row">מיילים בהזמנות בדיקה</th><td>';
	printf(
		'<label><input type="checkbox" name="%s" value="yes"%s> חסום מיילים אמיתיים בהזמנות בדיקה</label>',
		esc_attr( COWEB_TP_BLOCK_EMAILS_OPTION ),
		checked( coweb_tp_blocks_emails(), true, false )
	);
	echo '<p class="description">הזמנת בדיקה לא תשלח מיילים אמיתיים, לא ללקוח ולא לחנות. זה חשוב במיוחד ב-staging וב-live, שבהם השער יכול להיות פתוח.</p>';
	echo '<p class="description">WooCommerce בונה את המיילים כרגיל, והם עוברים דרך wp_mail ונעצרים לפני השליחה. כך תוסף תיעוד כמו CoWeb Outgoing Mail Archive רושם אותם, ובהזמנה נרשמת הערה על כל מייל שנחסם.</p>';
	echo '<p class="description">אם לא תסמן, המיילים יגיעו לנמענים האמיתיים של החנות והלקוח.</p>';
	echo '</td></tr></table>';
	submit_button();
	echo '</form>';

	printf(
		'<p>שאר ההגדרות של השער (הפעלה, כותרת, תוצאת הסליקה, החיוב החודשי, סטטוס הזמנה, בדיקות לקוח ב-staging) נמצאות ב-<a href="%s">WooCommerce ← הגדרות ← תשלומים ← Coweb Test Payment</a>.</p>',
		esc_url( coweb_tp_settings_url() )
	);
	echo '</div>';
}

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
