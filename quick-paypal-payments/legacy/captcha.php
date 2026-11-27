<?php
/**
 * The spambot blocker sum: the answer is decided and checked by the server.
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
 * How long an issued sum can be answered, in seconds. The same as the form's
 * own nonce, so a cached page goes stale no sooner than it already did.
 */
if ( ! defined( 'QPP_CAPTCHA_LIFETIME' ) ) {
	define( 'QPP_CAPTCHA_LIFETIME', DAY_IN_SECONDS );
}

/**
 * Signature for an answer and expiry time.
 *
 * @param int $answer  The sum's answer.
 * @param int $expires Unix time the sum stops being accepted.
 *
 * @return string
 */
function qpp_captcha_signature( $answer, $expires ) {
	return hash_hmac( 'sha256', 'qpp_captcha|' . (int) $answer . '|' . (int) $expires, wp_salt( 'nonce' ) );
}

/**
 * The hidden field value for a sum, carrying its expiry and signature but not
 * its answer.
 *
 * @param int      $answer The sum's answer.
 * @param int|null $now    Current time, for tests.
 *
 * @return string
 */
function qpp_captcha_token( $answer, $now = null ) {
	$expires = ( null === $now ? time() : (int) $now ) + QPP_CAPTCHA_LIFETIME;

	return $expires . '.' . qpp_captcha_signature( $answer, $expires );
}

/**
 * Whether a typed answer is the answer to the sum the token was issued for.
 *
 * @param mixed    $typed The buyer's answer.
 * @param mixed    $token The posted hidden field.
 * @param int|null $now   Current time, for tests.
 *
 * @return bool
 */
function qpp_captcha_answer_ok( $typed, $token, $now = null ) {
	if ( ! is_scalar( $typed ) || ! is_string( $token ) ) {
		return false;
	}
	$typed = trim( (string) $typed );
	if ( ! preg_match( '/^-?\d{1,3}$/', $typed ) || ! preg_match( '/^(\d{1,12})\.([0-9a-f]{64})$/', $token, $parts ) ) {
		return false;
	}
	$now = null === $now ? time() : (int) $now;
	if ( (int) $parts[1] < $now ) {
		return false;
	}

	return hash_equals( qpp_captcha_signature( (int) $typed, (int) $parts[1] ), $parts[2] );
}
