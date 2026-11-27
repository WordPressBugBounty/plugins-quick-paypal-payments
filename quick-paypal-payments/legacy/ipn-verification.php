<?php
/**
 * PayPal IPN payment verification.
 *
 * Kept in its own file with no side effects at load time so it can be required
 * directly by the test suite without booting the rest of the plugin.
 *
 * @package Quick_Paypal_Payments
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The raw IPN payload as posted by PayPal.
 *
 * Wrapped in a function so the request body can be supplied by a filter. There
 * is no other way to exercise the listener end to end, because php://input
 * cannot be populated from a test.
 *
 * @return string Raw request body.
 */
function qpp_get_raw_ipn_payload() {

	/**
	 * Filters the raw IPN request body before it is parsed.
	 *
	 * @param string $payload Raw request body.
	 */
	return (string) apply_filters( 'qpp_raw_ipn_payload', file_get_contents( 'php://input' ) );
}

/**
 * Verify that an IPN describes a completed payment of the expected amount to
 * the expected PayPal account.
 *
 * A VERIFIED response from PayPal proves only that the message is genuine. It
 * says nothing about who was paid, how much, or whether the payment completed.
 * PayPal Standard lets the buyer control the amount they actually pay, so all
 * of that has to be checked before an order is treated as paid.
 *
 * @param array $ipn      Raw IPN fields as posted by PayPal.
 * @param array $expected Expected payment, from qpp_get_ipn_expectation().
 *
 * @return true|string True when the payment is good, otherwise the reason it was rejected.
 */
function qpp_verify_ipn_payment( $ipn, $expected ) {

	$result = qpp_check_ipn_payment( $ipn, $expected );

	/**
	 * Filters the result of IPN payment verification.
	 *
	 * Return true to accept the payment, or a string explaining why it was
	 * rejected. Useful where a form deliberately accepts an amount other than
	 * the one it asked for, such as an open donation.
	 *
	 * @param true|string $result   True when verified, otherwise the rejection reason.
	 * @param array       $ipn      Raw IPN fields as posted by PayPal.
	 * @param array       $expected Expected payment for this order.
	 */
	return apply_filters( 'qpp_verify_ipn_payment', $result, $ipn, $expected );
}

/**
 * Run the payment checks. See qpp_verify_ipn_payment().
 *
 * @param array $ipn      Raw IPN fields as posted by PayPal.
 * @param array $expected Expected payment for this order.
 *
 * @return true|string True when the payment is good, otherwise the reason it was rejected.
 */
