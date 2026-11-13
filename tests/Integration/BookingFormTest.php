<?php
/**
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements\Tests\Integration;

use KWV\Enhancements\BookingForm;
use KWV\Enhancements\Events;

/**
 * The venue dropdown's event query against real events and brand terms.
 *
 * @covers \KWV\Enhancements\BookingForm
 */
final class BookingFormTest extends TestCase {

	private function event( string $title, string $brand, string $date, string $status = 'publish' ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'kwv_event',
				'post_title'  => $title,
				'post_status' => $status,
				'meta_input'  => array( 'kwv_event_date' => $date ),
			)
		);
		wp_set_object_terms( $id, $brand, Events::BRAND_TAXONOMY );

		return $id;
	}

	public function test_brand_choices_lists_the_venues_published_events_by_date(): void {
		( new Events() )->maybe_seed_brands();
		$this->event( 'Later', 'kwv-emporium', '20270301' );
		$this->event( 'Sooner', 'kwv-emporium', '20270105' );
		$this->event( 'Elsewhere', 'house-of-fire', '20270101' );
		$this->event( 'Draft', 'kwv-emporium', '20270101', 'draft' );

		$choices = ( new BookingForm() )->brand_choices( 'kwv-emporium' );

		$this->assertSame( array( 'Sooner — 5 Jan 2027', 'Later — 1 Mar 2027' ), wp_list_pluck( $choices, 'text' ) );
		$this->assertSame( wp_list_pluck( $choices, 'text' ), wp_list_pluck( $choices, 'value' ) );
		$this->assertSame( array( false, false ), wp_list_pluck( $choices, 'isSelected' ) );
	}

	public function test_brand_choices_empty_for_a_venue_with_no_events(): void {
		$this->assertSame( array(), ( new BookingForm() )->brand_choices( 'cathedral-cellar' ) );
	}

	public function test_gravity_forms_hooks_are_registered_when_it_is_active(): void {
		$this->requireGravityForms();

		$this->assertTrue( has_filter( 'gform_pre_render' ) );
		$this->assertTrue( has_filter( 'gform_admin_pre_render' ) );
	}
}
