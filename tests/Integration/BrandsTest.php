<?php
/**
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements\Tests\Integration;

use KWV\Enhancements\Brands;

/**
 * Brand page content: the kwv/brand source, the empty-field render filter and
 * the kwv/brand-awards block (docs/brand-pages.md).
 *
 * @covers \KWV\Enhancements\Brands
 */
final class BrandsTest extends TestCase {

	/** @var Brands */
	private $brands;

	/** @var \WP_Term */
	private $brand;

	public function set_up(): void {
		parent::set_up();
		$this->brands = new Brands();
		$this->brand  = get_term(
			self::factory()->term->create(
				array(
					'taxonomy' => Brands::TAXONOMY,
					'name'     => 'Laborie',
				)
			),
			Brands::TAXONOMY
		);
	}

	private function meta( string $key, $value ): void {
		update_term_meta( $this->brand->term_id, Brands::FIELD_PREFIX . $key, $value );
	}

	private function view_brand(): void {
		$this->go_to( get_term_link( $this->brand ) );
	}

	public function test_woocommerce_brands_taxonomy_exists(): void {
		$this->assertTrue( taxonomy_exists( Brands::TAXONOMY ) );
	}

	public function test_source_block_and_filters_are_registered(): void {
		unregister_block_bindings_source( Brands::SOURCE );
		unregister_block_type( 'kwv/brand-awards' );
		$this->brands->register_source();
		$this->brands->register_block();
		$this->brands->register();

		$this->assertNotNull( \WP_Block_Bindings_Registry::get_instance()->get_registered( Brands::SOURCE ) );
		$this->assertTrue( \WP_Block_Type_Registry::get_instance()->is_registered( 'kwv/brand-awards' ) );
		$this->assertContains( 'url', apply_filters( 'block_bindings_supported_attributes_core/social-link', array() ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- core's hook name.
		$this->assertSame( 10, has_filter( 'render_block', array( $this->brands, 'hide_blocks_bound_to_empty_fields' ) ) );
	}

	public function test_allow_social_link_url_does_not_duplicate(): void {
		$this->assertSame( array( 'url' ), $this->brands->allow_social_link_url( array( 'url' ) ) );
		$this->assertSame( array( 'rel', 'url' ), $this->brands->allow_social_link_url( array( 'rel' ) ) );
	}

	public function test_current_brand_is_the_queried_brand_only(): void {
		$this->view_brand();
		$this->assertSame( $this->brand->term_id, $this->brands->current_brand()->term_id );

		$this->go_to( get_term_link( self::factory()->category->create() ) );
		$this->assertNull( $this->brands->current_brand() );

		$this->go_to( get_permalink( self::factory()->post->create() ) );
		$this->assertNull( $this->brands->current_brand() );
	}

	public function test_value_name_and_trimmed_intro(): void {
		$this->meta( 'intro', "  Line one\nLine two  " );

		$this->assertSame( 'Laborie', $this->brands->value( $this->brand, 'name' ) );
		$this->assertSame( "Line one\nLine two", $this->brands->value( $this->brand, 'intro' ) );
	}

	/**
	 * @dataProvider links
	 *
	 * @param string $stored   Stored value.
	 * @param string $expected Normalised value.
	 */
	public function test_value_links_are_http_or_https_only( string $stored, string $expected ): void {
		foreach ( array( 'website', 'facebook', 'instagram', 'x', 'youtube', 'tiktok', 'linkedin' ) as $key ) {
			$this->meta( $key, $stored );
			$this->assertSame( $expected, $this->brands->value( $this->brand, $key ), $key );
		}
	}

	public static function links(): array {
		return array(
			'https'      => array( ' https://laborie.co.za ', 'https://laborie.co.za' ),
			'http'       => array( 'http://laborie.co.za', 'http://laborie.co.za' ),
			'javascript' => array( 'javascript:alert(1)', '' ),
			'mailto'     => array( 'mailto:a@b.co', '' ),
			'blank'      => array( '', '' ),
		);
	}

	public function test_value_logo_must_be_an_image_attachment(): void {
		$image = $this->image( 'logo.jpg' );

		$this->meta( 'logo', $image );
		$this->assertSame( (string) $image, $this->brands->value( $this->brand, 'logo' ) );

		$this->meta( 'logo', $this->pdf() );
		$this->assertSame( '', $this->brands->value( $this->brand, 'logo' ) );

		$this->meta( 'logo', 999999 );
		$this->assertSame( '', $this->brands->value( $this->brand, 'logo' ) );
	}

	public function test_value_unknown_key_is_empty(): void {
		$this->meta( 'secret', 'x' );

		$this->assertSame( '', $this->brands->value( $this->brand, 'secret' ) );
	}

	public function test_awards_keep_order_skip_non_images_and_cap_at_eight(): void {
		$images = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$images[] = $this->image( "award-$i.jpg" );
		}
		$stored = $images;
		array_splice( $stored, 1, 0, array( $this->pdf(), 0, 'junk' ) );
		$this->meta( 'awards', $stored );

		$this->assertSame( array_slice( $images, 0, Brands::MAX_AWARDS ), $this->brands->awards( $this->brand ) );
	}

	public function test_awards_empty_when_unset(): void {
		$this->assertSame( array(), $this->brands->awards( $this->brand ) );
	}

	public function test_binding_is_null_off_brand_pages_and_without_a_key(): void {
		$this->go_to( home_url( '/' ) );
		$this->assertNull( $this->brands->get_binding_value( array( 'key' => 'name' ), null, 'content' ) );

		$this->view_brand();
		$this->assertNull( $this->brands->get_binding_value( array(), null, 'content' ) );
	}

	public function test_binding_content_is_escaped_with_line_breaks(): void {
		$this->meta( 'intro', "Fine <wine>\nSince 1691" );
		$this->view_brand();

		$this->assertSame( "Fine &lt;wine&gt;<br />\nSince 1691", $this->brands->get_binding_value( array( 'key' => 'intro' ), null, 'content' ) );
	}

	public function test_binding_url_returns_the_link_and_other_attributes_null(): void {
		$this->meta( 'website', 'https://laborie.co.za' );
		$this->view_brand();

		$this->assertSame( 'https://laborie.co.za', $this->brands->get_binding_value( array( 'key' => 'website' ), null, 'url' ) );
		$this->assertNull( $this->brands->get_binding_value( array( 'key' => 'website' ), null, 'rel' ) );
	}

	public function test_binding_logo_attributes(): void {
		$logo = $this->image( 'logo.jpg', ' Laborie crest ' );
		$this->meta( 'logo', $logo );
		$this->view_brand();

		$this->assertSame( wp_get_attachment_image_url( $logo, 'medium' ), $this->brands->get_binding_value( array( 'key' => 'logo' ), null, 'url' ) );
		$this->assertSame( $logo, $this->brands->get_binding_value( array( 'key' => 'logo' ), null, 'id' ) );
		$this->assertSame( 'Laborie crest', $this->brands->get_binding_value( array( 'key' => 'logo' ), null, 'alt' ) );
		$this->assertNull( $this->brands->get_binding_value( array( 'key' => 'logo' ), null, 'title' ) );
	}

	public function test_binding_logo_alt_falls_back_to_the_brand_name(): void {
		$this->meta( 'logo', $this->image() );
		$this->view_brand();

		$this->assertSame( 'Laborie logo', $this->brands->get_binding_value( array( 'key' => 'logo' ), null, 'alt' ) );
	}

	public function test_binding_without_a_logo_blanks_the_url_only(): void {
		$this->view_brand();

		$this->assertSame( '', $this->brands->get_binding_value( array( 'key' => 'logo' ), null, 'url' ) );
		$this->assertNull( $this->brands->get_binding_value( array( 'key' => 'logo' ), null, 'alt' ) );
	}

	private function bound_paragraph( string $key, string $source = Brands::SOURCE ): string {
		return sprintf(
			'<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"%s","args":{"key":"%s"}}}}} --><p>Placeholder</p><!-- /wp:paragraph -->',
			$source,
			$key
		);
	}

	public function test_blocks_bound_to_blank_fields_are_removed_on_brand_pages(): void {
		$this->meta( 'intro', 'Hello' );
		$this->view_brand();

		$this->assertStringContainsString( '>Hello</p>', do_blocks( $this->bound_paragraph( 'intro' ) ) );
		$this->assertSame( '', trim( do_blocks( $this->bound_paragraph( 'facebook' ) ) ) );
	}

	public function test_blocks_keep_their_placeholder_off_brand_pages(): void {
		$this->go_to( home_url( '/' ) );

		$this->assertStringContainsString( '>Placeholder</p>', do_blocks( $this->bound_paragraph( 'facebook' ) ) );
	}

	public function test_blocks_bound_to_other_sources_are_left_alone(): void {
		$this->view_brand();
		$block = array(
			'attrs' => array(
				'metadata' => array(
					'bindings' => array(
						'content' => array(
							'source' => 'core/post-meta',
							'args'   => array( 'key' => 'x' ),
						),
						'bad',
					),
				),
			),
		);

		$this->assertSame( '<p>x</p>', $this->brands->hide_blocks_bound_to_empty_fields( '<p>x</p>', $block ) );
		$this->assertSame( '<p>x</p>', $this->brands->hide_blocks_bound_to_empty_fields( '<p>x</p>', array( 'attrs' => array() ) ) );
		$this->assertSame( '', $this->brands->hide_blocks_bound_to_empty_fields( '', $block ) );
	}

	public function test_awards_block_renders_each_image_in_a_labelled_group(): void {
		$first  = $this->image( 'a.jpg', 'Gold medal' );
		$second = $this->image( 'b.jpg' );
		$this->meta( 'awards', array( $first, $second ) );
		$this->view_brand();

		$html = do_blocks( '<!-- wp:kwv/brand-awards /-->' );

		$this->assertMatchesRegularExpression( '/^<div class="kwv-brand-awards wp-block-kwv-brand-awards"/', $html );
		$this->assertStringContainsString( 'role="group"', $html );
		$this->assertStringContainsString( 'aria-label="Awards and accolades"', $html );
		$this->assertSame( 2, substr_count( $html, '<figure class="wp-block-image size-medium kwv-brand-awards__item' ) );
		$this->assertStringContainsString( 'wp-image-' . $first, $html );
		$this->assertStringContainsString( 'wp-image-' . $second, $html );
		$this->assertStringContainsString( 'alt="Gold medal"', $html );
		$this->assertLessThan( strpos( $html, 'wp-image-' . $second ), strpos( $html, 'wp-image-' . $first ) );
	}

	public function test_awards_block_renders_nothing_without_awards_on_the_front_end(): void {
		$this->view_brand();

		$this->assertSame( '', trim( do_blocks( '<!-- wp:kwv/brand-awards /-->' ) ) );
	}
}
