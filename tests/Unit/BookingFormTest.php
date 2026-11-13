<?php
/**
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements\Tests\Unit;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use KWV\Enhancements\BookingForm;

/**
 * The Gravity Forms side of the venue booking forms. Gravity Forms is not
 * available in CI, so forms are plain arrays of field objects here; the event
 * query itself is covered by the integration suite.
 *
 * @covers \KWV\Enhancements\BookingForm
 */
final class BookingFormTest extends TestCase {

	private const LABEL = 'Event you wish to book for';

	/** @var BookingForm|\Mockery\MockInterface */
	private $form;

	protected function set_up(): void {
		parent::set_up();
		$this->stubOptions(
			array(
				'kwv_forms_installer_ids' => array(
					'contact'                  => '3',
					'booking-emporium'         => '4',
					'booking-house-of-fire'    => '5',
					'booking-cathedral-cellar' => '6',
				),
			)
		);
		$this->form = \Mockery::mock( BookingForm::class )->makePartial()->shouldAllowMockingProtectedMethods();
	}

	/**
	 * A booking form with an event dropdown, a date, a time and a name field.
	 *
	 * @param int $id Form ID.
	 * @return array
	 */
	private function gf_form( int $id = 4 ): array {
		return array(
			'id'     => $id,
			'fields' => array(
				kwv_test_gf_field(
					array(
						'id'    => 1,
						'type'  => 'text',
						'label' => 'Name',
					)
				),
				kwv_test_gf_field(
					array(
						'id'    => 2,
						'type'  => 'select',
						'label' => self::LABEL,
					)
				),
				kwv_test_gf_field(
					array(
						'id'    => 7,
						'type'  => 'date',
						'label' => 'Date',
					)
				),
				kwv_test_gf_field(
					array(
						'id'    => 8,
						'type'  => 'time',
						'label' => 'Time',
					)
				),
			),
		);
	}

	private function choices( string ...$labels ): array {
		return array_map(
			static function ( $label ) {
				return array(
					'text'       => $label,
					'value'      => $label,
					'isSelected' => false,
				);
			},
			$labels
		);
	}

	public function test_hooks_are_only_added_when_gravity_forms_is_loaded(): void {
		$this->form->register_hooks();

		foreach ( array( 'gform_pre_render', 'gform_pre_validation', 'gform_pre_submission_filter' ) as $hook ) {
			$this->assertNotFalse( has_filter( $hook, array( $this->form, 'prepare' ) ), $hook );
		}
		$this->assertNotFalse( has_filter( 'gform_admin_pre_render', array( $this->form, 'populate' ) ) );
	}

	public function test_register_defers_hooks_to_init(): void {
		$form = new BookingForm();
		$form->register();

		$this->assertNotFalse( has_action( 'init', array( $form, 'register_hooks' ) ) );
	}

	/**
	 * @dataProvider venue_forms
	 *
	 * @param int    $form_id Form ID.
	 * @param string $slug    Expected brand slug.
	 */
	public function test_form_brand_slug_maps_installer_ids_to_brands( int $form_id, string $slug ): void {
		$this->assertSame( $slug, $this->form->form_brand_slug( $form_id ) );
	}

	public static function venue_forms(): array {
		return array(
			'emporium'         => array( 4, 'kwv-emporium' ),
			'house of fire'    => array( 5, 'house-of-fire' ),
			'cathedral cellar' => array( 6, 'cathedral-cellar' ),
			'contact form'     => array( 3, '' ),
			'unknown form'     => array( 99, '' ),
		);
	}

	public function test_form_brand_slug_is_empty_when_the_installer_option_is_missing(): void {
		$this->options = array( 'kwv_forms_installer_ids' => 'garbage' );

		$this->assertSame( '', $this->form->form_brand_slug( 4 ) );
	}

	public function test_form_brand_slug_can_be_overridden(): void {
		Filters\expectApplied( 'kwv_events_form_brand_slug' )->once()->with( '', 99 )->andReturn( 'kwv-emporium' );

		$this->assertSame( 'kwv-emporium', $this->form->form_brand_slug( 99 ) );
	}

	public function test_field_label_is_filterable(): void {
		$this->assertSame( self::LABEL, $this->form->field_label() );

		Filters\expectApplied( 'kwv_events_booking_field_label' )->andReturn( 'Pick an event' );
		$this->assertSame( 'Pick an event', $this->form->field_label() );
	}

	public function test_prepare_fills_the_dropdown_and_shows_date_time_only_without_an_event(): void {
		$this->form->allows( 'brand_choices' )->with( 'kwv-emporium' )->andReturn( $this->choices( 'Jazz — 1 Dec 2026' ) );

		$form = $this->form->prepare( $this->gf_form() );

		$this->assertSame( $this->choices( 'Jazz — 1 Dec 2026' ), $form['fields'][1]->choices );
		$this->assertSame( 'Select an event', $form['fields'][1]->placeholder );
		$this->assertObjectNotHasProperty( 'visibility', $form['fields'][1] );

		$logic = $this->form->date_time_logic( 2 );
		$this->assertSame( $logic, $form['fields'][2]->conditionalLogic );
		$this->assertSame( $logic, $form['fields'][3]->conditionalLogic );
		$this->assertObjectNotHasProperty( 'conditionalLogic', $form['fields'][0] );
		$this->assertSame( 'show', $logic['actionType'] );
		$this->assertSame( 'all', $logic['logicType'] );
		$this->assertSame(
			array(
				'fieldId'  => 2,
				'operator' => 'is',
				'value'    => '',
			),
			$logic['rules'][0]
		);
	}

