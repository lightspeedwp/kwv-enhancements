<?php
/**
 * Block binding sources for KWV Enhancements.
 *
 * These let block markup (in patterns/templates) pull dynamic values from post
 * meta with light formatting the core `core/post-meta` source can't do. They
 * are content-type-agnostic — reuse them for any CPT (Careers, Events, …).
 *
 * @package KWV_Enhancements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the KWV block-binding sources.
 *
 * `kwv/meta-date` — reads a date meta value stored as Ymd (the format SCF's
 * date picker saves) and returns it formatted with the site's date format,
 * e.g. `20260731` → `31 July 2026`. Bind a paragraph's `content` to it and
 * pass the meta key as the `key` argument:
 *
 *   "bindings":{"content":{"source":"kwv/meta-date","args":{"key":"kwv_career_closing_date"}}}
 */
function kwv_enh_register_block_bindings() {

	if ( ! function_exists( 'register_block_bindings_source' ) ) {
		return; // WordPress < 6.5.
	}

	register_block_bindings_source(
		'kwv/meta-date',
		array(
			'label'              => __( 'KWV Meta Date', 'kwv-enhancements' ),
			'get_value_callback' => 'kwv_enh_meta_date_binding',
			'uses_context'       => array( 'postId' ),
		)
	);
}
add_action( 'init', 'kwv_enh_register_block_bindings' );

/**
 * Resolve a formatted date from a post-meta value stored as Ymd.
 *
 * @param array    $args            Binding args; expects 'key' (meta key) and optionally 'format' (PHP date format).
 * @param WP_Block $block_instance  The block being rendered (for post context).
 * @return string The formatted date, the raw value if it can't be parsed, or '' when empty.
 */
function kwv_enh_meta_date_binding( $args, $block_instance ) {

	$key = isset( $args['key'] ) ? (string) $args['key'] : '';

	if ( '' === $key ) {
		return '';
	}

	$post_id = $block_instance->context['postId'] ?? get_the_ID();

	if ( ! $post_id ) {
		return '';
	}

	$raw = (string) get_post_meta( (int) $post_id, $key, true );

	if ( '' === $raw ) {
		return '';
	}

	$date = DateTime::createFromFormat( 'Ymd', $raw );

	if ( ! $date ) {
		return $raw; // Unexpected format — show what's stored rather than nothing.
	}

	$format = ! empty( $args['format'] ) ? (string) $args['format'] : (string) get_option( 'date_format', 'j F Y' );

	return wp_date( $format, $date->getTimestamp() );
}
