<?php
/**
 * Plugin Name: Coweb Test Payment
 * Description: שער תשלום מדומה לבדיקות. מסמן הזמנה כשולמה בלי לפנות לשום סליקה חיצונית. מקומי: פתוח לכולם. staging: מנהלים בלבד. live: כבוי עד שמנהל מפעיל אותו ל-60 דקות, וגם אז רק למנהלים.
 * Version:     1.3.0
 * Author:      coweb
 * Requires Plugins: woocommerce
 * Update URI:  false
 */

defined( 'ABSPATH' ) || exit;

define( 'COWEB_TP_VERSION', '1.3.0' );
define( 'COWEB_TP_FILE', __FILE__ );
define( 'COWEB_TP_ID', 'coweb_test_payment' );
define( 'COWEB_TP_TEST_ORDER_META', '_coweb_test_order' );
define( 'COWEB_TP_ARMED_OPTION', 'coweb_test_payment_armed_until' );
define( 'COWEB_TP_BLOCK_EMAILS_OPTION', 'coweb_tp_block_emails' );
define( 'COWEB_TP_BLOCK_SIDE_EMAILS_OPTION', 'coweb_tp_block_side_emails' );
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

function coweb_tp_recipients_label( $to ) {
	return is_array( $to ) ? implode( ', ', $to ) : (string) $to;
}

function coweb_tp_is_enabled() {
	return 'yes' === coweb_tp_setting( 'enabled', 'yes' );
}

/**
 * אובייקט השער מתוך WooCommerce, או null אם WooCommerce לא טען אותו.
 */
function coweb_tp_gateway() {
	if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
		return null;
	}

	$gateways = WC()->payment_gateways()->payment_gateways();

	return $gateways[ COWEB_TP_ID ] ?? null;
}

function coweb_tp_page_url( $args = array() ) {
	return add_query_arg( $args, admin_url( 'admin.php?page=' . COWEB_TP_PAGE ) );
}

function coweb_tp_action_url( $action ) {
	return wp_nonce_url( admin_url( 'admin-post.php?action=' . $action ), $action );
}

/**
 * בדיקת הרשאה ו-nonce לכל פעולה בעמוד ההגדרות.
 */
function coweb_tp_check_request( $action ) {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'אין לך הרשאה לפעולה הזו.', '', array( 'response' => 403 ) );
	}

	check_admin_referer( $action );
}

require_once __DIR__ . '/includes/test-orders.php';
require_once __DIR__ . '/includes/side-emails.php';

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
 * מייל של WooCommerce שלא שייך להזמנת בדיקה (למשל חשבון חדש) נחסם כאן רק אם הוא מייל נלווה, ראו includes/side-emails.php.
 */
add_filter( 'woocommerce_mail_callback', function ( $callback, $email ) {
	if ( ! $email instanceof WC_Email ) {
		return $callback;
	}

	$block = coweb_tp_is_test_order( $email->object ) ? coweb_tp_blocks_emails() : coweb_tp_in_side_context();

	if ( ! $block ) {
		return $callback;
	}

	return function ( ...$args ) use ( $email ) {
		return coweb_tp_send_blocked_email( $email, $args );
	};
}, PHP_INT_MAX, 2 );

