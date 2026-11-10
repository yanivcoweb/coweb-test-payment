<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woocommerce_coweb_test_payment_settings' );
delete_option( 'coweb_test_payment_armed_until' );
delete_option( 'coweb_tp_block_emails' );