	public function test_prepare_hides_the_dropdown_and_always_shows_date_time_when_nothing_is_scheduled(): void {
		$this->form->allows( 'brand_choices' )->andReturn( array() );

		$form = $this->form->prepare( $this->gf_form() );

		$this->assertSame( array(), $form['fields'][1]->choices );
		$this->assertSame( 'No events currently scheduled', $form['fields'][1]->placeholder );
		$this->assertSame( 'administrative', $form['fields'][1]->visibility );
		$this->assertSame( '', $form['fields'][2]->conditionalLogic );
		$this->assertSame( '', $form['fields'][3]->conditionalLogic );
	}

	public function test_populate_fills_choices_but_leaves_visibility_and_logic_for_the_admin(): void {
		$this->form->allows( 'brand_choices' )->andReturn( array() );

		$form = $this->form->populate( $this->gf_form() );

		$this->assertSame( 'No events currently scheduled', $form['fields'][1]->placeholder );
		$this->assertObjectNotHasProperty( 'visibility', $form['fields'][1] );
		$this->assertObjectNotHasProperty( 'conditionalLogic', $form['fields'][2] );
	}

	public function test_forms_that_are_not_venue_forms_are_untouched(): void {
		$this->form->shouldNotReceive( 'brand_choices' );
		$form = $this->gf_form( 3 );

		$this->assertSame( $form, $this->form->prepare( $form ) );
	}

	/**
	 * @dataProvider incomplete_forms
	 *
	 * @param mixed $form A form Gravity Forms might pass.
	 */
	public function test_incomplete_forms_are_returned_as_is( $form ): void {
		$this->form->shouldNotReceive( 'brand_choices' );

		$this->assertSame( $form, $this->form->prepare( $form ) );
	}

	public static function incomplete_forms(): array {
		return array(
			'no id'     => array( array( 'fields' => array( 1 ) ) ),
			'no fields' => array(
				array(
					'id'     => 4,
					'fields' => array(),
				),
			),
			'not array' => array( false ),
		);
	}

	public function test_a_venue_form_without_the_event_dropdown_keeps_its_date_time_logic(): void {
		$this->form->allows( 'brand_choices' )->andReturn( $this->choices( 'A' ) );
		$form                                = $this->gf_form();
		$form['fields'][1]->label            = 'Something else';
		$form['fields'][2]->conditionalLogic = 'kept';

		$form = $this->form->prepare( $form );

		$this->assertSame( 'kept', $form['fields'][2]->conditionalLogic );
		$this->assertObjectNotHasProperty( 'choices', $form['fields'][1] );
	}

	/**
	 * @dataProvider labels
	 *
	 * @param string $title    Event title as get_the_title() returns it.
	 * @param string $date     Stored Ymd date.
	 * @param string $time     Stored time.
	 * @param string $expected The dropdown label.
	 */
	public function test_choice_label( string $title, string $date, string $time, string $expected ): void {
		Functions\when( 'get_the_title' )->justReturn( $title );
		Functions\when( 'get_bloginfo' )->justReturn( 'UTF-8' );
		Functions\when( 'get_post_meta' )->alias(
			static function ( $id, $key ) use ( $date, $time ) {
				return 'kwv_event_date' === $key ? $date : $time;
			}
		);
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias(
			static function ( $format, $timestamp ) {
				return gmdate( $format, $timestamp );
			}
		);

		$this->assertSame( $expected, ( new BookingForm() )->choice_label( 10 ) );
	}

	/**
	 * The stored Ymd date is a calendar day in the site's timezone: it must be
	 * read as local midnight, not the current UTC time of day, or after 22:00
	 * UTC a South African site labels the event with the next day's date.
	 */
	public function test_choice_label_reads_the_date_as_local_midnight(): void {
		$stamps = array();

		Functions\when( 'get_the_title' )->justReturn( 'Jazz Night' );
		Functions\when( 'get_bloginfo' )->justReturn( 'UTF-8' );
		Functions\when( 'get_post_meta' )->alias(
			static function ( $id, $key ) {
				return 'kwv_event_date' === $key ? '20261215' : '';
			}
		);
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'Africa/Johannesburg' ) );
		Functions\when( 'wp_date' )->alias(
			static function ( $format, $timestamp ) use ( &$stamps ) {
				$stamps[] = $timestamp;
				return ( new \DateTime( '@' . $timestamp ) )->setTimezone( new \DateTimeZone( 'Africa/Johannesburg' ) )->format( $format );
			}
		);

		$this->assertSame( 'Jazz Night — 15 Dec 2026', ( new BookingForm() )->choice_label( 10 ) );
		$this->assertSame( array( strtotime( '2026-12-15 00:00:00 +02:00' ) ), $stamps );
	}

	public static function labels(): array {
		return array(
			'date and time'      => array( 'Jazz Night', '20261215', ' 18:00 ', 'Jazz Night — 15 Dec 2026, 18:00' ),
			'date only'          => array( 'Jazz Night', '20261215', '', 'Jazz Night — 15 Dec 2026' ),
			'time only'          => array( 'Jazz Night', '', '18:00', 'Jazz Night — 18:00' ),
			'neither'            => array( 'Jazz Night', '', '', 'Jazz Night' ),
			'unparseable date'   => array( 'Jazz Night', 'next week', '', 'Jazz Night — next week' ),
			'encoded ampersand'  => array( 'Wine &#038; Dine', '', '', 'Wine & Dine' ),
			'encoded apostrophe' => array( 'Winemaker&#8217;s Table', '', '', "Winemaker\u{2019}s Table" ),
		);
	}
}
