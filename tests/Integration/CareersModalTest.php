<?php
/**
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements\Tests\Integration;

use KWV\Enhancements\Careers;
use KWV\Enhancements\CareersModal;
use KWV\Enhancements\Tests\Support\Snapshots;

/**
 * The careers listing modal: trigger tagging and the footer dialog.
 *
 * @covers \KWV\Enhancements\CareersModal
 */
final class CareersModalTest extends TestCase {

	use Snapshots;

	/** @var CareersModal */
	private $modal;

	public function set_up(): void {
		parent::set_up();
		// Gravity Forms (when present) provisions the careers form on install;
		// tests that want one set it themselves.
		delete_option( Careers::FORM_OPTION );
		$GLOBALS['wp_styles']  = null;
		$GLOBALS['wp_scripts'] = null;
		$this->modal           = new CareersModal( new Careers() );
		$this->go_to( home_url( '/' ) );
	}

	private function job( array $meta = array() ): int {
		return self::factory()->post->create(
			array(
				'post_type'    => 'kwv_career',
				'post_title'   => 'Cellar Hand',
				'post_content' => '<!-- wp:paragraph --><p>Role description</p><!-- /wp:paragraph -->',
				'meta_input'   => $meta,
			)
		);
	}

	private function instance( int $post_id ): \WP_Block {
		return new \WP_Block( array( 'blockName' => 'core/read-more' ), array( 'postId' => $post_id ) );
	}

	private function read_more( int $post_id ): string {
		return $this->modal->tag_trigger( '<a class="wp-block-read-more" href="/careers/x/">Read more</a>', array( 'blockName' => 'core/read-more' ), $this->instance( $post_id ) );
	}

	private function dialog(): string {
		ob_start();
		$this->modal->render_dialog();
		return (string) ob_get_clean();
	}

	public function test_read_more_links_on_jobs_open_the_dialog(): void {
		$id   = $this->job();
		$html = $this->read_more( $id );

		$this->assertStringContainsString( 'data-kwv-career-open="' . $id . '"', $html );
		$this->assertStringContainsString( 'aria-haspopup="dialog"', $html );
		$this->assertStringContainsString( 'aria-controls="' . CareersModal::DIALOG_ID . '"', $html );
		$this->assertTrue( wp_script_is( 'kwv-careers-modal' ) );
		$this->assertTrue( wp_style_is( 'kwv-careers-modal' ) );
		$this->assertTrue( wp_style_is( Careers::STYLE_HANDLE ) );
	}

	public function test_post_titles_are_only_tagged_when_linked(): void {
		$id      = $this->job();
		$content = '<h2><a href="/careers/x/">Cellar Hand</a></h2>';

		$this->assertSame(
			$content,
			$this->modal->tag_trigger(
				$content,
				array(
					'blockName' => 'core/post-title',
					'attrs'     => array(),
				),
				$this->instance( $id )
			)
		);
		$this->assertStringContainsString(
			'data-kwv-career-open',
			$this->modal->tag_trigger(
				$content,
				array(
					'blockName' => 'core/post-title',
					'attrs'     => array( 'isLink' => true ),
				),
				$this->instance( $id )
			)
		);
	}

	public function test_non_jobs_missing_context_and_linkless_markup_are_left_alone(): void {
		$post = self::factory()->post->create();
		$html = '<a href="/x/">Read more</a>';
		$read = array( 'blockName' => 'core/read-more' );

		$this->assertSame( $html, $this->modal->tag_trigger( $html, $read, $this->instance( $post ) ) );
		$this->assertSame( $html, $this->modal->tag_trigger( $html, $read, $this->instance( 0 ) ) );
		$this->assertSame( $html, $this->modal->tag_trigger( $html, $read, null ) );
		$this->assertSame( '<span>Read more</span>', $this->modal->tag_trigger( '<span>Read more</span>', $read, $this->instance( $this->job() ) ) );
	}

	public function test_nothing_is_tagged_on_a_single_job_page(): void {
		$id = $this->job();
		$this->go_to( get_permalink( $id ) );

		$this->assertStringNotContainsString( 'data-kwv-career-open', $this->read_more( $id ) );
	}

	public function test_no_dialog_without_a_tagged_job(): void {
		$this->assertSame( '', $this->dialog() );
	}

