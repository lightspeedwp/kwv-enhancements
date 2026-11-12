<?php
/**
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements\Tests\Unit;

use Brain\Monkey\Functions;
use KWV\Enhancements\Careers;
use KWV\Enhancements\Events;
use Mockery;

/**
 * Query-time and scheduling branches of the shared post-type base. The real
 * expiry against a database is in the integration suite.
 *
 * @covers \KWV\Enhancements\PostTypeModule
 */
final class PostTypeModuleTest extends TestCase {

	/**
	 * A WP_Query double whose get()/set() read and write $vars.
	 *
	 * @param array $vars     Query vars.
	 * @param bool  $singular Whether the query is singular.
	 * @return \Mockery\MockInterface
	 */
	private function query( array &$vars, bool $singular = false ) {
		$query = Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_singular' )->andReturn( $singular );
		$query->shouldReceive( 'get' )->andReturnUsing(
			static function ( $key ) use ( &$vars ) {
				return $vars[ $key ] ?? '';
			}
		);
		$query->shouldReceive( 'set' )->andReturnUsing(
			static function ( $key, $value ) use ( &$vars ) {
				$vars[ $key ] = $value;
			}
		);
		return $query;
	}

	public function test_careers_hides_expired_items_from_listings(): void {
		Functions\when( 'add_shortcode' )->justReturn( true );

		( new Careers() )->register();

		$this->assertNotFalse( has_action( 'pre_get_posts', 'KWV\Enhancements\Careers->exclude_expired()' ) );
	}

	public function test_events_do_not_hide_expired_items_at_query_time(): void {
		( new Events() )->register();

		$this->assertFalse( has_action( 'pre_get_posts', 'KWV\Enhancements\Events->exclude_expired()' ) );
		$this->assertNotFalse( has_action( Events::EXPIRY_HOOK, 'KWV\Enhancements\Events->expire()' ) );
	}

	public function test_exclude_expired_leaves_admin_queries_alone(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		$vars = array( 'post_type' => 'kwv_career' );

		( new Careers() )->exclude_expired( $this->query( $vars ) );

		$this->assertArrayNotHasKey( 'meta_query', $vars );
	}

	public function test_exclude_expired_leaves_singular_queries_alone(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		$vars = array( 'post_type' => 'kwv_career' );

		( new Careers() )->exclude_expired( $this->query( $vars, true ) );

		$this->assertArrayNotHasKey( 'meta_query', $vars );
	}

	public function test_exclude_expired_honours_the_opt_out_query_var(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		$vars = array(
			'post_type'           => 'kwv_career',
			'kwv_include_expired' => true,
		);

		( new Careers() )->exclude_expired( $this->query( $vars ) );

		$this->assertArrayNotHasKey( 'meta_query', $vars );
	}

	/**
	 * @dataProvider other_post_types
	 *
	 * @param mixed $post_type The query's post_type var.
	 */
	public function test_exclude_expired_ignores_other_and_mixed_post_type_queries( $post_type ): void {
		Functions\when( 'is_admin' )->justReturn( false );
		$vars = array( 'post_type' => $post_type );

		( new Careers() )->exclude_expired( $this->query( $vars ) );

		$this->assertArrayNotHasKey( 'meta_query', $vars );
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public function other_post_types(): array {
		return array(
			'posts'        => array( 'post' ),
			'mixed'        => array( array( 'kwv_career', 'post' ) ),
			'default/none' => array( '' ),
		);
	}

	public function test_exclude_expired_keeps_undated_and_current_items(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'current_time' )->justReturn( '20261112' );
		$vars = array( 'post_type' => 'kwv_career' );

		( new Careers() )->exclude_expired( $this->query( $vars ) );

		$clause = $vars['meta_query'][0];
		$this->assertSame( 'OR', $clause['relation'] );
		$this->assertSame( 'NOT EXISTS', $clause[0]['compare'] );
		$this->assertSame( '', $clause[1]['value'] );
		$this->assertSame( 20261112, $clause[2]['value'] );
		$this->assertSame( '>=', $clause[2]['compare'] );
		$this->assertSame( 'NUMERIC', $clause[2]['type'] );
	}

	public function test_exclude_expired_ands_with_an_existing_meta_query(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'current_time' )->justReturn( '20261112' );
		$existing = array(
			array(
				'key'   => 'kwv_career_location',
				'value' => 'Paarl',
			),
		);
		$vars     = array(
			'post_type'  => array( 'kwv_career' ),
			'meta_query' => $existing,
		);

		( new Careers() )->exclude_expired( $this->query( $vars ) );

		$this->assertSame( 'AND', $vars['meta_query']['relation'] );
		$this->assertSame( $existing, $vars['meta_query'][0] );
		$this->assertSame( 'OR', $vars['meta_query'][1]['relation'] );
	}

	public function test_schedule_expiry_is_a_no_op_when_already_hourly(): void {
		Functions\when( 'wp_get_scheduled_event' )->justReturn( (object) array( 'schedule' => 'hourly' ) );
		Functions\expect( 'wp_schedule_event' )->never();
		Functions\expect( 'wp_unschedule_event' )->never();

		( new Careers() )->schedule_expiry();
	}

	public function test_schedule_expiry_replaces_a_legacy_daily_schedule(): void {
		Functions\when( 'wp_get_scheduled_event' )->justReturn( (object) array( 'schedule' => 'daily' ) );
		Functions\when( 'wp_next_scheduled' )->justReturn( 123 );
		Functions\expect( 'wp_unschedule_event' )->once()->with( 123, Careers::EXPIRY_HOOK );
		Functions\expect( 'wp_schedule_event' )->once()->with( Mockery::type( 'int' ), 'hourly', Careers::EXPIRY_HOOK );

		( new Careers() )->schedule_expiry();
	}

	public function test_schedule_expiry_schedules_when_missing(): void {
		Functions\when( 'wp_get_scheduled_event' )->justReturn( false );
		Functions\expect( 'wp_unschedule_event' )->never();
		Functions\expect( 'wp_schedule_event' )->once()->with( Mockery::type( 'int' ), 'hourly', Events::EXPIRY_HOOK );

		( new Events() )->schedule_expiry();
	}

	public function test_unschedule_expiry_does_nothing_when_not_scheduled(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_unschedule_event' )->never();

		( new Careers() )->unschedule_expiry();
	}

	/**
	 * @dataProvider expiry_dates
	 *
	 * @param string $stored  Stored Ymd (or '').
	 * @param bool   $expired Expected result.
	 */
	public function test_is_expired_only_after_the_date( string $stored, bool $expired ): void {
		Functions\when( 'current_time' )->justReturn( '20261112' );
		Functions\when( 'get_post_meta' )->justReturn( $stored );

		$this->assertSame( $expired, ( new Careers() )->is_expired( 7 ) );
	}

	/**
	 * @return array<string,array{0:string,1:bool}>
	 */
	public function expiry_dates(): array {
		return array(
			'no date'   => array( '', false ),
			'yesterday' => array( '20261111', true ),
			'today'     => array( '20261112', false ),
			'tomorrow'  => array( '20261113', false ),
			'garbage'   => array( 'soon', false ),
		);
	}
}
