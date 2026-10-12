<?php
/**
 * Careers — the job modal on the jobs board.
 *
 * On the Careers listing, a job's "Read More" link (and its linked title) opens
 * a modal instead of navigating: the job's title, reference and details, the
 * content KWV wrote for it, its LinkedIn Easy Apply button when it has one, and
 * the application form — the only way to apply when there is no Easy Apply
 * link.
 *
 * How it fits together:
 *
 * - Triggers. The theme's job card renders ordinary core/read-more and
 *   core/post-title links to the job. This module tags those links (only for
 *   kwv_career posts) with `data-kwv-career-open`, so without JavaScript — or
 *   on a modified click — they still go to the single-job page, which has
 *   everything the modal has.
 * - Content. Each job tagged on the page gets a <template> printed in the
 *   footer holding its modal content; the script clones the right one in.
 * - Form. Rendering the same Gravity Form once per job would duplicate its
 *   ids, so the dialog holds ONE copy of each form in use (normally just the
 *   shared application form), submitting over AJAX so the confirmation lands
 *   inside the modal. Opening a job sets the form's hidden Job ID field; the
 *   rest of the job's details are filled in server-side at submission
 *   ({@see ApplicationContext}).
 *
 * The plugin owns this (not the theme) because it renders the form and the job
 * data, and is deployable to every environment.
 *
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns the jobs board's links into a job modal.
 */
class CareersModal implements Module {

	/**
	 * The dialog element's id.
	 */
	public const DIALOG_ID = 'kwv-career-dialog';

	/**
	 * The Careers module, for job data and the apply panel.
	 *
	 * @var Careers
	 */
	private $careers;

	/**
	 * Job IDs tagged on this page (id => form id).
	 *
	 * @var array<int,int>
	 */
	private $jobs = array();

	/**
	 * @param Careers $careers The Careers module.
	 */
	public function __construct( Careers $careers ) {
		$this->careers = $careers;
	}

	/**
	 * Hook the link tagging and the footer output.
	 */
	public function register(): void {
		add_filter( 'render_block_core/read-more', array( $this, 'tag_trigger' ), 10, 3 );
		add_filter( 'render_block_core/post-title', array( $this, 'tag_trigger' ), 10, 3 );
		add_action( 'wp_footer', array( $this, 'render_dialog' ), 5 );
	}

	/**
	 * Tag a job's read-more / title link as a modal trigger.
	 *
	 * Block themes render the template before wp_head, so assets enqueued here
	 * still print in the head.
	 *
	 * @param string    $content  Rendered block markup.
	 * @param array     $block    Parsed block.
	 * @param \WP_Block $instance Block instance (carries the loop's postId context).
	 * @return string
	 */
	public function tag_trigger( $content, $block, $instance = null ) {

		if ( is_admin() || wp_is_json_request() || is_singular( 'kwv_career' ) || ! $instance instanceof \WP_Block ) {
			return $content;
		}

		$post_id = (int) ( $instance->context['postId'] ?? 0 );

		if ( $post_id < 1 || 'kwv_career' !== get_post_type( $post_id ) ) {
			return $content;
		}

		if ( 'core/post-title' === $block['blockName'] && empty( $block['attrs']['isLink'] ) ) {
			return $content;
		}

		$tags = new \WP_HTML_Tag_Processor( $content );

		if ( ! $tags->next_tag( 'a' ) ) {
			return $content;
		}

		$tags->set_attribute( 'data-kwv-career-open', (string) $post_id );
		$tags->set_attribute( 'aria-haspopup', 'dialog' );
		$tags->set_attribute( 'aria-controls', self::DIALOG_ID );

		$this->add_job( $post_id );

		return $tags->get_updated_html();
	}

	/**
	 * Record a job on this page and make sure the assets are queued.
	 *
	 * @param int $post_id Job post ID.
	 */
	private function add_job( int $post_id ): void {

		if ( isset( $this->jobs[ $post_id ] ) ) {
			return;
		}

		$form_id                = $this->careers->get_form_id();
		$this->jobs[ $post_id ] = $form_id;

		if ( 1 === count( $this->jobs ) ) {
			$this->enqueue_assets();
		}

		// Queue the form's own scripts/styles now, so they land in the head.
		if ( $form_id > 0 && function_exists( 'gravity_form_enqueue_scripts' ) ) {
			gravity_form_enqueue_scripts( $form_id, true );
		}
	}

