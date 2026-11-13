<?php
/**
 * Brands module — the content behind the redesigned brand pages (/brand/…).
 *
 * Client change request (Estimate 3439, "KWV - Brand page redesign"). Each
 * WooCommerce Brand (`product_brand` term) gets a "Brand Page Content" SCF field
 * group (acf-json/group_kwv_brands.json): logo, introduction, up to eight award
 * images, a website link and six social links. This module exposes those values
 * to the theme's brand template in three ways:
 *
 * - the `kwv/brand` block-binding source, for single values (the brand name,
 *   introduction, logo, website link and each social link);
 * - the dynamic `kwv/brand-awards` block, for the awards row;
 * - a render filter that drops any block bound to an empty brand field, so a
 *   field left blank is simply not shown.
 *
 * Values are read as plain term meta (SCF stores a term field under its field
 * name), so the page still renders without SCF active. Presentation — layout,
 * tokens, the product slider — lives in the kwv-theme-2026 block theme.
 *
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the brand binding source, the awards block and the empty-field filter.
 */
class Brands implements Module {

	/**
	 * The WooCommerce Brands taxonomy.
	 */
	public const TAXONOMY = 'product_brand';

	/**
	 * The block-binding source name.
	 */
	public const SOURCE = 'kwv/brand';

	/**
	 * Prefix shared by every brand field name (= its term-meta key).
	 */
	public const FIELD_PREFIX = 'kwv_brand_';

	/**
	 * The most award images shown for a brand (matches the SCF gallery's max).
	 */
	public const MAX_AWARDS = 8;

	/**
	 * The awards block's directory, relative to the plugin root.
	 */
	public const AWARDS_BLOCK_DIR = 'blocks/brand-awards';

	/**
	 * Binding keys that hold a link (the website plus the six social platforms).
	 */
	private const LINK_KEYS = array( 'website', 'facebook', 'instagram', 'x', 'youtube', 'tiktok', 'linkedin' );

	/**
	 * Register the module's hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_source' ) );
		add_action( 'init', array( $this, 'register_block' ) );
		add_filter( 'block_bindings_supported_attributes_core/social-link', array( $this, 'allow_social_link_url' ) );
		add_filter( 'render_block', array( $this, 'hide_blocks_bound_to_empty_fields' ), 10, 2 );
	}

	/**
	 * Register the `kwv/brand` binding source.
	 *
	 * Bind an attribute to a brand value by passing its `key`:
	 *
	 *   "bindings":{"content":{"source":"kwv/brand","args":{"key":"intro"}}}
	 *
	 * Keys: `name`, `intro` (paragraph/heading `content`); `logo` (image `url`,
	 * `alt`, `id`); `website`, `facebook`, `instagram`, `x`, `youtube`, `tiktok`,
	 * `linkedin` (button or social-link `url`).
	 */
	public function register_source(): void {

		if ( ! function_exists( 'register_block_bindings_source' ) ) {
			return; // WordPress < 6.5.
		}

		register_block_bindings_source(
			self::SOURCE,
			array(
				'label'              => __( 'KWV Brand', 'kwv-enhancements' ),
				'get_value_callback' => array( $this, 'get_binding_value' ),
			)
		);
	}

	/**
	 * Register kwv/brand-awards from its block.json, rendered by
	 * {@see render_awards()}.
	 */
	public function register_block(): void {
		register_block_type(
			KWV_ENH_DIR . self::AWARDS_BLOCK_DIR,
			array(
				'render_callback' => array( $this, 'render_awards' ),
			)
		);
	}

	/**
	 * Let a Social Icon's `url` be bound, so each icon can read its link from the
	 * brand. Core already renders nothing for a social icon without a URL, which
	 * is exactly the "blank field is hidden" behaviour the brand page needs.
	 *
	 * @param string[] $attributes The block's bindable attributes.
	 * @return string[]
	 */
	public function allow_social_link_url( $attributes ) {
		$attributes   = (array) $attributes;
		$attributes[] = 'url';

		return array_values( array_unique( $attributes ) );
	}

	/**
	 * The brand being viewed: the queried term on a brand archive, else null.
	 *
	 * @return \WP_Term|null
	 */
	public function current_brand(): ?\WP_Term {

		$term = get_queried_object();

		if ( $term instanceof \WP_Term && self::TAXONOMY === $term->taxonomy ) {
			return $term;
		}

		return null;
	}

	/**
	 * A brand's value for a binding key, normalised for output.
	 *
	 * - `name`  — the brand name.
	 * - `intro` — the introduction, as plain text (line breaks kept as entered).
	 * - `logo`  — the logo's attachment ID as a string, or '' when it is not an
	 *             image attachment.
	 * - links   — an http(s) URL, or '' for anything else.
	 *
	 * @param \WP_Term $brand The brand term.
	 * @param string   $key   A binding key.
	 * @return string The value, or '' when the field is blank or the key unknown.
	 */
	public function value( \WP_Term $brand, string $key ): string {

		if ( 'name' === $key ) {
			return $brand->name;
		}

		$raw = get_term_meta( $brand->term_id, self::FIELD_PREFIX . $key, true );

		if ( 'intro' === $key ) {
			return trim( (string) $raw );
		}

		if ( 'logo' === $key ) {
			$logo_id = (int) $raw;

			return ( $logo_id && wp_attachment_is_image( $logo_id ) ) ? (string) $logo_id : '';
		}

		if ( in_array( $key, self::LINK_KEYS, true ) ) {
			return esc_url_raw( trim( (string) $raw ), array( 'http', 'https' ) );
		}

		return '';
	}