	public function test_dialog_holds_one_escaped_template_per_job(): void {
		$open   = $this->job(
			array(
				'kwv_career_reference' => 'R1',
				'kwv_career_location'  => 'Paarl & Wellington',
				Careers::LINKEDIN_META => 'https://linkedin.com/j/1',
			)
		);
		$closed = $this->job( array( 'kwv_career_closing_date' => $this->ymd( -1 ) ) );
		$this->read_more( $open );
		$this->read_more( $open ); // Tagged twice, rendered once.
		$this->read_more( $closed );

		$html = $this->dialog();

		$this->assertStringContainsString( '<dialog id="' . CareersModal::DIALOG_ID . '"', $html );
		$this->assertSame( 1, substr_count( $html, 'id="kwv-career-tpl-' . $open . '"' ) );
		$this->assertStringContainsString( 'id="kwv-career-tpl-' . $closed . '" data-kwv-form="0" data-kwv-closed="1"', $html );
		$this->assertStringContainsString( 'id="kwv-career-tpl-' . $open . '" data-kwv-form="0" data-kwv-closed="0"', $html );
		$this->assertStringContainsString( '>Cellar Hand</h2>', $html );
		$this->assertStringContainsString( 'Ref: R1', $html );
		$this->assertStringContainsString( '<dt>Location</dt><dd>Paarl &amp; Wellington</dd>', $html );
		$this->assertStringNotContainsString( '<dt>Department</dt>', $html );
		$this->assertStringContainsString( 'Role description', $html );
		$this->assertStringContainsString( 'kwv-career-easy-apply', $html );
		$this->assertStringNotContainsString( 'data-kwv-career-form=', $html, 'No form is configured.' );
	}

	public function test_dialog_includes_the_form_once_with_its_job_id_input(): void {
		$this->requireGravityForms();
		$form_id = (int) \GFAPI::add_form(
			array(
				'title'  => 'Careers',
				'fields' => array(
					array(
						'id'        => 5,
						'type'      => 'hidden',
						'label'     => 'Job ID',
						'inputName' => 'job_id',
					),
				),
			)
		);
		update_option( Careers::FORM_OPTION, $form_id );
		$this->read_more( $this->job() );
		$this->read_more( $this->job() );

		$html = $this->dialog();

		$this->assertSame( 1, substr_count( $html, 'data-kwv-career-form="' . $form_id . '"' ) );
		$this->assertStringContainsString( 'data-kwv-job-input="input_5"', $html );
	}

	public function test_dialog_markup_matches_snapshot(): void {
		$open   = $this->job(
			array(
				'kwv_career_reference'    => 'BMMS0128',
				'kwv_career_location'     => 'Paarl',
				'kwv_career_department'   => 'Production',
				'kwv_career_closing_date' => '20991231',
				Careers::LINKEDIN_META    => 'https://linkedin.com/j/1',
			)
		);
		$closed = $this->job( array( 'kwv_career_closing_date' => '20200101' ) );
		$this->read_more( $open );
		$this->read_more( $closed );

		$this->assertMatchesSnapshot(
			$this->withStableIds(
				$this->dialog(),
				array(
					'OPEN_JOB'   => $open,
					'CLOSED_JOB' => $closed,
				)
			),
			'careers-dialog.html'
		);
	}

	public function test_modal_assets_point_at_the_plugin_files_and_defer(): void {
		$this->read_more( $this->job() );

		$this->assertSame( KWV_ENH_URL . 'assets/careers-modal.css', wp_styles()->registered['kwv-careers-modal']->src );
		$this->assertSame( array( Careers::STYLE_HANDLE ), wp_styles()->registered['kwv-careers-modal']->deps );
		$script = wp_scripts()->registered['kwv-careers-modal'];
		$this->assertSame( KWV_ENH_URL . 'assets/careers-modal.js', $script->src );
		$this->assertSame( 'defer', $script->extra['strategy'] ?? null );
		$this->assertSame( 1, $script->extra['group'] ?? null, 'Loaded in the footer.' );
	}

	public function test_markup_without_a_link_loads_nothing_and_adds_no_dialog(): void {
		$this->modal->tag_trigger( '<span>Read more</span>', array( 'blockName' => 'core/read-more' ), $this->instance( $this->job() ) );

		$this->assertFalse( wp_script_is( 'kwv-careers-modal' ) );
		$this->assertSame( '', $this->dialog() );
	}

	public function test_the_forms_own_scripts_are_queued_when_a_job_is_tagged(): void {
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

		$this->read_more( $this->job() );

		$this->assertTrue( wp_script_is( 'gform_gravityforms' ) || wp_script_is( 'gform_gravityforms_theme' ), 'Gravity Forms scripts are queued in the head.' );
	}

	public function test_nothing_is_tagged_in_wp_admin(): void {
		set_current_screen( 'edit-page' );

		try {
			$this->assertStringNotContainsString( 'data-kwv-career-open', $this->read_more( $this->job() ) );
		} finally {
			set_current_screen( 'front' );
		}
	}
}
