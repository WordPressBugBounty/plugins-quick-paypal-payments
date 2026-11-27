<?php
/**
 * Cells for the payment records CSV export.
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
 * A value as it should go into the CSV export, always read as text.
 *
 * A spreadsheet reads a cell that starts with =, +, -, @, a tab or a carriage
 * return as something to calculate. Such a value is given a leading apostrophe,
 * which spreadsheets show as text and do not display. Numbers and phone numbers
 * such as -5 or +44 20 7946 0000 are left as they are.
 *
 * @param mixed $value The stored value.
 *
 * @return string
 */
function qpp_csv_cell( $value ) {
	if ( ! is_scalar( $value ) ) {
		return '';
	}
	$value = (string) $value;
	if ( '' === $value || false === strpos( "=+-@\t\r", $value[0] ) ) {
		return $value;
	}
	if ( preg_match( '/^[+-]?[0-9][0-9 ().-]*$/', $value ) ) {
		return $value;
	}

	return "'" . $value;
}

/**
 * Every cell of a CSV row through qpp_csv_cell().
 *
 * @param array $cells The row.
 *
 * @return array
 */
function qpp_csv_row( array $cells ) {
	return array_map( 'qpp_csv_cell', $cells );
}