	/**
	 * A brand's award images, in the order the editor arranged them.
	 *
	 * @param \WP_Term $brand The brand term.
	 * @return int[] Image attachment IDs, at most {@see MAX_AWARDS}.
	 */
	public function awards( \WP_Term $brand ): array {

		$ids = get_term_meta( $brand->term_id, self::FIELD_PREFIX . 'awards', true );
		$ids = array_filter( array_map( 'absint', (array) $ids ), 'wp_attachment_is_image' );

		return array_slice( array_values( $ids ), 0, self::MAX_AWARDS );
	}

	/**
	 * Resolve the bound value for the `kwv/brand` source.
	 *
	 * Away from a brand page (the Site Editor, any other template) it returns
	 * null, which leaves the block's own saved value in place as a placeholder.
	 *
	 * @param array     $args           Binding args; expects 'key'.
	 * @param \WP_Block $block_instance The bound block (unused).
	 * @param string    $attribute_name The attribute being bound.
	 * @return string|int|null
	 */
	public function get_binding_value( $args, $block_instance, $attribute_name ) {

		$key   = isset( $args['key'] ) ? (string) $args['key'] : '';
		$brand = $this->current_brand();

		if ( '' === $key || ! $brand ) {
			return null;
		}

		$value = $this->value( $brand, $key );

		if ( 'logo' === $key ) {
			return $this->logo_attribute( $brand, (int) $value, (string) $attribute_name );
		}

		if ( 'content' === $attribute_name ) {
			return nl2br( esc_html( $value ) );
		}

		return 'url' === $attribute_name ? $value : null;
	}

	/**
	 * One attribute of the brand's logo image.
	 *
	 * @param \WP_Term $brand     The brand term.
	 * @param int      $logo_id   The logo attachment ID (0 when none).
	 * @param string   $attribute The image attribute: url, alt or id.
	 * @return string|int|null
	 */
	private function logo_attribute( \WP_Term $brand, int $logo_id, string $attribute ) {

		if ( ! $logo_id ) {
			return 'url' === $attribute ? '' : null;
		}

		switch ( $attribute ) {
			case 'url':
				return (string) wp_get_attachment_image_url( $logo_id, 'medium' );
			case 'id':
				return $logo_id;
			case 'alt':
				$alt = trim( (string) get_post_meta( $logo_id, '_wp_attachment_image_alt', true ) );

				/* translators: %s: brand name. */
				return '' !== $alt ? $alt : sprintf( __( '%s logo', 'kwv-enhancements' ), $brand->name );
		}

		return null;
	}

	/**
	 * Drop a block whose brand field is blank.
	 *
	 * A block bound to `kwv/brand` renders nothing on a brand page when the field
	 * behind it is empty — no empty paragraph, no broken image, no dead link.
	 *
	 * @param string $block_content The rendered block.
	 * @param array  $block         The parsed block.
	 * @return string
	 */
	public function hide_blocks_bound_to_empty_fields( $block_content, $block ) {

		$bindings = $block['attrs']['metadata']['bindings'] ?? null;

		if ( ! is_array( $bindings ) || '' === (string) $block_content ) {
			return $block_content;
		}

		$brand = null;

		foreach ( $bindings as $binding ) {
			if ( ! is_array( $binding ) || self::SOURCE !== ( $binding['source'] ?? '' ) ) {
				continue;
			}

			$brand = $brand ?? $this->current_brand();

			if ( ! $brand ) {
				return $block_content;
			}

			if ( '' === $this->value( $brand, (string) ( $binding['args']['key'] ?? '' ) ) ) {
				return '';
			}
		}

		return $block_content;
	}

	/**
	 * Render the awards block: the brand's award images in a centred row, or
	 * nothing when the brand has none.
	 *
	 * The row is emitted as core block markup through do_blocks(), so the theme
	 * lays it out with its own tokens. An image with Alt Text is announced by
	 * that text; one without is treated as decorative.
	 *
	 * @return string
	 */
	public function render_awards(): string {

		$brand  = $this->current_brand();
		$awards = $brand ? $this->awards( $brand ) : array();

		if ( ! $awards ) {
			return wp_is_serving_rest_request() ? $this->awards_placeholder() : '';
		}

		$images = '';

		foreach ( $awards as $image_id ) {
			$images .= '<!-- wp:image ' . serialize_block_attributes(
				array(
					'id'        => $image_id,
					'sizeSlug'  => 'medium',
					'className' => 'kwv-brand-awards__item',
				)
			) . ' -->'
				. '<figure class="wp-block-image size-medium kwv-brand-awards__item">'
				. wp_get_attachment_image( $image_id, 'medium', false, array( 'class' => 'wp-image-' . $image_id ) )
				. '</figure>'
				. '<!-- /wp:image -->';
		}

		$markup = '<!-- wp:group {"metadata":{"name":"Awards"},"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"center","verticalAlignment":"center"}} -->'
			. '<div class="wp-block-group">' . $images . '</div>'
			. '<!-- /wp:group -->';

		return sprintf(
			'<div %1$s>%2$s</div>',
			get_block_wrapper_attributes(
				array(
					'class'      => 'kwv-brand-awards',
					'role'       => 'group',
					'aria-label' => __( 'Awards and accolades', 'kwv-enhancements' ),
				)
			),
			do_blocks( $markup )
		);
	}

	/**
	 * The editor-only note shown when there is nothing to render.
	 *
	 * @return string
	 */
	private function awards_placeholder(): string {
		return sprintf(
			'<div %1$s><p><strong>%2$s</strong><br>%3$s</p></div>',
			get_block_wrapper_attributes( array( 'class' => 'kwv-brand-awards is-placeholder' ) ),
			esc_html__( 'Brand awards', 'kwv-enhancements' ),
			esc_html__( 'Shows the award images entered on the Brand (Products → Brands → Awards & accolades). Hidden when a brand has none.', 'kwv-enhancements' )
		);
	}
}
