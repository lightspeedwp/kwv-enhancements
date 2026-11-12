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

	public function test_post_types_are_public_and_in_rest(): void {
		foreach ( array(
			'kwv_career' => 'careers',
			'kwv_event'  => 'events',
		) as $type => $slug ) {
			$object = get_post_type_object( $type );

			$this->assertNotNull( $object, $type );
			$this->assertTrue( $object->public, $type );
			$this->assertTrue( $object->show_in_rest, $type );
			$this->assertFalse( $object->has_archive, $type );
			$this->assertSame( $slug, $object->rewrite['slug'], $type );
		}
	}

	public function test_event_brand_taxonomy_is_registered_for_events(): void {
		$taxonomy = get_taxonomy( Events::BRAND_TAXONOMY );

		$this->assertNotFalse( $taxonomy );
		$this->assertTrue( $taxonomy->hierarchical );
		$this->assertTrue( $taxonomy->show_in_rest );
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
		$this->assertEqualSets( array_keys( $events->default_brands() ), $slugs );
		$this->assertEquals( 1, get_option( Events::BRANDS_SEEDED_OPTION ) );

		// A brand deleted by an editor is not recreated.
		wp_delete_term( get_term_by( 'slug', 'house-of-fire', Events::BRAND_TAXONOMY )->term_id, Events::BRAND_TAXONOMY );
		$events->maybe_seed_brands();
		$this->assertFalse( get_term_by( 'slug', 'house-of-fire', Events::BRAND_TAXONOMY ) );
	}

	public function test_activate_registers_everything_and_seeds(): void {
		delete_option( Events::BRANDS_SEEDED_OPTION );

		( new Events() )->activate();

		$this->assertEquals( 1, get_option( Events::BRANDS_SEEDED_OPTION ) );
		$this->assertNotFalse( wp_next_scheduled( Events::EXPIRY_HOOK ) );
	}
}