	/**
	 * Enqueue the modal's stylesheet and script (plus the apply-panel styles).
	 */
	private function enqueue_assets(): void {

		$this->careers->enqueue_styles();

		wp_enqueue_style(
			'kwv-careers-modal',
			KWV_ENH_URL . 'assets/careers-modal.css',
			array( Careers::STYLE_HANDLE ),
			Careers::asset_version( 'assets/careers-modal.css' )
		);

		wp_enqueue_script(
			'kwv-careers-modal',
			KWV_ENH_URL . 'assets/careers-modal.js',
			array(),
			Careers::asset_version( 'assets/careers-modal.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Print the dialog and one content template per job, once, in the footer.
	 */
	public function render_dialog(): void {

		if ( ! $this->jobs ) {
			return;
		}

		$templates = '';
		foreach ( $this->jobs as $post_id => $form_id ) {
			$templates .= $this->job_template( $post_id, $form_id );
		}

		$forms = '';
		foreach ( array_unique( array_filter( $this->jobs ) ) as $form_id ) {
			$forms .= sprintf(
				'<div class="kwv-career-modal__form" data-kwv-career-form="%1$d" data-kwv-job-input="%2$s" hidden>%3$s</div>',
				$form_id,
				esc_attr( $this->job_id_input( $form_id ) ),
				$this->careers->form_html( $form_id, true )
			);
		}

		$close_icon = '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M18.3 5.7a1 1 0 0 0-1.4 0L12 10.6 7.1 5.7a1 1 0 0 0-1.4 1.4l4.9 4.9-4.9 4.9a1 1 0 1 0 1.4 1.4l4.9-4.9 4.9 4.9a1 1 0 0 0 1.4-1.4L13.4 12l4.9-4.9a1 1 0 0 0 0-1.4Z"/></svg>';

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- every dynamic part is escaped where it is built.
		printf(
			'<dialog id="%1$s" class="kwv-career-modal" aria-labelledby="kwv-career-modal-title">'
				. '<div class="kwv-career-modal__inner">'
				. '<button type="button" class="kwv-career-modal__close" data-kwv-career-close autofocus>%2$s<span class="kwv-sr-only">%3$s</span></button>'
				. '<div class="kwv-career-modal__job" data-kwv-career-slot="job"></div>'
				. '<section class="kwv-career-modal__apply kwv-career-apply" aria-labelledby="kwv-career-modal-apply">'
				. '<h3 id="kwv-career-modal-apply" class="kwv-career-modal__apply-heading">%4$s</h3>'
				. '<div data-kwv-career-slot="easy-apply"></div>'
				. '<p class="kwv-career-apply__closed" data-kwv-career-closed hidden>%5$s</p>'
				. '%6$s'
				. '</section>'
				. '</div>'
				. '</dialog>%7$s',
			esc_attr( self::DIALOG_ID ),
			$close_icon,
			esc_html__( 'Close', 'kwv-enhancements' ),
			esc_html__( 'Apply for this position', 'kwv-enhancements' ),
			esc_html__( 'Applications for this position have closed.', 'kwv-enhancements' ),
			$forms,
			$templates
		);
		// phpcs:enable
	}

	/**
	 * The `name` of a form's Job ID input (e.g. input_17), so the script can set
	 * it — or '' when the form has no job_id field.
	 *
	 * @param int $form_id Form ID.
	 * @return string
	 */
	private function job_id_input( int $form_id ): string {

		$form = class_exists( 'GFAPI' ) ? \GFAPI::get_form( $form_id ) : false;

		foreach ( (array) rgar( (array) $form, 'fields' ) as $field ) {
			if ( 'hidden' === $field->type && 'job_id' === $field->inputName ) {
				return 'input_' . $field->id;
			}
		}

		return '';
	}

	/**
	 * One job's modal content, as an inert <template>: the header (title, ref,
	 * details) and description in one part, the Easy Apply button in another.
	 *
	 * @param int $post_id Job post ID.
	 * @param int $form_id The form the job uses.
	 * @return string
	 */
	private function job_template( int $post_id, int $form_id ): string {

		$details = $this->careers->job_details( $post_id );

		$ref = '' !== $details['job_ref']
			? sprintf( '<p class="kwv-career-modal__ref">%s %s</p>', esc_html__( 'Ref:', 'kwv-enhancements' ), esc_html( $details['job_ref'] ) )
			: '';

		$meta = '';
		foreach (
			array(
				'job_location'   => __( 'Location', 'kwv-enhancements' ),
				'job_department' => __( 'Department', 'kwv-enhancements' ),
				'job_closing'    => __( 'Closing date', 'kwv-enhancements' ),
			) as $key => $label
		) {
			if ( '' !== $details[ $key ] ) {
				$meta .= sprintf( '<div><dt>%s</dt><dd>%s</dd></div>', esc_html( $label ), esc_html( $details[ $key ] ) );
			}
		}

		return sprintf(
			'<template id="kwv-career-tpl-%1$d" data-kwv-form="%2$d" data-kwv-closed="%3$s">'
				. '<div class="kwv-career-modal__job-inner" data-kwv-part="job">'
				. '<header class="kwv-career-modal__header"><h2 id="kwv-career-modal-title" class="kwv-career-modal__title">%4$s</h2>%5$s%6$s</header>'
				. '<div class="kwv-career-modal__content">%7$s</div>'
				. '</div>'
				. '<div data-kwv-part="easy-apply">%8$s</div>'
				. '</template>',
			$post_id,
			$form_id,
			$this->careers->is_open( $post_id ) ? '0' : '1',
			esc_html( $details['job_title'] ),
			$ref,
			'' !== $meta ? '<dl class="kwv-career-modal__meta">' . $meta . '</dl>' : '',
			$this->job_content( $post_id ),
			$this->careers->easy_apply_html( $post_id )
		);
	}

	/**
	 * A job's content, rendered as it would be on its own page.
	 *
	 * @param int $post_id Job post ID.
	 * @return string
	 */
	private function job_content( int $post_id ): string {

		global $post;

		$post = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored below.
		setup_postdata( $post );

		$content = (string) apply_filters( 'the_content', get_the_content( null, false, $post ) );

		wp_reset_postdata();

		return $content;
	}
}
