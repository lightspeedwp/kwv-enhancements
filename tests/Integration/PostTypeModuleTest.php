<?php
/**
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements\Tests\Integration;

use KWV\Enhancements\Careers;
use KWV\Enhancements\Events;

/**
 * Careers and Events against real WordPress: post types, REST meta, the expiry
 * cron and the listing filter.
 *
 * @covers \KWV\Enhancements\PostTypeModule
 * @covers \KWV\Enhancements\Careers
 * @covers \KWV\Enhancements\Events
 */
final class PostTypeModuleTest extends TestCase {

	private function job( ?int $closing_offset_days = null, string $status = 'publish' ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'kwv_career',
				'post_status' => $status,
			)
		);

		if ( null !== $closing_offset_days ) {
			update_post_meta( $id, 'kwv_career_closing_date', $this->ymd( $closing_offset_days ) );
		}

		return $id;
	}

	/**
	 * The post types are registered while the plugin loads; register them
	 * again here so the arguments themselves are under test.
	 *
	 * @dataProvider post_types
	 *
	 * @param string $module Module class.
	 * @param string $type   Post type.
	 * @param string $slug   Rewrite slug.
	 * @param string $name   Plural label.
	 */
	public function test_post_type_arguments( string $module, string $type, string $slug, string $name ): void {
		unregister_post_type( $type );
		( new $module() )->register_post_type();

		$object = get_post_type_object( $type );

		$this->assertNotNull( $object, $type );
		$this->assertTrue( $object->public );
		$this->assertTrue( $object->show_in_rest );
		$this->assertFalse( $object->has_archive );
		$this->assertFalse( $object->hierarchical );
		$this->assertSame( $slug, $object->rewrite['slug'] );
		$this->assertFalse( $object->rewrite['with_front'] );
		$this->assertSame( $name, $object->labels->name );
		$this->assertSame( 'post', $object->capability_type );
		foreach ( array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'revisions' ) as $feature ) {
			$this->assertTrue( post_type_supports( $type, $feature ), "{$type} supports {$feature}" );
		}
	}

	public static function post_types(): array {
		return array(
			'careers' => array( Careers::class, 'kwv_career', 'careers', 'Careers' ),
			'events'  => array( Events::class, 'kwv_event', 'events', 'Events' ),
		);
	}

	public function test_admin_menu_positions_and_event_taxonomy(): void {
		unregister_post_type( 'kwv_career' );
		unregister_post_type( 'kwv_event' );
		( new Careers() )->register_post_type();
		( new Events() )->register_post_type();

		$this->assertSame( 20, get_post_type_object( 'kwv_career' )->menu_position );
		$this->assertSame( 21, get_post_type_object( 'kwv_event' )->menu_position );
		$this->assertSame( array( Events::BRAND_TAXONOMY ), get_post_type_object( 'kwv_event' )->taxonomies );
	}

	public function test_default_brands_are_the_three_venues(): void {
		$this->assertSame(
			array(
				'kwv-emporium'     => 'KWV Emporium',
				'house-of-fire'    => 'House of Fire',
				'cathedral-cellar' => 'Cathedral Cellar',
			),
			( new Events() )->default_brands()
		);
	}

	public function test_event_brand_taxonomy_arguments(): void {
		unregister_taxonomy( Events::BRAND_TAXONOMY );
		( new Events() )->register_taxonomy();

		$taxonomy = get_taxonomy( Events::BRAND_TAXONOMY );

		$this->assertNotFalse( $taxonomy );
		$this->assertTrue( $taxonomy->public );
		$this->assertTrue( $taxonomy->hierarchical );
		$this->assertTrue( $taxonomy->show_in_rest );
		$this->assertTrue( $taxonomy->show_ui );
		$this->assertTrue( $taxonomy->show_admin_column );
		$this->assertSame( 'brand', $taxonomy->rewrite['slug'] );
		$this->assertSame( 'Brands', $taxonomy->labels->name );
		$this->assertSame( array( 'kwv_event' ), $taxonomy->object_type );
	}

	/**
	 * @dataProvider meta_keys
	 *
	 * @param string $type Post type.
	 * @param string $key  Meta key.
	 */
	public function test_meta_is_registered_single_string_and_in_rest( string $type, string $key ): void {
		$registered = get_registered_meta_keys( 'post', $type );

		$this->assertArrayHasKey( $key, $registered );
		$this->assertSame( 'string', $registered[ $key ]['type'] );
		$this->assertTrue( $registered[ $key ]['single'] );
		$this->assertTrue( $registered[ $key ]['show_in_rest'] );
	}

	public static function meta_keys(): array {
		return array(
			array( 'kwv_career', 'kwv_career_reference' ),
			array( 'kwv_career', 'kwv_career_location' ),
			array( 'kwv_career', 'kwv_career_department' ),
			array( 'kwv_career', 'kwv_career_closing_date' ),
			array( 'kwv_career', Careers::LINKEDIN_META ),
			array( 'kwv_event', 'kwv_event_date' ),
			array( 'kwv_event', 'kwv_event_time' ),
		);
	}

	public function test_text_meta_is_sanitised_and_the_linkedin_url_is_url_sanitised(): void {
		$id = $this->job();

		update_post_meta( $id, 'kwv_career_location', "<b>Paarl</b>\n" );
		update_post_meta( $id, Careers::LINKEDIN_META, 'javascript:alert(1)' );

		$this->assertSame( 'Paarl', get_post_meta( $id, 'kwv_career_location', true ) );
		$this->assertSame( '', get_post_meta( $id, Careers::LINKEDIN_META, true ) );

		update_post_meta( $id, Careers::LINKEDIN_META, 'https://www.linkedin.com/jobs/view/1' );
		$this->assertSame( 'https://www.linkedin.com/jobs/view/1', get_post_meta( $id, Careers::LINKEDIN_META, true ) );
	}

	public function test_meta_can_only_be_edited_through_rest_by_editors(): void {
		$id = $this->job();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( current_user_can( 'edit_post_meta', $id, 'kwv_career_reference' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertTrue( current_user_can( 'edit_post_meta', $id, 'kwv_career_reference' ) );
	}

	public function test_meta_is_exposed_in_the_rest_response(): void {
		$id = $this->job( 5 );
		update_post_meta( $id, 'kwv_career_reference', 'BMMS0128' );

		$response = rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/kwv_career/' . $id ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'BMMS0128', $response->get_data()['meta']['kwv_career_reference'] );
		$this->assertSame( $this->ymd( 5 ), $response->get_data()['meta']['kwv_career_closing_date'] );
	}

	public function test_expiry_crons_are_scheduled_hourly(): void {
		foreach ( array( Careers::EXPIRY_HOOK, Events::EXPIRY_HOOK ) as $hook ) {
			$event = wp_get_scheduled_event( $hook );

			$this->assertNotFalse( $event, $hook );
			$this->assertSame( 'hourly', $event->schedule, $hook );
		}
	}

	public function test_deactivate_clears_and_activate_restores_the_cron(): void {
		$careers = new Careers();

		$careers->deactivate();
		$this->assertFalse( wp_next_scheduled( Careers::EXPIRY_HOOK ) );

		$careers->activate();
		$this->assertSame( 'hourly', wp_get_scheduled_event( Careers::EXPIRY_HOOK )->schedule );
	}

	public function test_expire_drafts_only_jobs_whose_date_has_passed(): void {
		$yesterday = $this->job( -1 );
		$long_ago  = $this->job( -400 );
		$today     = $this->job( 0 );
		$tomorrow  = $this->job( 1 );
		$undated   = $this->job();
		$drafted   = $this->job( -3, 'draft' );

		do_action( Careers::EXPIRY_HOOK );

		$this->assertSame( 'draft', get_post_status( $yesterday ) );
		$this->assertSame( 'draft', get_post_status( $long_ago ) );
		$this->assertSame( 'publish', get_post_status( $today ), 'A job stays open on its closing date.' );
		$this->assertSame( 'publish', get_post_status( $tomorrow ) );
		$this->assertSame( 'publish', get_post_status( $undated ) );
		$this->assertSame( 'draft', get_post_status( $drafted ) );
	}

	public function test_expire_drafts_past_events(): void {
		$past   = self::factory()->post->create( array( 'post_type' => 'kwv_event' ) );
		$future = self::factory()->post->create( array( 'post_type' => 'kwv_event' ) );
		update_post_meta( $past, 'kwv_event_date', $this->ymd( -1 ) );
		update_post_meta( $future, 'kwv_event_date', $this->ymd( 0 ) );

		do_action( Events::EXPIRY_HOOK );

		$this->assertSame( 'draft', get_post_status( $past ) );
		$this->assertSame( 'publish', get_post_status( $future ) );
	}

	public function test_job_listings_hide_closed_jobs_before_the_cron_runs(): void {
		$closed  = $this->job( -1 );
		$open    = $this->job( 0 );
		$undated = $this->job();
		$blank   = $this->job();
		update_post_meta( $blank, 'kwv_career_closing_date', '' );

		$ids = ( new \WP_Query(
			array(
				'post_type' => 'kwv_career',
				'fields'    => 'ids',
			)
		) )->posts;

		$this->assertEqualSets( array( $open, $undated, $blank ), $ids );
		$this->assertNotContains( $closed, $ids );
	}

	public function test_a_closed_job_is_still_reachable_on_its_own_page(): void {
		$closed = $this->job( -1 );

		$this->go_to( get_permalink( $closed ) );

		$this->assertTrue( is_singular( 'kwv_career' ) );
		$this->assertSame( $closed, get_queried_object_id() );
	}

	public function test_event_listings_still_include_past_events_until_the_cron_runs(): void {
		$past = self::factory()->post->create( array( 'post_type' => 'kwv_event' ) );
		update_post_meta( $past, 'kwv_event_date', $this->ymd( -1 ) );

		$ids = get_posts(
			array(
				'post_type' => 'kwv_event',
				'fields'    => 'ids',
			)
		);

		$this->assertContains( $past, $ids );
	}

	public function test_seeding_creates_the_venue_brands_once(): void {
		delete_option( Events::BRANDS_SEEDED_OPTION );
		foreach ( get_terms(
			array(
				'taxonomy'   => Events::BRAND_TAXONOMY,
				'hide_empty' => false,
			)
		) as $term ) {
			wp_delete_term( $term->term_id, Events::BRAND_TAXONOMY );
		}

		$events = new Events();
		$events->maybe_seed_brands();

		$slugs = wp_list_pluck(
			get_terms(
				array(
					'taxonomy'   => Events::BRAND_TAXONOMY,
					'hide_empty' => false,
				)
			),
			'slug'
		);
		$this->assertEqualSets( array( 'kwv-emporium', 'house-of-fire', 'cathedral-cellar' ), $slugs );
		$this->assertEquals( 1, get_option( Events::BRANDS_SEEDED_OPTION ) );

		// A brand deleted by an editor is not recreated.
		wp_delete_term( get_term_by( 'slug', 'house-of-fire', Events::BRAND_TAXONOMY )->term_id, Events::BRAND_TAXONOMY );
		$events->maybe_seed_brands();
		$this->assertFalse( get_term_by( 'slug', 'house-of-fire', Events::BRAND_TAXONOMY ) );
	}

	public function test_activate_registers_everything_and_seeds(): void {
		delete_option( Events::BRANDS_SEEDED_OPTION );
		wp_clear_scheduled_hook( Events::EXPIRY_HOOK );
		unregister_post_type( 'kwv_event' );
		unregister_taxonomy( Events::BRAND_TAXONOMY );

		( new Events() )->activate();

		$this->assertTrue( post_type_exists( 'kwv_event' ) );
		$this->assertTrue( taxonomy_exists( Events::BRAND_TAXONOMY ) );
		$this->assertEquals( 1, get_option( Events::BRANDS_SEEDED_OPTION ) );
		$this->assertNotFalse( wp_next_scheduled( Events::EXPIRY_HOOK ) );
	}

	public function test_careers_activation_registers_the_post_type_for_the_rewrite_flush(): void {
		unregister_post_type( 'kwv_career' );
		wp_clear_scheduled_hook( Careers::EXPIRY_HOOK );

		( new Careers() )->activate();

		$this->assertTrue( post_type_exists( 'kwv_career' ) );
		$this->assertSame( 'hourly', wp_get_scheduled_event( Careers::EXPIRY_HOOK )->schedule );
	}

	public function test_scheduling_again_keeps_the_existing_hourly_event(): void {
		wp_clear_scheduled_hook( Careers::EXPIRY_HOOK );
		wp_schedule_event( 1000, 'hourly', Careers::EXPIRY_HOOK );

		( new Careers() )->schedule_expiry();

		$this->assertSame( 1000, wp_next_scheduled( Careers::EXPIRY_HOOK ), 'The existing event is not replaced.' );
	}
}
