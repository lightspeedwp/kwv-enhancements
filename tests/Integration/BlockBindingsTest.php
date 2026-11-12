<?php
/**
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements\Tests\Integration;

use KWV\Enhancements\BlockBindings;

/**
 * The kwv/meta-date, kwv/meta-text and kwv/event-booking-url binding sources.
 *
 * @covers \KWV\Enhancements\BlockBindings
 */
final class BlockBindingsTest extends TestCase {

	/** @var BlockBindings */
	private $bindings;

	public function set_up(): void {
		parent::set_up();
		$this->bindings = new BlockBindings();
		update_option( 'date_format', 'j F Y' );
	}

	/**
	 * A block instance with the given post context.
	 *
	 * @param int|null $post_id    Post ID context.
	 * @param array    $attributes Block attributes.
	 * @return \WP_Block
	 */
	private function block( ?int $post_id, array $attributes = array() ): \WP_Block {
		$context = null === $post_id ? array() : array( 'postId' => $post_id );

		$block = new \WP_Block(
			array(
				'blockName' => 'core/button',
				'attrs'     => $attributes,
			)
		);

		// WP_Block keeps only the context its block type declares; a binding
		// source's own uses_context is added at render time, so set it here.
		$block->context = $context;

		return $block;
	}

	public function test_all_sources_are_registered_with_post_context(): void {
		$names = array( 'kwv/meta-date', 'kwv/meta-text', 'kwv/event-booking-url' );

		// Registered on init during bootstrap; register again here so the
		// registration itself is exercised (and measured).
		array_map( 'unregister_block_bindings_source', $names );
		$this->bindings->register_sources();

		foreach ( $names as $name ) {
			$source = get_block_bindings_source( $name );

			$this->assertNotNull( $source, $name );
			$this->assertSame( array( 'postId' ), $source->uses_context, $name );
		}
	}

	public function test_register_hooks_registration_on_init(): void {
		$this->bindings->register();

		$this->assertSame( 10, has_action( 'init', array( $this->bindings, 'register_sources' ) ) );
	}

	public function test_meta_date_formats_a_ymd_value_with_the_site_format(): void {
		$id = self::factory()->post->create( array( 'meta_input' => array( 'when' => '20260731' ) ) );

		$this->assertSame( '31 July 2026', $this->bindings->get_meta_date( array( 'key' => 'when' ), $this->block( $id ) ) );
	}

	public function test_meta_date_accepts_a_custom_format(): void {
		$id = self::factory()->post->create( array( 'meta_input' => array( 'when' => '20260731' ) ) );

		$this->assertSame(
			'2026-07-31',
			$this->bindings->get_meta_date(
				array(
					'key'    => 'when',
					'format' => 'Y-m-d',
				),
				$this->block( $id )
			)
		);
	}

	public function test_meta_date_shows_an_unparseable_value_as_stored(): void {
		$id = self::factory()->post->create( array( 'meta_input' => array( 'when' => 'TBC' ) ) );

		$this->assertSame( 'TBC', $this->bindings->get_meta_date( array( 'key' => 'when' ), $this->block( $id ) ) );
	}

	/**
	 * @dataProvider empty_inputs
	 *
	 * @param string $method Callback under test.
	 */
	public function test_sources_return_empty_for_missing_key_post_or_value( string $method ): void {
		$id = self::factory()->post->create();

		$this->assertSame( '', $this->bindings->$method( array(), $this->block( $id ) ), 'no key' );
		$this->assertSame( '', $this->bindings->$method( array( 'key' => '' ), $this->block( $id ) ), 'empty key' );
		$this->assertSame( '', $this->bindings->$method( array( 'key' => 'missing' ), $this->block( $id ) ), 'no value' );
		$this->assertSame( '', $this->bindings->$method( array( 'key' => 'x' ), $this->block( null ) ), 'no post' );
	}

	public static function empty_inputs(): array {
		return array(
			'meta-date' => array( 'get_meta_date' ),
			'meta-text' => array( 'get_meta_text' ),
		);
	}

	public function test_meta_text_returns_the_value_verbatim(): void {
		$id = self::factory()->post->create( array( 'meta_input' => array( 'kwv_edition' => 'Edition 7 & more' ) ) );

		$this->assertSame( 'Edition 7 & more', $this->bindings->get_meta_text( array( 'key' => 'kwv_edition' ), $this->block( $id ) ) );
	}

	public function test_meta_text_falls_back_to_the_loop_post(): void {
		$id = self::factory()->post->create( array( 'meta_input' => array( 'kwv_edition' => 'Loop' ) ) );
		$this->go_to( get_permalink( $id ) );
		the_post();

		$this->assertSame( 'Loop', $this->bindings->get_meta_text( array( 'key' => 'kwv_edition' ), $this->block( null ) ) );
	}

	public function test_meta_text_renders_through_a_bound_paragraph(): void {
		$id = self::factory()->post->create( array( 'meta_input' => array( 'kwv_edition' => 'Edition 7' ) ) );

		$block = new \WP_Block(
			parse_blocks( '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"kwv/meta-text","args":{"key":"kwv_edition"}}}}} --><p>Placeholder</p><!-- /wp:paragraph -->' )[0],
			array( 'postId' => $id )
		);

		$this->assertStringContainsString( '>Edition 7</p>', $block->render() );
	}

	public function test_booking_url_carries_the_event_label_and_permalink(): void {
		$id = self::factory()->post->create(
			array(
				'post_type'  => 'kwv_event',
				'post_title' => 'Jazz & Wine',
				'meta_input' => array(
					'kwv_event_date' => '20261215',
					'kwv_event_time' => '18:00',
				),
			)
		);

		$url = $this->bindings->get_event_booking_url( array( 'path' => '/visit/cellar/' ), $this->block( $id ) );

		$this->assertStringStartsWith( home_url( '/visit/cellar/' ) . '?', $url );
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( 'Jazz & Wine — 15 Dec 2026, 18:00', $query['event'] );
		$this->assertSame( get_permalink( $id ), $query['event_url'] );
	}

	public function test_booking_url_falls_back_to_the_buttons_saved_url(): void {
		$id = self::factory()->post->create( array( 'post_type' => 'kwv_event' ) );

		$url = $this->bindings->get_event_booking_url( array(), $this->block( $id, array( 'url' => 'https://book.example.com/form' ) ) );

		$this->assertStringStartsWith( 'https://book.example.com/form?event=', $url );
	}

	public function test_booking_url_is_null_without_an_event_or_a_target(): void {
		$id = self::factory()->post->create( array( 'post_type' => 'kwv_event' ) );

		$this->assertNull( $this->bindings->get_event_booking_url( array( 'path' => '/x/' ), $this->block( null ) ) );
		$this->assertNull( $this->bindings->get_event_booking_url( array(), $this->block( $id ) ) );
	}
}
