<?php
/**
 * רשימת הזמנות הבדיקה ומחיקה שלהן, בעמוד WooCommerce ← תשלום בדיקה (1.3.0).
 *
 * מחיקה = העברה לפח של WooCommerce, אחרי החזרת המלאי שההזמנה הפחיתה. מהפח אפשר לשחזר הזמנה או למחוק אותה לצמיתות,
 * ו-WordPress מרוקן אותו לבד אחרי EMPTY_TRASH_DAYS (ברירת מחדל: 30 יום). הזמנות בפח לא נספרות בדוחות Analytics.
 * לא מוחקים לצמיתות מכאן: פעולה אחת לא הפיכה על הרבה הזמנות באתר חי מסוכנת מדי.
 */

defined( 'ABSPATH' ) || exit;

define( 'COWEB_TP_ORDERS_PER_PAGE', 50 );

/**
 * השאילתה של הזמנות הבדיקה. דרך wc_get_orders, כך שהיא עובדת גם עם HPOS וגם עם טבלת ה-posts.
 * אותם תנאים כמו coweb_tp_is_test_order(): המטא וגם אמצעי התשלום. הפח לא נכלל.
 */
function coweb_tp_test_orders_query( $args = array() ) {
	return wc_get_orders(
		array_merge(
			array(
				'type'           => 'shop_order',
				'status'         => array_keys( wc_get_order_statuses() ),
				'payment_method' => COWEB_TP_ID,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => COWEB_TP_TEST_ORDER_META,
						'value' => 'yes',
					),
				),
				'orderby'        => 'date',
				'order'          => 'DESC',
			),
			$args
		)
	);
}

/**
 * מזהי כל הזמנות הבדיקה.
 */
function coweb_tp_test_order_ids() {
	return array_map( 'absint', coweb_tp_test_orders_query( array( 'limit' => -1, 'return' => 'ids' ) ) );
}

/**
 * מעביר לפח הזמנות בדיקה, אחרי שהמלאי שלהן הוחזר. הזמנה ש-coweb_tp_is_test_order() לא מאשר לא נמחקת.
 *
 * @param int[] $ids מזהי הזמנות.
 * @return array{trashed:int, skipped:int}
 */
function coweb_tp_trash_test_orders( array $ids ) {
	$result = array(
		'trashed' => 0,
		'skipped' => 0,
	);

	wc_set_time_limit( 0 );

	foreach ( array_unique( array_filter( array_map( 'absint', $ids ) ) ) as $id ) {
		if ( ! coweb_tp_is_test_order( wc_get_order( $id ) ) ) {
			++$result['skipped'];
			continue;
		}

		// כמו בביטול הזמנה: מחזיר רק מלאי שההזמנה הפחיתה ועוד לא הוחזר, ורושם הערה "רמות המלאי הוגדלו".
		// התראות מלאי שהחזרת המלאי גורמת להן הן מיילים נלווים להזמנת בדיקה, ראו side-emails.php.
		wc_maybe_increase_stock_levels( $id );

		// טוענים מחדש: wc_maybe_increase_stock_levels() עבד על עותק אחר של ההזמנה.
		$order = wc_get_order( $id );
		$order->add_order_note( 'הזמנת הבדיקה הועברה לפח מעמוד תשלום בדיקה. ' . coweb_tp_audit_label() );
		$order->delete( false );

		++$result['trashed'];
	}

	return $result;
}

add_action( 'admin_post_coweb_tp_delete_orders', function () {
	coweb_tp_check_request( 'coweb_tp_delete_orders' );

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- נבדק ב-coweb_tp_check_request().
	$result = coweb_tp_trash_test_orders( array_map( 'absint', (array) wp_unslash( $_POST['order_ids'] ?? array() ) ) );

	wp_safe_redirect(
		coweb_tp_page_url(
			array(
				'coweb_tp_trashed' => $result['trashed'],
				'coweb_tp_skipped' => $result['skipped'],
			)
		) . '#coweb-tp-orders'
	);
	exit;
} );