function qpp_check_ipn_payment( $ipn, $expected ) {

	$txn_type = isset( $ipn['txn_type'] ) ? strtolower( trim( $ipn['txn_type'] ) ) : '';

	/*
	 * PayPal reports the account the button was addressed to in business, and
	 * the account's primary address in receiver_email. The two differ when a
	 * merchant takes payment on a secondary address, so either one matching the
	 * configured account is good enough.
	 */
	$receivers = array();

	foreach ( array( 'receiver_email', 'business' ) as $key ) {
		if ( ! empty( $ipn[ $key ] ) ) {
			$receivers[] = strtolower( trim( $ipn[ $key ] ) );
		}
	}

	if ( empty( $receivers ) ) {
		return 'IPN did not identify the receiving PayPal account';
	}

	if ( '' === $expected['receiver'] ) {
		return 'no merchant PayPal account is configured to check the receiver against';
	}

	if ( ! in_array( $expected['receiver'], $receivers, true ) ) {
		return 'payment was received by a different PayPal account from the one this form is configured for';
	}

	/*
	 * Which notice settles an order depends on what the order asked for. A
	 * subscription order is settled by its signup, the only notice that says
	 * what the buyer signed up to, and only when those are the terms the form
	 * offered. PayPal reports the per-period amount in mc_amount3 (amount3 on
	 * older notices). Its payments carry no terms at all, so they never settle
	 * a subscription order. A one-off order is settled by a completed payment,
	 * never by any subscription notice.
	 */
	$terms = isset( $expected['recurring'] ) && is_array( $expected['recurring'] ) ? $expected['recurring'] : null;

	if ( 0 === strpos( $txn_type, 'subscr_' ) && ! in_array( $txn_type, array( 'subscr_signup', 'subscr_payment' ), true ) ) {
		return 'notification type ' . $txn_type . ' is not a payment';
	}

	if ( null !== $terms ) {
		if ( 'subscr_signup' !== $txn_type ) {
			return 'a subscription order is settled by its signup, not by ' . ( '' === $txn_type ? 'a notice with no type' : $txn_type );
		}

		$terms_check = qpp_check_subscription_terms( $ipn, $terms );

		if ( true !== $terms_check ) {
			return $terms_check;
		}

		$amount_field = isset( $ipn['mc_amount3'] ) ? 'mc_amount3' : 'amount3';
	} elseif ( 0 === strpos( $txn_type, 'subscr_' ) ) {
		return 'notification type ' . $txn_type . ' does not settle a one-off order';
	} else {
		$amount_field   = 'mc_gross';
		$payment_status = isset( $ipn['payment_status'] ) ? trim( $ipn['payment_status'] ) : '';

		if ( 'Completed' !== $payment_status ) {
			return 'payment status is ' . ( '' === $payment_status ? 'missing' : $payment_status ) . ', not Completed';
		}
	}

	if ( '' !== $expected['currency'] ) {
		if ( empty( $ipn['mc_currency'] ) ) {
			return 'IPN carried no currency to check against this order';
		}
		if ( strtoupper( trim( $ipn['mc_currency'] ) ) !== $expected['currency'] ) {
			return 'paid in ' . strtoupper( trim( $ipn['mc_currency'] ) ) . ' but this order is in ' . $expected['currency'];
		}
	}

	/*
	 * The expectation holds what the buyer was asked to pay, including an
	 * amount they typed in themselves. An order expecting nothing, or less,
	 * could be settled by paying nothing, so it never verifies.
	 */
	if ( ! ( $expected['gross'] > 0 ) ) {
		return 'this order has no amount to check the payment against';
	}

	if ( ! isset( $ipn[ $amount_field ] ) || ! is_numeric( $ipn[ $amount_field ] ) ) {
		return 'IPN carried no usable ' . $amount_field . ' to check the amount against';
	}

	$paid = (float) $ipn[ $amount_field ];

	// Half a cent of tolerance so rounding never rejects a good payment.
	if ( $paid + 0.005 < $expected['gross'] ) {
		return 'paid ' . $paid . ' but this order totals ' . $expected['gross'];
	}

	return true;
}

/**
 * Check a subscription signup against the terms the order offered.
 *
 * @param array $ipn   Raw IPN fields as posted by PayPal.
 * @param array $terms Expected period3 and recur_times. Without recur_times the
 *                     count is not checked, for an order stored before 6.0.7.
 *
 * @return true|string True when the terms match, otherwise the reason they do not.
 */
function qpp_check_subscription_terms( $ipn, $terms ) {

	foreach ( array( 'mc_amount1', 'amount1', 'period1', 'mc_amount2', 'amount2', 'period2' ) as $key ) {
		if ( isset( $ipn[ $key ] ) && '' !== trim( (string) $ipn[ $key ] ) ) {
			return 'subscription has a trial period this form does not offer';
		}
	}

	$want = qpp_ipn_normalise_period( isset( $terms['period3'] ) ? $terms['period3'] : '' );
	$got  = qpp_ipn_normalise_period( isset( $ipn['period3'] ) ? $ipn['period3'] : '' );

	if ( '' === $want || $got !== $want ) {
		return 'subscription bills every ' . ( '' === $got ? '(missing)' : $got ) . ' but this order offered every ' . $want;
	}

	if ( ! isset( $ipn['recurring'] ) || '1' !== trim( (string) $ipn['recurring'] ) ) {
		return 'subscription does not recur';
	}

	if ( isset( $terms['recur_times'] ) ) {
		$want_times = (int) $terms['recur_times'];
		$got_times  = isset( $ipn['recur_times'] ) ? (int) $ipn['recur_times'] : 0;

		if ( $got_times !== $want_times ) {
			return 'subscription runs for ' . $got_times . ' payments but this order offered ' . $want_times;
		}
	}

	return true;
}

/**
 * A billing period in one form, so "1 m" and "1  M" compare equal.
 *
 * @param mixed $period Period as PayPal or the form states it.
 *
 * @return string
 */
function qpp_ipn_normalise_period( $period ) {
	return is_scalar( $period ) ? strtoupper( (string) preg_replace( '/\s+/', ' ', trim( (string) $period ) ) ) : '';
}