function coweb_tp_send_blocked_email( WC_Email $email, array $args ) {
	$to = $args[0] ?? '';

	if ( coweb_tp_is_test_order( $email->object ) ) {
		$email->object->add_order_note(
			sprintf( 'המייל "%s" אל %s לא נשלח: זו הזמנת בדיקה, ומיילים אמיתיים בהזמנות בדיקה חסומים.', $email->get_title(), coweb_tp_recipients_label( $to ) )
		);
	} else {
		coweb_tp_note_side_email( $email->get_title(), $to );
	}

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
// מייל שנשלח ישירות ב-wp_mail בתוך הקשר של הזמנת בדיקה (למשל התראת מלאי) נעצר כאן ונרשם בהזמנה.
add_filter( 'pre_wp_mail', function ( $return, $atts ) {
	if ( coweb_tp_mail_blocking() ) {
		return true;
	}

	if ( coweb_tp_in_side_context() ) {
		coweb_tp_note_side_email( $atts['subject'] ?? '', $atts['to'] ?? '' );

		return true;
	}

	return $return;
}, PHP_INT_MIN, 2 );

// רשת ביטחון: אם תוסף אחר ביטל את העצירה, בלי נמענים PHPMailer נכשל עוד לפני שהוא מתחבר לשרת.
add_action( 'phpmailer_init', function ( $phpmailer ) {
	if ( coweb_tp_mail_blocking() || coweb_tp_in_side_context() ) {
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
 * הפעלה זמנית באתר חי: כפתורי "הפעל ל-60 דקות" ו"כבה" בעמוד ההגדרות, ו"כבה עכשיו" בבאנר.
 */
add_action( 'admin_post_coweb_test_payment_arm', function () {
	coweb_tp_check_request( 'coweb_test_payment_arm' );

	if ( 'live' === coweb_tp_environment() ) {
		update_option( COWEB_TP_ARMED_OPTION, time() + HOUR_IN_SECONDS, false );
	}

	// חוזרים למסך שממנו לחצו.
	wp_safe_redirect( wp_get_referer() ?: coweb_tp_page_url() );
	exit;
} );

add_action( 'admin_post_coweb_test_payment_disarm', function () {
	coweb_tp_check_request( 'coweb_test_payment_disarm' );

	delete_option( COWEB_TP_ARMED_OPTION );

	wp_safe_redirect( wp_get_referer() ?: coweb_tp_page_url() );
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

	if ( ! coweb_tp_is_enabled() ) {
		echo '<p><strong>השער כבוי: התיבה "הפעל את שער הבדיקות" בהמשך העמוד לא מסומנת.</strong></p>';
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
 * עמוד ההגדרות: WooCommerce ← תשלום בדיקה. מאז 1.3.0 כל ההגדרות של השער נמצאות כאן, יחד עם רשימת הזמנות הבדיקה.
 */
add_action( 'admin_menu', function () {
	add_submenu_page( 'woocommerce', 'Coweb Test Payment', 'תשלום בדיקה', 'manage_woocommerce', COWEB_TP_PAGE, 'coweb_tp_render_settings_page' );
}, 60 );

add_filter( 'plugin_action_links_' . plugin_basename( COWEB_TP_FILE ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( coweb_tp_page_url() ) . '">הגדרות</a>' );

	return $links;
} );

/**
 * המסך הישן של השער (WooCommerce ← הגדרות ← תשלומים ← Coweb Test Payment) מפנה לעמוד ההגדרות.
 * מסך התשלומים החדש של WooCommerce מגיע לעמוד ישירות דרך get_settings_url() של השער.
 */
add_action( 'admin_init', function () {
	global $pagenow;

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- ניתוב בלבד, בלי שינוי נתונים.
	$section = strtolower( sanitize_text_field( wp_unslash( $_GET['section'] ?? '' ) ) );

	if (
		'admin.php' !== $pagenow
		|| 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' )
		|| 'wc-settings' !== ( $_GET['page'] ?? '' )
		|| 'checkout' !== ( $_GET['tab'] ?? '' )
		|| ! in_array( $section, array( COWEB_TP_ID, 'wc_gateway_coweb_test_payment' ), true )
		|| ! current_user_can( 'manage_woocommerce' )
	) {
		return;
	}
	// phpcs:enable

	wp_safe_redirect( coweb_tp_page_url() );
	exit;
} );

/**
 * שמירת עמוד ההגדרות. הגדרות השער נשמרות דרך השער עצמו, ב-woocommerce_coweb_test_payment_settings,
 * בדיוק כמו במסך של WooCommerce, כך ש-WooCommerce, הבלוקים ו-coweb_tp_setting() ממשיכים לקרוא אותן משם.
 */
add_action( 'admin_post_coweb_tp_save_settings', function () {
	coweb_tp_check_request( 'coweb_tp_save_settings' );

	$gateway = coweb_tp_gateway();

	if ( $gateway ) {
		$gateway->process_admin_options();
	}

	foreach ( array( COWEB_TP_BLOCK_EMAILS_OPTION, COWEB_TP_BLOCK_SIDE_EMAILS_OPTION ) as $option ) {
		update_option( $option, empty( $_POST[ $option ] ) ? 'no' : 'yes' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- נבדק ב-coweb_tp_check_request().
	}

	wp_safe_redirect( coweb_tp_page_url( array( 'coweb_tp_saved' => 1 ) ) );
	exit;
} );

function coweb_tp_render_settings_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	echo '<div class="wrap coweb-tp"><h1>Coweb Test Payment</h1>';
	echo '<style>.coweb-tp .form-table .input-text, .coweb-tp .form-table textarea { width: 30em; max-width: 100%; } .coweb-tp .coweb-tp-orders { max-width: 60em; }</style>';

	coweb_tp_render_page_notices();
	coweb_tp_render_status();

	// שלב האישור של מחיקת הזמנות בדיקה מחליף את העמוד, כדי שלא ילחצו בטעות על משהו אחר.
	if ( coweb_tp_delete_confirmation_requested() ) {
		coweb_tp_render_delete_confirmation();
		echo '</div>';

		return;
	}

	echo '<div class="notice notice-warning inline"><p><strong>חשוב לדעת:</strong> השער מדלג רק על הסליקה. הפחתת מלאי, נתוני Analytics ואינטגרציות חשבונית/ERP רצים על הזמנת בדיקה כמו על הזמנה אמיתית. באתר חי כדאי למחוק את הזמנות הבדיקה בסוף הבדיקה (רשימה בתחתית העמוד). המחיקה מחזירה גם את המלאי.</p></div>';

	coweb_tp_render_settings_form();
	coweb_tp_render_test_orders();

	echo '</div>';
}

function coweb_tp_render_page_notices() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- הודעות תוצאה בלבד.
	if ( isset( $_GET['coweb_tp_saved'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>ההגדרות נשמרו.</p></div>';
	}

	if ( isset( $_GET['coweb_tp_trashed'] ) ) {
		$trashed = absint( $_GET['coweb_tp_trashed'] );
		$skipped = absint( $_GET['coweb_tp_skipped'] ?? 0 );

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
			1 === $trashed
				? 'הזמנת בדיקה אחת הועברה לפח, והמלאי שהיא הפחיתה הוחזר.'
				: sprintf( '%d הזמנות בדיקה הועברו לפח, והמלאי שהן הפחיתו הוחזר.', $trashed )
		) . '</p></div>';

		if ( $skipped ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( sprintf( 'דילגתי על %d הזמנות: הן לא הזמנות בדיקה, או שכבר נמחקו.', $skipped ) ) . '</p></div>';
		}
	}
	// phpcs:enable
}

function coweb_tp_render_settings_form() {
	$gateway = coweb_tp_gateway();

	printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
	echo '<input type="hidden" name="action" value="coweb_tp_save_settings">';
	wp_nonce_field( 'coweb_tp_save_settings' );

	echo '<h2>שער התשלום</h2>';

	if ( $gateway ) {
		// אותם שדות ואותה שמירה כמו במסך של WooCommerce. generate_settings_html() מחזיר HTML שכבר עבר escape.
		echo '<table class="form-table" role="presentation">' . $gateway->generate_settings_html( $gateway->get_form_fields(), false ) . '</table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	} else {
		echo '<div class="notice notice-error inline"><p>WooCommerce לא טען את השער, ולכן אי אפשר להציג את ההגדרות שלו.</p></div>';
	}

	echo '<h2>מיילים</h2><table class="form-table" role="presentation"><tr><th scope="row">מיילים בהזמנות בדיקה</th><td>';
	printf(
		'<label><input type="checkbox" name="%s" value="yes"%s> חסום מיילים אמיתיים בהזמנות בדיקה</label>',
		esc_attr( COWEB_TP_BLOCK_EMAILS_OPTION ),
		checked( coweb_tp_blocks_emails(), true, false )
	);
	echo '<p class="description">הזמנת בדיקה לא תשלח מיילים אמיתיים, לא ללקוח ולא לחנות. זה חשוב במיוחד ב-staging וב-live, שבהם השער יכול להיות פתוח.</p>';
	echo '<p class="description">WooCommerce בונה את המיילים כרגיל, והם עוברים דרך wp_mail ונעצרים לפני השליחה. כך תוסף תיעוד כמו CoWeb Outgoing Mail Archive רושם אותם, ובהזמנה נרשמת הערה על כל מייל שנחסם.</p>';
	echo '<p class="description">אם לא תסמן, המיילים יגיעו לנמענים האמיתיים של החנות והלקוח.</p>';
	echo '</td></tr><tr><th scope="row">מיילים נלווים להזמנות בדיקה</th><td>';
	printf(
		'<label><input type="checkbox" name="%s" value="yes"%s> חסום מיילים נלווים להזמנות בדיקה (התראות מלאי, חשבון חדש)</label>',
		esc_attr( COWEB_TP_BLOCK_SIDE_EMAILS_OPTION ),
		checked( coweb_tp_blocks_side_emails(), true, false )
	);
	echo '<p class="description">מיילים ש-WooCommerce שולח בעקבות הזמנת בדיקה אבל לא על ההזמנה עצמה: מייל "חשבון חדש" כשחשבון נפתח בקופה ששולמה בשער הבדיקות, והתראות מלאי (מלאי נמוך, אזל מהמלאי, הזמנה מראש) כשהזמנת בדיקה מפחיתה מלאי או מחזירה אותו.</p>';
	echo '<p class="description">החסימה חלה רק על מיילים שהזמנת בדיקה גרמה להם, בכל הסביבות. לקוח אמיתי שנרשם או מאפס סיסמה בזמן שהשער מופעל יקבל את המייל כרגיל. גם המיילים האלה עוברים דרך wp_mail, נרשמים בתוסף התיעוד, ובהזמנה נרשמת הערה.</p>';
	echo '</td></tr></table>';

	submit_button( 'שמור שינויים' );
	echo '</form>';
}

/**
 * באנר תזכורת בדשבורד, כדי שלא תשכח שהשער דלוק.
 */
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) || ! coweb_tp_is_enabled() ) {
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
	if ( 'live' !== coweb_tp_environment() || ! coweb_tp_is_enabled() || ! coweb_tp_can_use() ) {
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
