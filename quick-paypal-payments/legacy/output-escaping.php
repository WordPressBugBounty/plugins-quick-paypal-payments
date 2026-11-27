<?php
/**
 * Printing visitor text in the payment form.
 *
 * Values from a link or a buyer's submission are printed through these
 * helpers, and the form's square brackets are written as character references,
 * so the page shows exactly what was typed.
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
 * Turn square brackets into character references, outside script and style.
 *
 * A browser shows &#091; as [ in text and decodes it in an attribute value, so
 * the page and anything the form posts are unchanged. Script and style contents
 * are not decoded by a browser, so they are left alone.
 *
 * The zero-padded form is used because it is what wp_kses() normalises &#91; to,
 * and WordPress leaves it as it is, where it turns the short form back into a
 * bracket.
 *
 * @param string $html Markup.
 * @return string The same markup, its brackets written as character references.
 */
function qpp_inert_shortcodes( $html ) {
	$html = (string) $html;
	if ( false === strpos( $html, '[' ) && false === strpos( $html, ']' ) ) {
		return $html;
	}

	$parts = preg_split( '#(<(script|style)\b[^>]*>.*?</\2\s*>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
	if ( false === $parts ) {
		// A string preg cannot split is not one to trust whole.
		return str_replace( array( '[', ']' ), array( '&#091;', '&#093;' ), $html );
	}

	$out   = '';
	$count = count( $parts );
	for ( $i = 0; $i < $count; $i++ ) {
		if ( 1 === $i % 3 ) {
			// A script or style element, kept as it is. Its tag name follows.
			$out .= $parts[ $i ];
			++$i;
			continue;
		}
		$out .= str_replace( array( '[', ']' ), array( '&#091;', '&#093;' ), $parts[ $i ] );
	}

	return $out;
}

/**
 * A visitor's value, for printing as text.
 *
 * @param mixed $value Value from the request or the visitor's submission.
 * @return string
 */
function qpp_esc_visitor_html( $value ) {
	return qpp_inert_shortcodes( esc_html( (string) $value ) );
}

/**
 * A visitor's value, for printing inside an attribute.
 *
 * @param mixed $value Value from the request or the visitor's submission.
 * @return string
 */
function qpp_esc_visitor_attr( $value ) {
	return qpp_inert_shortcodes( esc_attr( (string) $value ) );
}

/**
 * A value a link supplies to prefill the form, cleaned as it is read.
 *
 * Square brackets have no place in a reference, an amount, an item number or a
 * coupon code, so they are dropped here as well as escaped where the form is
 * printed.
 *
 * @param mixed $value Value from the request, already unslashed.
 * @return string
 */
function qpp_clean_prefill( $value ) {
	if ( ! is_scalar( $value ) ) {
		return '';
	}

	return str_replace( array( '[', ']' ), '', sanitize_text_field( (string) $value ) );
}