/**
 * The subscription terms a form offers now, for an order stored before the
 * order recorded its own terms. Null for a form that does not recur.
 *
 * @param string $form_id Form id.
 *
 * @return array|null
 */
function qpp_ipn_form_subscription_terms( $form_id ) {

	$qpp = qpp_get_stored_options( $form_id );

	if ( empty( $qpp['userecurring'] ) ) {
		return null;
	}

	// The number of payments may have been the buyer's choice, so it is not guessed.
	return array(
		'period3' => '1 ' . strtoupper( (string) $qpp['recurring'] ),
	);
}

/**
 * Build the payment expected for an order.
 *
 * Prefers the record written when the order was created, which is what the
 * PayPal button actually asked for. Orders placed before this version have no
 * such record, so fall back to the stored order total and the form's current
 * settings.
 *
 * @param string $custom  The order's PayPal custom token.
 * @param array  $order   The stored order record.
 * @param string $form_id The form the order belongs to.
 *
 * @return array Expected gross, currency and receiver.
 */
function qpp_get_ipn_expectation( $custom, $order, $form_id ) {

	$expectations = get_option( 'qpp_ipn_expectations' );

	if ( is_array( $expectations ) && isset( $expectations[ $custom ] ) ) {
		$stored = $expectations[ $custom ];

		$expectation = array(
			'gross'    => isset( $stored['gross'] ) ? (float) $stored['gross'] : 0.0,
			'currency' => isset( $stored['currency'] ) ? strtoupper( (string) $stored['currency'] ) : '',
			'receiver' => isset( $stored['receiver'] ) ? strtolower( trim( (string) $stored['receiver'] ) ) : '',
		);

		/*
		 * Orders record whether they are a subscription and on what terms.
		 * One stored by an earlier version records neither, so it is held to
		 * the terms its form offers now.
		 */
		if ( isset( $stored['kind'] ) || isset( $stored['recurring'] ) ) {
			$terms = isset( $stored['recurring'] ) && is_array( $stored['recurring'] ) ? $stored['recurring'] : null;
		} else {
			$terms = qpp_ipn_form_subscription_terms( $form_id );
		}

		if ( null !== $terms ) {
			$expectation['recurring'] = $terms;
		}

		return $expectation;
	}

	$qpp_setup = qpp_get_stored_setup();
	$send      = qpp_get_stored_send( $form_id );
	$currency  = qpp_get_stored_curr();

	$receiver = ! empty( $send['email'] ) ? $send['email'] : ( isset( $qpp_setup['email'] ) ? $qpp_setup['email'] : '' );

	$expectation = array(
		'gross'    => isset( $order['field3'] ) ? (float) $order['field3'] : 0.0,
		'currency' => isset( $currency[ $form_id ] ) ? strtoupper( substr( (string) $currency[ $form_id ], 0, 3 ) ) : '',
		'receiver' => strtolower( trim( (string) $receiver ) ),
	);

	$terms = qpp_ipn_form_subscription_terms( $form_id );

	if ( null !== $terms ) {
		$expectation['recurring'] = $terms;
	}

	return $expectation;
}

/**
 * Record what an order is asking PayPal to charge, for the IPN listener to
 * check the payment against later.
 *
 * @param string $custom The order's PayPal custom token.
 * @param array  $args   Expected gross, currency, receiver and form id, and for a
 *                       subscription its terms in recurring (period3, recur_times).
 */
function qpp_store_ipn_expectation( $custom, $args ) {

	if ( empty( $custom ) ) {
		return;
	}

	$expectations = get_option( 'qpp_ipn_expectations' );

	if ( ! is_array( $expectations ) ) {
		$expectations = array();
	}

	$expectations[ $custom ] = array(
		'gross'    => isset( $args['gross'] ) ? (float) $args['gross'] : 0.0,
		'currency' => isset( $args['currency'] ) ? strtoupper( (string) $args['currency'] ) : '',
		'receiver' => isset( $args['receiver'] ) ? strtolower( trim( (string) $args['receiver'] ) ) : '',
		'form'     => isset( $args['form'] ) ? (string) $args['form'] : '',
		'kind'     => empty( $args['recurring'] ) ? 'payment' : 'subscription',
		'created'  => time(),
	);

	if ( ! empty( $args['recurring'] ) && is_array( $args['recurring'] ) ) {
		$expectations[ $custom ]['recurring'] = array(
			'period3'     => qpp_ipn_normalise_period( isset( $args['recurring']['period3'] ) ? $args['recurring']['period3'] : '' ),
			'recur_times' => isset( $args['recurring']['recur_times'] ) ? trim( (string) $args['recurring']['recur_times'] ) : '',
		);
	}

	update_option( 'qpp_ipn_expectations', qpp_prune_ipn_expectations( $expectations ), false );
}

