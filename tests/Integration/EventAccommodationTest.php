<?php
/**
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements\Tests\Integration;

use KWV\Enhancements\EventAccommodation;

/**
 * The "Accommodation nearby" repeater and its kwv/event-accommodation block.
 *
 * @covers \KWV\Enhancements\EventAccommodation
 */
final class EventAccommodationTest extends TestCase {

	/** @var EventAccommodation */
	private $module;

	public function set_up(): void {
		parent::set_up();
		$this->module = new EventAccommodation();
	}

	/**
	 * An event with SCF-style repeater rows.
	 *
	 * @param array[] $rows Each: url, title, image.
	 * @return int
	 */
	private function event( array $rows ): int {
		$meta = array( EventAccommodation::FIELD => count( $rows ) );

		foreach ( array_values( $rows ) as $i => $row ) {
			foreach ( $row as $key => $value ) {
				$meta[ EventAccommodation::FIELD . '_' . $i . '_' . $key ] = $value;
			}
		}

		return self::factory()->post->create(
			array(
				'post_type'  => 'kwv_event',
				'meta_input' => $meta,
			)
		);
	}

	public function test_block_is_registered(): void {
		$this->assertTrue( \WP_Block_Type_Registry::get_instance()->is_registered( 'kwv/event-accommodation' ) );
	}

	public function test_items_keep_valid_rows_and_drop_incomplete_or_unsafe_ones(): void {
		$image = $this->image();
		$id    = $this->event(
			array(
				array(
					'url'   => ' https://www.airbnb.co.za/rooms/1 ',
					'title' => ' Cottage ',
					'image' => $image,
				),
				array(
					'url'   => '',
					'title' => 'No link',
				),
				array(
					'url'   => 'https://example.com',
					'title' => '  ',
				),
				array(
					'url'   => 'javascript:alert(1)',
					'title' => 'Bad',
				),
				array(
					'url'   => 'https://www.booking.com/hotel/x',
					'title' => 'Hotel',
					'image' => $this->pdf(),
				),
			)
		);

		$this->assertSame(
			array(
				array(
					'url'      => 'https://www.airbnb.co.za/rooms/1',
					'title'    => 'Cottage',
					'image_id' => $image,
					'platform' => 'Airbnb',
				),
				array(
					'url'      => 'https://www.booking.com/hotel/x',
					'title'    => 'Hotel',
					'image_id' => 0,
					'platform' => 'Booking.com',
				),
			),
			$this->module->items( $id )
		);
	}

	public function test_items_empty_without_rows(): void {
		$this->assertSame( array(), $this->module->items( self::factory()->post->create( array( 'post_type' => 'kwv_event' ) ) ) );
	}

	/**
	 * @dataProvider platforms
	 *
	 * @param string $url   Listing URL.
	 * @param string $label Expected label.
	 */
	public function test_platform_label( string $url, string $label ): void {
		$this->assertSame( $label, $this->module->platform_label( $url ) );
	}

	public static function platforms(): array {
		return array(
			'booking.com'     => array( 'https://www.booking.com/hotel/za/x.html', 'Booking.com' ),
			'airbnb regional' => array( 'https://www.airbnb.co.za/rooms/1', 'Airbnb' ),
			'airbnb'          => array( 'https://airbnb.com/rooms/1', 'Airbnb' ),
			'lekkeslaap'      => array( 'https://www.lekkeslaap.co.za/akkommodasie/x', 'LekkeSlaap' ),
			'upper case host' => array( 'https://WWW.BOOKING.COM/x', 'Booking.com' ),
			'other site'      => array( 'https://www.paarlguesthouse.co.za/', 'paarlguesthouse.co.za' ),
			'no host'         => array( 'not a url', '' ),
		);
	}

	public function test_block_renders_a_labelled_section_with_a_card_per_item(): void {
		$image = $this->image();
		$id    = $this->event(
			array(
				array(
					'url'   => 'https://www.airbnb.co.za/rooms/1',
					'title' => 'Cottage <A>',
					'image' => $image,
				),
				array(
					'url'   => 'https://www.lekkeslaap.co.za/x',
					'title' => 'Farm stay',
				),
			)
		);

		$html = ( new \WP_Block(
			array(
				'blockName' => 'kwv/event-accommodation',
				'attrs'     => array(),
			),
			array( 'postId' => $id )
		) )->render();

		$this->assertMatchesRegularExpression( '/<section [^>]*class="[^"]*kwv-event-accommodation[^"]*" aria-labelledby="(kwv-event-accommodation-\d+)"/', $html );
		preg_match( '/aria-labelledby="([^"]+)"/', $html, $label );
		$this->assertStringContainsString( 'id="' . $label[1] . '">Accommodation nearby</h2>', $html );
		$this->assertSame( 2, substr_count( $html, 'is-style-event-card' ) );
		$this->assertStringContainsString( 'Cottage &lt;A&gt;', $html );
		$this->assertStringContainsString( 'via Airbnb', $html );
		$this->assertStringContainsString( 'via LekkeSlaap', $html );
		$this->assertStringContainsString( 'wp-image-' . $image, $html );
		$this->assertStringContainsString( 'loading="lazy"', $html );
		$this->assertStringContainsString( 'kwv-event-accommodation__placeholder', $html, 'The imageless card gets a placeholder panel.' );
		$this->assertStringContainsString( 'rel="noopener noreferrer nofollow"', $html );
		$this->assertStringContainsString( '(opens in a new tab)', $html );
	}

	public function test_block_renders_nothing_for_an_event_without_items(): void {
		$id = self::factory()->post->create( array( 'post_type' => 'kwv_event' ) );

		$this->assertSame( '', $this->module->render( array(), '', new \WP_Block( array( 'blockName' => 'kwv/event-accommodation' ), array( 'postId' => $id ) ) ) );
	}
}
