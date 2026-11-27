<?php
/**
 * A one-off reminder about an IPN debug log left on the server.
 *
 * Shown to administrators while a log file named by QPP_IPN_DEBUG_LOG_FILE or
 * IPN_DEBUG_LOG_FILE exists, until each one dismisses it. The file is never
 * read or deleted here; that stays the site owner's decision.
 *
 * @package Quick_Paypal_Payments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The debug log constants that are set, by name.
 *
 * @return array<string, string> constant name => path
 */
function qpp_ipn_log_configured_paths() {
	$paths = array();
	foreach ( array( 'QPP_IPN_DEBUG_LOG_FILE', 'IPN_DEBUG_LOG_FILE' ) as $name ) {
		if ( defined( $name ) && is_string( constant( $name ) ) && '' !== constant( $name ) ) {
			$paths[ $name ] = constant( $name );
		}
	}

	return $paths;
}

/**
 * The configured log files that exist, by constant name.
 *
 * @param array<string, string> $paths constant name => path.
 *
 * @return array<string, string>
 */
function qpp_ipn_log_existing_files( array $paths ) {
	return array_filter(
		$paths,
		function ( $path ) {
			return is_string( $path ) && '' !== $path && is_file( $path );
		}
	);
}

/**
 * What a dismissal is recorded against, so a different log file shows again.
 *
 * @param array<string, string> $files constant name => path.
 *
 * @return string
 */
function qpp_ipn_log_notice_key( array $files ) {
	ksort( $files );

	return md5( wp_json_encode( $files ) );
}

/**
 * The reminder for the current user and screen, or '' when it is not due.
 *
 * @param array<string, string> $paths constant name => path, normally qpp_ipn_log_configured_paths().
 *
 * @return string
 */
function qpp_ipn_log_notice( array $paths ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return '';
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	$base   = $screen ? (string) $screen->base : '';
	if ( 'dashboard' !== $base && false === strpos( $base, 'quick-paypal-payments' ) ) {
		return '';
	}
	$files = qpp_ipn_log_existing_files( $paths );
	if ( empty( $files ) ) {
		return '';
	}
	if ( get_user_meta( get_current_user_id(), 'qpp_ipn_log_notice_dismissed', true ) === qpp_ipn_log_notice_key( $files ) ) {
		return '';
	}

	$out = '<div id="qpp-ipn-log-notice" class="notice notice-warning">';
	foreach ( $files as $name => $path ) {
		$out .= '<p>' . esc_html(
			sprintf(
				/* translators: %1$s is the log file path, %2$s is the name of the wp-config.php constant. */
				__( 'Quick PayPal Payments: the IPN debug log at %1$s may contain buyers\' personal data. Delete it if you no longer need it, and remove %2$s from wp-config.php when debugging is finished.', 'quick-paypal-payments' ),
				$path,
				$name
			)
		) . '</p>';
	}
	$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=qpp_dismiss_ipn_log_notice' ), 'qpp_dismiss_ipn_log_notice' );
	$out    .= '<p><a class="button" href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss', 'quick-paypal-payments' ) . '</a></p></div>';

	return $out;
}

/**
 * Records the dismissal for the current user, against the files now present.
 *
 * @param array<string, string> $paths constant name => path.
 *
 * @return void
 */
function qpp_ipn_log_notice_dismiss( array $paths ) {
	update_user_meta( get_current_user_id(), 'qpp_ipn_log_notice_dismissed', qpp_ipn_log_notice_key( qpp_ipn_log_existing_files( $paths ) ) );
}