function coweb_tp_delete_confirmation_requested() {
	return isset( $_GET['coweb_tp_confirm_delete'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- תצוגה בלבד, המחיקה עצמה בודקת nonce.
}

/**
 * השורות של טבלת ההזמנות: מספר עם קישור להזמנה, תאריך, סטטוס וסכום.
 *
 * @param WC_Order[] $orders     הזמנות.
 * @param bool       $checkboxes עם תיבת סימון לכל הזמנה.
 */
function coweb_tp_render_orders_table( array $orders, $checkboxes ) {
	echo '<table class="wp-list-table widefat striped coweb-tp-orders"><thead><tr>';

	if ( $checkboxes ) {
		echo '<td class="manage-column column-cb check-column"><input type="checkbox" aria-label="בחר הכול"></td>';
	}

	echo '<th scope="col">הזמנה</th><th scope="col">תאריך</th><th scope="col">סטטוס</th><th scope="col">סכום</th></tr></thead><tbody>';

	foreach ( $orders as $order ) {
		$date = $order->get_date_created();

		echo '<tr>';

		if ( $checkboxes ) {
			printf(
				'<th scope="row" class="check-column"><input type="checkbox" name="order_ids[]" value="%1$d" aria-label="בחר את הזמנה #%2$s"></th>',
				absint( $order->get_id() ),
				esc_attr( $order->get_order_number() )
			);
		}

		printf(
			'<td><a href="%s">#%s</a></td><td>%s</td><td>%s</td><td>%s</td>',
			esc_url( $order->get_edit_order_url() ),
			esc_html( $order->get_order_number() ),
			esc_html( $date ? wc_format_datetime( $date, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '—' ),
			esc_html( wc_get_order_status_name( $order->get_status() ) ),
			wp_kses_post( $order->get_formatted_order_total() )
		);

		echo '</tr>';
	}

	echo '</tbody></table>';
}

/**
 * רשימת הזמנות הבדיקה בתחתית עמוד ההגדרות. הטופס שולח GET לשלב האישור, ושום דבר לא נמחק בלי האישור.
 */
function coweb_tp_render_test_orders() {
	$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$result = coweb_tp_test_orders_query(
		array(
			'limit'    => COWEB_TP_ORDERS_PER_PAGE,
			'page'     => $paged,
			'paginate' => true,
		)
	);
	$orders = array_filter( $result->orders, 'coweb_tp_is_test_order' );

	echo '<h2 id="coweb-tp-orders">הזמנות בדיקה</h2>';

	if ( ! $result->total ) {
		echo '<p>אין הזמנות בדיקה באתר.</p>';

		return;
	}

	echo '<p>' . esc_html(
		sprintf(
			'%s מחיקה מחזירה את המלאי שההזמנה הפחיתה ומעבירה אותה לפח של WooCommerce. מהפח אפשר לשחזר אותה או למחוק אותה לצמיתות, ו-WordPress מרוקן אותו לבד אחרי %d ימים.',
			1 === (int) $result->total ? 'יש באתר הזמנת בדיקה אחת.' : sprintf( 'יש באתר %d הזמנות בדיקה.', $result->total ),
			EMPTY_TRASH_DAYS
		)
	) . '</p>';

	printf( '<form method="get" action="%s">', esc_url( admin_url( 'admin.php' ) ) );
	printf( '<input type="hidden" name="page" value="%s"><input type="hidden" name="coweb_tp_confirm_delete" value="1">', esc_attr( COWEB_TP_PAGE ) );

	coweb_tp_render_orders_table( $orders, true );

	echo '<p><button type="submit" class="button" name="scope" value="selected">מחק את ההזמנות שנבחרו</button> ';
	printf( '<button type="submit" class="button" name="scope" value="all">מחק את כל הזמנות הבדיקה (%d)</button></p>', absint( $result->total ) );
	echo '</form>';

	if ( $result->max_num_pages > 1 ) {
		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo wp_kses_post(
			paginate_links(
				array(
					'base'    => add_query_arg( 'paged', '%#%', coweb_tp_page_url() ) . '#coweb-tp-orders',
					'format'  => '',
					'current' => $paged,
					'total'   => $result->max_num_pages,
				)
			)
		);
		echo '</div></div>';
	}
}

/**
 * שלב האישור: מציג בדיוק את ההזמנות שיימחקו. הכפתור שולח POST עם nonce, והמזהים נשלחים כמו שהוצגו,
 * כך שהזמנת בדיקה חדשה שנוצרה בינתיים לא נמחקת בלי שראו אותה.
 */
function coweb_tp_render_delete_confirmation() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- תצוגה בלבד, המחיקה עצמה בודקת nonce.
	$ids = 'all' === sanitize_key( wp_unslash( $_GET['scope'] ?? '' ) )
		? coweb_tp_test_order_ids()
		: array_map( 'absint', (array) wp_unslash( $_GET['order_ids'] ?? array() ) );
	// phpcs:enable

	$orders = array_filter( array_map( 'wc_get_order', array_unique( array_filter( $ids ) ) ), 'coweb_tp_is_test_order' );

	echo '<h2 id="coweb-tp-orders">מחיקת הזמנות בדיקה</h2>';

	if ( ! $orders ) {
		printf( '<div class="notice notice-warning inline"><p>לא נבחרו הזמנות בדיקה. <a href="%s">חזרה לעמוד</a></p></div>', esc_url( coweb_tp_page_url() . '#coweb-tp-orders' ) );

		return;
	}

	$count = count( $orders );

	echo '<div class="notice notice-warning inline"><p><strong>' . esc_html( 1 === $count ? 'להעביר לפח את הזמנת הבדיקה הזו?' : sprintf( 'להעביר לפח את %d הזמנות הבדיקה האלה?', $count ) ) . '</strong></p>';
	echo '<p>המלאי שהן הפחיתו יוחזר, ובכל הזמנה תירשם הערה. מהפח של WooCommerce אפשר לשחזר אותן או למחוק אותן לצמיתות.</p></div>';

	coweb_tp_render_orders_table( $orders, false );

	printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
	echo '<input type="hidden" name="action" value="coweb_tp_delete_orders">';
	wp_nonce_field( 'coweb_tp_delete_orders' );

	foreach ( $orders as $order ) {
		printf( '<input type="hidden" name="order_ids[]" value="%d">', absint( $order->get_id() ) );
	}

	echo '<p><button type="submit" class="button button-primary">כן, העבר לפח</button> ';
	printf( '<a class="button" href="%s">ביטול</a></p>', esc_url( coweb_tp_page_url() . '#coweb-tp-orders' ) );
	echo '</form>';
}