/**
 * Drop an order's expectation record once its payment has been accepted.
 *
 * @param string $custom The order's PayPal custom token.
 */
function qpp_clear_ipn_expectation( $custom ) {

	$expectations = get_option( 'qpp_ipn_expectations' );

	if ( ! is_array( $expectations ) || ! isset( $expectations[ $custom ] ) ) {
		return;
	}

	unset( $expectations[ $custom ] );

	update_option( 'qpp_ipn_expectations', $expectations, false );
}

/**
 * Keep the expectation store bounded. Forms that are filled in but never taken
 * through PayPal would otherwise accumulate indefinitely.
 *
 * @param array $expectations Current expectation records.
 *
 * @return array Pruned expectation records.
 */
function qpp_prune_ipn_expectations( $expectations ) {

	$cutoff = time() - ( 180 * DAY_IN_SECONDS );

	foreach ( $expectations as $key => $expectation ) {
		if ( ! isset( $expectation['created'] ) || (int) $expectation['created'] < $cutoff ) {
			unset( $expectations[ $key ] );
		}
	}

	$limit = (int) apply_filters( 'qpp_ipn_expectation_limit', 2000 );

	if ( $limit > 0 && count( $expectations ) > $limit ) {
		$expectations = array_slice( $expectations, - $limit, null, true );
	}

	return $expectations;
}

/**
 * Whether a PayPal transaction has already been processed.
 *
 * @param string $txn_id PayPal transaction id.
 *
 * @return bool
 */
function qpp_ipn_txn_seen( $txn_id ) {

	$seen = get_option( 'qpp_ipn_txn_ids' );

	return is_array( $seen ) && in_array( $txn_id, $seen, true );
}

/**
 * Record a processed PayPal transaction so the same one cannot be replayed.
 *
 * @param string $txn_id PayPal transaction id.
 */
function qpp_ipn_record_txn( $txn_id ) {

	$seen = get_option( 'qpp_ipn_txn_ids' );

	if ( ! is_array( $seen ) ) {
		$seen = array();
	}

	$seen[] = $txn_id;

	$limit = (int) apply_filters( 'qpp_ipn_txn_history_limit', 1000 );

	if ( $limit > 0 && count( $seen ) > $limit ) {
		$seen = array_slice( $seen, - $limit );
	}

	update_option( 'qpp_ipn_txn_ids', $seen, false );
}

/**
 * Log an IPN that came from PayPal but did not describe a valid payment for the
 * order. The order is left pending and no confirmation is sent.
 *
 * @param string $reason Why the IPN was rejected.
 * @param array  $ipn    Raw IPN fields as posted by PayPal.
 */
