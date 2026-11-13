<?php
/**
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements\Tests\Integration;

use KWV\Enhancements\Careers;

/**
 * Job details, open/closed state, the Easy Apply link and the application panel.
 *
 * @covers \KWV\Enhancements\Careers
 */
final class CareersTest extends TestCase {

	/** @var Careers */
	private $careers;

	public function set_up(): void {
		parent::set_up();
		$this->careers = new Careers();
	}

	private function job( array $meta = array(), string $status = 'publish' ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'kwv_career',
				'post_status' => $status,
				'post_title'  => 'Cellar Hand',
				'meta_input'  => $meta,
			)
		);
	}

	public function test_form_id_comes_from_the_option(): void {
		delete_option( Careers::FORM_OPTION );
		$this->assertSame( 0, $this->careers->get_form_id() );

		update_option( Careers::FORM_OPTION, '12' );
		$this->assertSame( 12, $this->careers->get_form_id() );
	}

	public function test_job_details_reads_every_field(): void {
		$id = $this->job(
			array(
				'kwv_career_reference'    => 'BMMS0128',
				'kwv_career_location'     => 'Paarl',
				'kwv_career_department'   => 'Production',
				'kwv_career_closing_date' => '20261231',
			)
		);

		$this->assertSame(
			array(
				'job_id'         => (string) $id,
				'job_title'      => 'Cellar Hand',
				'job_ref'        => 'BMMS0128',
				'job_url'        => get_permalink( $id ),
				'job_location'   => 'Paarl',
				'job_department' => 'Production',
				'job_closing'    => '31 December 2026',
			),
			$this->careers->job_details( $id )
		);
	}

	public function test_job_details_blank_closing_date(): void {
		$this->assertSame( '', $this->careers->job_details( $this->job() )['job_closing'] );
	}

	public function test_job_details_empty_for_anything_but_a_job(): void {
		$this->assertSame( array(), $this->careers->job_details( 0 ) );
		$this->assertSame( array(), $this->careers->job_details( self::factory()->post->create() ) );
	}

	public function test_is_open_only_for_published_jobs_not_past_their_date(): void {
		$this->assertTrue( $this->careers->is_open( $this->job( array( 'kwv_career_closing_date' => $this->ymd( 0 ) ) ) ) );
		$this->assertTrue( $this->careers->is_open( $this->job() ) );
		$this->assertFalse( $this->careers->is_open( $this->job( array( 'kwv_career_closing_date' => $this->ymd( -1 ) ) ) ) );
		$this->assertFalse( $this->careers->is_open( $this->job( array(), 'draft' ) ) );
		$this->assertFalse( $this->careers->is_open( self::factory()->post->create() ) );
	}

	public function test_linkedin_url_must_be_a_valid_url(): void {
		$id = $this->job();
		update_post_meta( $id, Careers::LINKEDIN_META, 'https://www.linkedin.com/jobs/view/1' );
		$this->assertSame( 'https://www.linkedin.com/jobs/view/1', $this->careers->linkedin_url( $id ) );

		// Bypass the meta sanitiser to simulate a bad stored value.
		$GLOBALS['wpdb']->update(
			$GLOBALS['wpdb']->postmeta,
			array( 'meta_value' => 'not a url' ),
			array(
				'post_id'  => $id,
				'meta_key' => Careers::LINKEDIN_META,
			)
		);
		wp_cache_delete( $id, 'post_meta' );
		$this->assertSame( '', $this->careers->linkedin_url( $id ) );
	}

	public function test_easy_apply_button_opens_linkedin_in_a_new_tab(): void {
		$id = $this->job( array( Careers::LINKEDIN_META => 'https://www.linkedin.com/jobs/view/1?a=1&b=2' ) );

		$html = $this->careers->easy_apply_html( $id );

		$this->assertStringContainsString( 'href="https://www.linkedin.com/jobs/view/1?a=1&#038;b=2"', $html );
		$this->assertStringContainsString( 'target="_blank" rel="noopener noreferrer"', $html );
		$this->assertStringContainsString( '(opens in a new tab)', $html );
		$this->assertSame( '', $this->careers->easy_apply_html( $this->job() ) );
	}

	public function test_closed_jobs_show_a_closed_notice_instead_of_the_form(): void {
		$html = $this->careers->apply_panel_html( $this->job( array( 'kwv_career_closing_date' => $this->ymd( -1 ) ) ) );

		$this->assertStringContainsString( 'Applications for this position have closed.', $html );
		$this->assertStringNotContainsString( 'gform', $html );
	}

	public function test_open_jobs_show_easy_apply_and_no_form_when_none_is_configured(): void {
		delete_option( Careers::FORM_OPTION );
		$html = $this->careers->apply_panel_html( $this->job( array( Careers::LINKEDIN_META => 'https://linkedin.com/j/1' ) ) );

		$this->assertStringStartsWith( '<div class="kwv-career-apply"><div class="kwv-career-easy-apply">', $html );
		$this->assertStringNotContainsString( 'closed', $html );
	}

	public function test_form_html_is_empty_without_a_form(): void {
		$this->assertSame( '', $this->careers->form_html( 0 ) );
	}

	public function test_form_html_renders_the_gravity_form(): void {
		$this->requireGravityForms();
		$form_id = \GFAPI::add_form(
			array(
				'title'  => 'Test',
				'fields' => array(),
			)
		);

		$this->assertStringContainsString( 'gform_wrapper', $this->careers->form_html( (int) $form_id ) );
	}

	public function test_shortcode_resolves_the_job_from_the_attribute_or_the_queried_post(): void {
		$closed = $this->job( array( 'kwv_career_closing_date' => $this->ymd( -1 ) ) );

		$this->assertStringContainsString( 'have closed', do_shortcode( '[kwv_career_form id="' . $closed . '"]' ) );

		$this->go_to( get_permalink( $closed ) );
		$this->assertStringContainsString( 'have closed', do_shortcode( '[kwv_career_form]' ) );
	}

	public function test_shortcode_block_is_expanded_only_for_the_career_form(): void {
		$closed = $this->job( array( 'kwv_career_closing_date' => $this->ymd( -1 ) ) );

		$this->assertStringContainsString(
			'have closed',
			$this->careers->render_form_block( 'raw', array( 'innerHTML' => ' [kwv_career_form id="' . $closed . '"] ' ) )
		);
		$this->assertSame( 'raw', $this->careers->render_form_block( 'raw', array( 'innerHTML' => '[gallery]' ) ) );
		$this->assertSame( 'raw', $this->careers->render_form_block( 'raw', array( 'innerHTML' => '[kwv_career_form] [gallery]' ) ) );
		$this->assertSame( 'raw', $this->careers->render_form_block( 'raw', array() ) );
	}

	public function test_form_style_is_enqueued_on_single_jobs_only(): void {
		$GLOBALS['wp_styles'] = null;
		$this->go_to( home_url( '/' ) );
		$this->careers->enqueue_form_style();
		$this->assertFalse( wp_style_is( Careers::STYLE_HANDLE ) );

		$this->go_to( get_permalink( $this->job() ) );
		$GLOBALS['wp_styles'] = null;
		$this->careers->enqueue_form_style();
		$this->assertTrue( wp_style_is( Careers::STYLE_HANDLE ) );
	}

	public function test_asset_version_is_the_file_mtime_or_the_plugin_version(): void {
		$this->assertSame( (string) filemtime( KWV_ENH_DIR . 'assets/careers-form.css' ), Careers::asset_version( 'assets/careers-form.css' ) );
		$this->assertSame( KWV_ENH_VERSION, Careers::asset_version( 'assets/missing.css' ) );
	}

	public function test_shortcode_without_an_id_uses_the_job_being_viewed(): void {
		delete_option( Careers::FORM_OPTION );
		$open = $this->job( array( Careers::LINKEDIN_META => 'https://linkedin.com/j/1' ) );
		$this->go_to( get_permalink( $open ) );

		$html = do_shortcode( '[kwv_career_form]' );

		$this->assertStringNotContainsString( 'have closed', $html );
		$this->assertStringContainsString( 'kwv-career-easy-apply', $html );
	}

	public function test_shortcode_with_an_explicit_id_ignores_the_page(): void {
		$closed = $this->job( array( 'kwv_career_closing_date' => $this->ymd( -1 ) ) );
		$this->go_to( get_permalink( $this->job() ) );

		$this->assertStringContainsString( 'have closed', do_shortcode( '[kwv_career_form id="' . $closed . '"]' ) );
	}

	public function test_easy_apply_comes_before_the_form(): void {
		$this->requireGravityForms();
		update_option(
			Careers::FORM_OPTION,
			(int) \GFAPI::add_form(
				array(
					'title'  => 'T',
					'fields' => array(),
				)
			)
		);

		$html = $this->careers->apply_panel_html( $this->job( array( Careers::LINKEDIN_META => 'https://linkedin.com/j/1' ) ) );

		$this->assertLessThan( strpos( $html, 'gform_wrapper' ), strpos( $html, 'kwv-career-easy-apply' ) );
	}

	public function test_asset_version_resolves_paths_from_the_plugin_folder(): void {
		$cwd = getcwd();
		chdir( sys_get_temp_dir() );

		try {
			$this->assertSame( (string) filemtime( KWV_ENH_DIR . 'assets/careers-form.css' ), Careers::asset_version( 'assets/careers-form.css' ) );
		} finally {
			chdir( $cwd );
		}
	}

	public function test_form_style_points_at_the_plugin_stylesheet(): void {
		$GLOBALS['wp_styles'] = null;
		$this->careers->enqueue_styles();

		$this->assertSame( KWV_ENH_URL . 'assets/careers-form.css', wp_styles()->registered[ Careers::STYLE_HANDLE ]->src );
	}

	public function test_shortcode_prefers_the_loop_post_over_the_queried_page(): void {
		delete_option( Careers::FORM_OPTION );
		$closed = $this->job( array( 'kwv_career_closing_date' => $this->ymd( -1 ) ) );
		$open   = $this->job();
		$this->go_to( get_permalink( $closed ) );

		// A query loop on the page sets up each job as the current post.
		$GLOBALS['post'] = get_post( $open );
		setup_postdata( $GLOBALS['post'] );

		$this->assertStringNotContainsString( 'have closed', do_shortcode( '[kwv_career_form]' ) );
	}

	public function test_form_is_rendered_without_its_title_or_description(): void {
		$this->requireGravityForms();
		$form_id = (int) \GFAPI::add_form(
			array(
				'title'       => 'Unique Form Title 123',
				'description' => 'Unique form description 456',
				'fields'      => array(),
			)
		);

		$html = $this->careers->form_html( $form_id );

		$this->assertStringContainsString( 'gform_wrapper', $html );
		$this->assertStringNotContainsString( 'Unique Form Title 123', $html );
		$this->assertStringNotContainsString( 'Unique form description 456', $html );
	}

	/**
	 * On the job page the form posts normally and doesn't set tab order; the
	 * modal asks for AJAX explicitly.
	 */
	public function test_form_defaults_to_a_normal_post_without_tabindex(): void {
		$this->requireGravityForms();
		$form_id = (int) \GFAPI::add_form(
			array(
				'title'  => 'T',
				'fields' => array(
					array(
						'id'    => 1,
						'type'  => 'text',
						'label' => 'Name',
					),
				),
			)
		);

		$page  = $this->careers->form_html( $form_id );
		$modal = $this->careers->form_html( $form_id, true );

		$this->assertStringNotContainsString( 'tabindex', $page );
		$this->assertGreaterThan( substr_count( $page, 'gform_ajax' ), substr_count( $modal, 'gform_ajax' ), 'Only the modal form submits over AJAX.' );
	}
}
