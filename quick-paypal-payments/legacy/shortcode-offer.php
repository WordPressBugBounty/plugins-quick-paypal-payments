<?php
/**
 * Whether a signed set of form attributes is still offered on the site.
 *
 * A signature proves the plugin rendered the attributes once. Attributes that
 * set a price are honoured only while somewhere public still carries them: the
 * published page they were signed on, a widget, a published reusable block or
 * template. Attributes that only choose a form set no price of their own, so
 * they are honoured wherever the form appears, including a theme template.
 *
 * Kept apart from shortcode-attributes.php, which the unit suite loads, because
 * everything here reads the database.
 *
 * @package Quick_Paypal_Payments
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The comparable form of an attribute set: no post, no empty values, sorted.
 *
 * @param array $atts Attributes from a shortcode, a block or a widget.
 *
 * @return array
 */
function qpp_shortcode_offer_terms( $atts ) {
	$atts = is_array( $atts ) ? qpp_sanitize_shortcode_attributes( $atts ) : array();
	unset( $atts['post'] );

	if ( isset( $atts['form'] ) ) {
		$atts['form'] = qpp_sanitize_form_name( $atts['form'] );
	}

	$atts = array_filter( $atts, static function ( $value ) {
		return '' !== $value;
	} );
	ksort( $atts );

	return $atts;
}

/**
 * Whether one offered attribute set gives the signed one.
 *
 * An offer that names no form is for the default form. Only a plain [qpp]
 * follows a ?form= link, and that sets no price, so an offer carrying terms
 * never gives another form.
 *
 * @param array $signed Terms of the signed set.
 * @param array $offer  Attributes as written where the form is placed.
 *
 * @return bool
 */
function qpp_shortcode_offer_matches( $signed, $offer ) {
	$offer = qpp_shortcode_offer_terms( $offer );

	if ( ! isset( $offer['form'] ) && isset( $signed['form'] ) && 'default' === $signed['form'] ) {
		unset( $signed['form'] );
	}

	return $offer === $signed;
}

/**
 * Every [qpp] shortcode and form block in a piece of content, as attributes.
 *
 * @param string $content Post content, widget text or a stored meta value.
 * @param int    $depth   Reusable block nesting so far.
 *
 * @return array[]
 */
function qpp_shortcode_offers_in( $content, $depth = 0 ) {
	$offers = array();

	if ( ! is_string( $content ) || '' === $content ) {
		return $offers;
	}

	if ( false !== strpos( $content, '[qpp' ) && preg_match_all( '/' . get_shortcode_regex( array( 'qpp' ) ) . '/', $content, $found, PREG_SET_ORDER ) ) {
		foreach ( $found as $shortcode ) {
			if ( '[' === $shortcode[1] && ']' === $shortcode[6] ) {
				continue; // [[qpp]] is printed, not rendered.
			}
			$atts     = shortcode_parse_atts( $shortcode[3] );
			$offers[] = is_array( $atts ) ? $atts : array();
		}
	}

	if ( function_exists( 'parse_blocks' ) && false !== strpos( $content, '<!-- wp:' ) ) {
		$offers = array_merge( $offers, qpp_shortcode_offers_in_blocks( parse_blocks( $content ), $depth ) );
	}

	return $offers;
}

/**
 * Form blocks among parsed blocks, following inner blocks and reusable blocks.
 *
 * @param array $blocks From parse_blocks().
 * @param int   $depth  Reusable block nesting so far.
 *
 * @return array[]
 */
function qpp_shortcode_offers_in_blocks( $blocks, $depth ) {
	$offers = array();

	foreach ( (array) $blocks as $block ) {
		$name = isset( $block['blockName'] ) ? $block['blockName'] : '';

		if ( 'quick-paypal-payments/block' === $name ) {
			$offers[] = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		} elseif ( 'core/block' === $name && $depth < 3 && ! empty( $block['attrs']['ref'] ) ) {
			$reusable = get_post( (int) $block['attrs']['ref'] );
			if ( $reusable && 'publish' === $reusable->post_status ) {
				$offers = array_merge( $offers, qpp_shortcode_offers_in( $reusable->post_content, $depth + 1 ) );
			}
		}

		if ( ! empty( $block['innerBlocks'] ) ) {
			$offers = array_merge( $offers, qpp_shortcode_offers_in_blocks( $block['innerBlocks'], $depth ) );
		}
	}

	return $offers;
}

