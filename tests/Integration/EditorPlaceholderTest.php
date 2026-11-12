<?php
/**
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements\Tests\Integration;

use KWV\Enhancements\Brands;
use KWV\Enhancements\EventAccommodation;

/**
 * The editor placeholders the dynamic blocks return when they have nothing to
 * show. The editor previews blocks over REST, which wp_is_serving_rest_request()
 * detects from the REST_REQUEST constant; a constant cannot be undefined, so
 * each test runs in its own process.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 *
 * @covers \KWV\Enhancements\Brands
 * @covers \KWV\Enhancements\EventAccommodation
 */
final class EditorPlaceholderTest extends TestCase {

	public function test_brand_awards_placeholder(): void {
		define( 'REST_REQUEST', true );

		$html = ( new Brands() )->render_awards();

		$this->assertStringContainsString( 'kwv-brand-awards is-placeholder', $html );
		$this->assertStringContainsString( '<strong>Brand awards</strong>', $html );
	}

	public function test_event_accommodation_placeholder(): void {
		define( 'REST_REQUEST', true );
		$id = self::factory()->post->create( array( 'post_type' => 'kwv_event' ) );

		$html = ( new EventAccommodation() )->render( array(), '', new \WP_Block( array( 'blockName' => 'kwv/event-accommodation' ), array( 'postId' => $id ) ) );

		$this->assertStringContainsString( 'kwv-event-accommodation is-placeholder', $html );
		$this->assertStringContainsString( '<strong>Accommodation nearby</strong>', $html );
	}
}
