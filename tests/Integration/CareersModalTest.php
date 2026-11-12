<?php
/**
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements\Tests\Integration;

use KWV\Enhancements\Careers;
use KWV\Enhancements\CareersModal;

/**
 * The careers listing modal: trigger tagging and the footer dialog.
 *
 * @covers \KWV\Enhancements\CareersModal
 */
final class CareersModalTest extends TestCase {

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
}