function qpp_ipn_reject( $reason, $ipn ) {

	$txn     = ! empty( $ipn['txn_id'] ) ? qpp_ipn_log_value( $ipn['txn_id'] ) : 'unknown';
	$message = 'Quick PayPal Payments: IPN rejected (txn ' . $txn . '): ' . $reason;

	if ( ! qpp_ipn_log( $message ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug only, and the message holds no personal data.
	}

	/**
	 * Fires when a genuine IPN is rejected because it does not match the order.
	 *
	 * @param string $reason Why the IPN was rejected.
	 * @param array  $ipn    Raw IPN fields as posted by PayPal.
	 */
	do_action( 'qpp_ipn_rejected', $reason, $ipn );
}


/**
 * The IPN debug log file, or false when logging is off or the path is refused.
 *
 * QPP_IPN_DEBUG_LOG_FILE turns logging on. IPN_DEBUG_LOG_FILE is the older name
 * and is still honoured.
 *
 * @return string|false
 */
function qpp_ipn_log_path() {
	if ( defined( 'QPP_IPN_DEBUG_LOG_FILE' ) ) {
		$path = QPP_IPN_DEBUG_LOG_FILE;
	} elseif ( defined( 'IPN_DEBUG_LOG_FILE' ) ) {
		$path = IPN_DEBUG_LOG_FILE;
	} else {
		return false;
	}

	return is_string( $path ) && qpp_ipn_log_path_allowed( $path ) ? $path : false;
}

/**
 * Whether a debug log may be written at this path: an absolute path in an
 * existing folder outside the WordPress install and its content folder.
 *
 * @param string $path Log file path.
 * @param array  $roots Folders the log may not be in; defaults to ABSPATH and WP_CONTENT_DIR.
 *
 * @return bool
 */
function qpp_ipn_log_path_allowed( $path, $roots = null ) {
	if ( ! is_string( $path ) || '' === $path || ! preg_match( '#^(/|[A-Za-z]:[\\\\/])#', $path ) ) {
		return false;
	}
	$dir = realpath( dirname( $path ) );
	if ( false === $dir ) {
		return false;
	}
	if ( null === $roots ) {
		$roots = array( defined( 'ABSPATH' ) ? ABSPATH : '', defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '' );
	}
	foreach ( $roots as $root ) {
		$root = '' === $root ? false : realpath( $root );
		if ( false !== $root && 0 === strpos( $dir . DIRECTORY_SEPARATOR, rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Write a line to the IPN debug log, if there is one.
 *
 * @param string $message What to log. Never personal data.
 *
 * @return bool Whether it was written.
 */
function qpp_ipn_log( $message ) {
	$path = qpp_ipn_log_path();
	if ( false === $path ) {
		return false;
	}

	return error_log( gmdate( '[Y-m-d H:i e] ' ) . $message . PHP_EOL, 3, $path ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Opt-in debug log set by the site owner.
}

/**
 * A value made safe for a single log line.
 *
 * @param mixed $value Value from an IPN.
 *
 * @return string
 */
function qpp_ipn_log_value( $value ) {
	return is_scalar( $value ) ? substr( (string) preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $value ), 0, 40 ) : '';
}

/**
 * The fields of an IPN that may be logged: what identifies the transaction and
 * its amount, nothing about the buyer.
 *
 * @param array $ipn IPN fields.
 *
 * @return string
 */
function qpp_ipn_log_summary( $ipn ) {
	$parts = array();
	foreach ( array( 'txn_id', 'txn_type', 'payment_status', 'mc_gross', 'mc_currency' ) as $key ) {
		$parts[] = $key . '=' . ( isset( $ipn[ $key ] ) ? qpp_ipn_log_value( $ipn[ $key ] ) : '' );
	}

	return implode( ' ', $parts );
}

/**
 * The option that holds an order's settlement lock while one request settles it.
 *
 * @param string $custom Order token.
 *
 * @return string
 */
function qpp_ipn_settlement_lock_key( $custom ) {
	return 'qpp_ipn_lock_' . md5( (string) $custom );
}

/**
 * Take the settlement lock for an order, so two deliveries of the same notice
 * arriving together cannot both settle it.
 *
 * The insert is atomic in the database. add_option() is not usable here, since
 * it overwrites a row another request inserted a moment earlier. A lock older
 * than the time limit belongs to a request that died, and is taken over only if
 * no other request has taken it over first.
 *
 * @param string $custom Order token.
 *
 * @return bool True when this request now holds the lock.
 */
function qpp_ipn_acquire_settlement_lock( $custom ) {
	global $wpdb;

	$key = qpp_ipn_settlement_lock_key( $custom );
	$now = (string) time();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- An atomic insert is the point; the options API cannot give one.
	$taken = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $key, $now ) );
	wp_cache_delete( $key, 'options' );
	wp_cache_delete( 'notoptions', 'options' );

	if ( 1 === (int) $taken ) {
		return true;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Must read the row as stored now, not a cached copy.
	$held_since = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );
	$limit      = (int) apply_filters( 'qpp_ipn_settlement_lock_timeout', 5 * MINUTE_IN_SECONDS );

	if ( null === $held_since || (int) $held_since > time() - $limit ) {
		return false;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Compare and swap, so only one request takes over a stale lock.
	$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $now, $key, $held_since ) );
	wp_cache_delete( $key, 'options' );

	return 1 === (int) $taken;
}

/**
 * Release an order's settlement lock.
 *
 * @param string $custom Order token.
 */
function qpp_ipn_release_settlement_lock( $custom ) {
	delete_option( qpp_ipn_settlement_lock_key( $custom ) );
}