/**
 * Strings among stored meta values that may carry a shortcode. Page builders
 * keep the page there rather than in the post content, often JSON encoded.
 *
 * @param mixed $value A meta value.
 *
 * @return string[]
 */
function qpp_shortcode_offer_meta_strings( $value ) {
	if ( is_array( $value ) || is_object( $value ) ) {
		$strings = array();
		foreach ( (array) $value as $item ) {
			$strings = array_merge( $strings, qpp_shortcode_offer_meta_strings( $item ) );
		}

		return $strings;
	}

	if ( ! is_string( $value ) || false === strpos( $value, 'qpp' ) ) {
		return array();
	}

	$value = maybe_unserialize( $value );
	if ( ! is_string( $value ) ) {
		return qpp_shortcode_offer_meta_strings( $value );
	}

	return array( $value, stripslashes( $value ) );
}

/**
 * Forms a published post offers, in its content and its meta.
 *
 * @param WP_Post $post Post.
 *
 * @return array[]
 */
function qpp_shortcode_offers_on_post( $post ) {
	$offers = qpp_shortcode_offers_in( $post->post_content );

	foreach ( qpp_shortcode_offer_meta_strings( get_post_meta( $post->ID ) ) as $text ) {
		$offers = array_merge( $offers, qpp_shortcode_offers_in( $text ) );
	}

	return $offers;
}

/**
 * Forms offered outside any one post: widgets, reusable blocks and templates.
 *
 * @return array[]
 */
function qpp_shortcode_offers_sitewide() {
	$offers = array();

	$widgets = get_option( 'widget_qpp_widget' );
	if ( is_array( $widgets ) ) {
		foreach ( $widgets as $key => $instance ) {
			if ( is_int( $key ) && is_array( $instance ) ) {
				$offers[] = $instance;
			}
		}
	}

	foreach ( array( 'widget_text' => 'text', 'widget_custom_html' => 'content', 'widget_block' => 'content' ) as $option => $field ) {
		$stored = get_option( $option );
		if ( is_array( $stored ) ) {
			foreach ( $stored as $key => $instance ) {
				if ( is_int( $key ) && isset( $instance[ $field ] ) ) {
					$offers = array_merge( $offers, qpp_shortcode_offers_in( $instance[ $field ] ) );
				}
			}
		}
	}

	$placed = get_posts( array(
		'post_type'      => array( 'wp_block', 'wp_template', 'wp_template_part' ),
		'post_status'    => 'publish',
		'posts_per_page' => 200,
		'no_found_rows'  => true,
	) );
	foreach ( $placed as $post ) {
		$offers = array_merge( $offers, qpp_shortcode_offers_in( $post->post_content ) );
	}

	return $offers;
}

/**
 * Whether verified attributes may be used to price a submission.
 *
 * @param array $sc Attributes from qpp_verified_shortcode_attributes().
 *
 * @return bool
 */
function qpp_shortcode_attributes_are_offered( $sc ) {
	if ( ! is_array( $sc ) ) {
		return false;
	}

	$signed = qpp_shortcode_offer_terms( $sc );
	$terms  = $signed;
	unset( $terms['form'] );

	// Only a form chosen: the form's own settings set the price.
	if ( array() === $terms ) {
		return true;
	}

	$post_id = isset( $sc['post'] ) ? (int) $sc['post'] : 0;
	$offered = false;

	if ( $post_id > 0 ) {
		$post = get_post( $post_id );

		// Signed on a post: only while that post is public and unprotected.
		if ( ! $post || 'publish' !== $post->post_status || '' !== $post->post_password || ! is_post_type_viewable( $post->post_type ) ) {
			return (bool) apply_filters( 'qpp_shortcode_attributes_offered', false, $sc );
		}

		foreach ( qpp_shortcode_offers_on_post( $post ) as $offer ) {
			if ( qpp_shortcode_offer_matches( $signed, $offer ) ) {
				$offered = true;
				break;
			}
		}
	}

	if ( ! $offered ) {
		foreach ( qpp_shortcode_offers_sitewide() as $offer ) {
			if ( qpp_shortcode_offer_matches( $signed, $offer ) ) {
				$offered = true;
				break;
			}
		}
	}

	/**
	 * Whether signed form attributes are still offered somewhere public.
	 *
	 * For a form placed where the plugin cannot see it, such as a price set
	 * in a PHP theme template.
	 *
	 * @param bool  $offered Whether a placement carrying them was found.
	 * @param array $sc      The signed attributes, including 'post'.
	 */
	return (bool) apply_filters( 'qpp_shortcode_attributes_offered', $offered, $sc );
}
