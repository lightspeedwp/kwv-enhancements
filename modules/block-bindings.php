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

namespace KWV\Enhancements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the KWV block-binding sources.
 *
 * `kwv/meta-date` — reads a date meta value stored as Ymd (the format SCF's
 * date picker saves) and returns it formatted with the site's date format,
 * e.g. `20260731` → `31 July 2026`. Bind a paragraph's `content` to it and
 * pass the meta key as the `key` argument:
 *
 *   "bindings":{"content":{"source":"kwv/meta-date","args":{"key":"kwv_career_closing_date"}}}
 */
class BlockBindings implements Module {

	/**
	 * Register the binding source on init.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_sources' ) );
	}

	/**
	 * Register the KWV block-binding sources.
	 */
	public function register_sources(): void {

		if ( ! function_exists( 'register_block_bindings_source' ) ) {
			return; // WordPress < 6.5.
		}

		register_block_bindings_source(
			'kwv/meta-date',
			array(
				'label'              => __( 'KWV Meta Date', 'kwv-enhancements' ),
				'get_value_callback' => array( $this, 'get_meta_date' ),
				'uses_context'       => array( 'postId' ),
			)
		);

		register_block_bindings_source(
			'kwv/meta-text',
			array(
				'label'              => __( 'KWV Meta Text', 'kwv-enhancements' ),
				'get_value_callback' => array( $this, 'get_meta_text' ),
				'uses_context'       => array( 'postId' ),
			)
		);
	}

	/**
	 * Resolve a formatted date from a post-meta value stored as Ymd.
	 *
	 * @param array    $args           Binding args; expects 'key' (meta key) and optionally 'format' (PHP date format).
	 * @param \WP_Block $block_instance The block being rendered (for post context).
	 * @return string The formatted date, the raw value if it can't be parsed, or '' when empty.
	 */
	public function get_meta_date( $args, $block_instance ) {

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

		$date = \DateTime::createFromFormat( 'Ymd', $raw );

		if ( ! $date ) {
			return $raw; // Unexpected format — show what's stored rather than nothing.
		}

		$format = ! empty( $args['format'] ) ? (string) $args['format'] : (string) get_option( 'date_format', 'j F Y' );

		return wp_date( $format, $date->getTimestamp() );
	}

	/**
	 * Return a plain post-meta value verbatim (no formatting).
	 *
	 * The counterpart to `kwv/meta-date` for text meta the `core/post-meta`
	 * source can't reach on the front end (SCF/ACF fields aren't registered for
	 * it). Bind a paragraph's `content` to it and pass the meta key as `key`:
	 *
	 *   "bindings":{"content":{"source":"kwv/meta-text","args":{"key":"kwv_edition"}}}
	 *
	 * @param array     $args           Binding args; expects 'key' (meta key).
	 * @param \WP_Block $block_instance The block being rendered (for post context).
	 * @return string The stored meta value, or '' when empty/unavailable.
	 */
	public function get_meta_text( $args, $block_instance ) {

		$key = isset( $args['key'] ) ? (string) $args['key'] : '';

		if ( '' === $key ) {
			return '';
		}

		$post_id = $block_instance->context['postId'] ?? get_the_ID();

		if ( ! $post_id ) {
			return '';
		}

		return (string) get_post_meta( (int) $post_id, $key, true );
	}
}
