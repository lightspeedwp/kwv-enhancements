<?php
/**
 * Careers module — the kwv_career job post type, its custom fields, the
 * closing-date auto-expiry, and the per-job application-form resolution.
 *
 * Fields are edited with Secure Custom Fields (field group in /acf-json) but
 * their meta keys are registered here with register_post_meta so they are
 * REST-exposed and available to the theme's core/post-meta block bindings.
 * Field NAMES deliberately match these meta keys so SCF writes to the same key.
 *
 * @package KWV_Enhancements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const KWV_CAREERS_EXPIRY_HOOK = 'kwv_careers_expire_jobs';

/**
 * Register the Careers post type.
 *
 * Slug (kwv_career), rewrite (careers/{slug}) and REST exposure are unchanged
 * from the original kwv-careers plugin so existing jobs and URLs keep working.
 */
function kwv_careers_register_post_type() {

	$labels = array(
		'name'               => _x( 'Careers', 'Post type general name', 'kwv-enhancements' ),
		'singular_name'      => _x( 'Job', 'Post type singular name', 'kwv-enhancements' ),
		'menu_name'          => _x( 'Careers', 'Admin Menu text', 'kwv-enhancements' ),
		'name_admin_bar'     => _x( 'Job', 'Add New on Toolbar', 'kwv-enhancements' ),
		'add_new'            => __( 'Add New', 'kwv-enhancements' ),
		'add_new_item'       => __( 'Add New Job', 'kwv-enhancements' ),
		'new_item'           => __( 'New Job', 'kwv-enhancements' ),
		'edit_item'          => __( 'Edit Job', 'kwv-enhancements' ),
		'view_item'          => __( 'View Job', 'kwv-enhancements' ),
		'all_items'          => __( 'All Jobs', 'kwv-enhancements' ),
		'search_items'       => __( 'Search Jobs', 'kwv-enhancements' ),
		'not_found'          => __( 'No jobs found.', 'kwv-enhancements' ),
		'not_found_in_trash' => __( 'No jobs found in Trash.', 'kwv-enhancements' ),
		'archives'           => __( 'Job Archives', 'kwv-enhancements' ),
		'item_published'     => __( 'Job published.', 'kwv-enhancements' ),
		'item_updated'       => __( 'Job updated.', 'kwv-enhancements' ),
	);

	$args = array(
		'labels'          => $labels,
		'public'          => true,
		'show_in_rest'    => true,
		'menu_icon'       => 'dashicons-businessperson',
		'menu_position'   => 20,
		'has_archive'     => false,
		'hierarchical'    => false,
		'supports'        => array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'revisions' ),
		'rewrite'         => array(
			'slug'       => 'careers',
			'with_front' => false,
		),
		'capability_type' => 'post',
	);

	register_post_type( 'kwv_career', $args );
}
add_action( 'init', 'kwv_careers_register_post_type' );

/**
 * Register the Careers meta fields.
 *
 * REST-exposed so the block editor and core/post-meta block bindings (used by
 * the theme's careers patterns) can read them. SCF supplies the editor UI; the
 * field names in the SCF group match these keys, so SCF writes here directly.
 */
function kwv_careers_register_meta() {

	$fields = array(
		'kwv_career_reference'    => __( 'The job reference number, e.g. BMMS0128.', 'kwv-enhancements' ),
		'kwv_career_location'     => __( 'Where the role is based, e.g. Paarl, Western Cape.', 'kwv-enhancements' ),
		'kwv_career_department'   => __( 'The department the role sits in.', 'kwv-enhancements' ),
		'kwv_career_closing_date' => __( 'Closing date (stored as Ymd). The job auto-expires to Draft after this date.', 'kwv-enhancements' ),
		'kwv_career_form_id'      => __( 'Gravity Forms form ID to embed on this job. Empty falls back to the shared application form.', 'kwv-enhancements' ),
	);

	foreach ( $fields as $key => $description ) {
		register_post_meta(
			'kwv_career',
			$key,
			array(
				'type'              => 'string',
				'description'       => $description,
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}
}
add_action( 'init', 'kwv_careers_register_meta' );

/**
 * Populate the "Application form" SCF select with the site's Gravity Forms.
 *
 * The field group in /acf-json declares this select with empty choices; the
 * live list of forms is environment-specific, so it is injected at load time.
 *
 * @param array $field The ACF/SCF field being loaded.
 * @return array The field with a choices map of form-id => title.
 */
function kwv_careers_load_form_choices( $field ) {

	$field['choices'] = array();

	if ( class_exists( 'GFAPI' ) ) {
		foreach ( GFAPI::get_forms() as $form ) {
			$field['choices'][ (string) $form['id'] ] = sprintf( '%s (#%d)', $form['title'], $form['id'] );
		}
	}

	return $field;
}
add_filter( 'acf/load_field/name=kwv_career_form_id', 'kwv_careers_load_form_choices' );

/**
 * Resolve which Gravity Forms form a job should embed.
 *
 * Per-job kwv_career_form_id wins; otherwise the shared form installed by this
 * plugin (option kwv_careers_form_id) is used.
 *
 * @param int $post_id Job post ID.
 * @return int The form ID to render (0 if none is available).
 */
function kwv_careers_get_form_id( $post_id ) {

	$per_job = (int) get_post_meta( $post_id, 'kwv_career_form_id', true );

	if ( $per_job > 0 ) {
		return $per_job;
	}

	return (int) get_option( 'kwv_careers_form_id', 0 );
}

/**
 * Render the application form for the current (or given) job.
 *
 * Usable as the [kwv_career_form] shortcode so the single-job template can
 * embed whichever form the job resolves to at render time.
 *
 * @param array $atts Shortcode attributes: 'id' to force a specific job ID.
 * @return string The Gravity Forms markup, or an empty string.
 */
function kwv_careers_render_form( $atts = array() ) {

	if ( ! function_exists( 'gravity_form' ) ) {
		return '';
	}

	$atts    = shortcode_atts( array( 'id' => get_the_ID() ), $atts, 'kwv_career_form' );
	$form_id = kwv_careers_get_form_id( (int) $atts['id'] );

	if ( $form_id < 1 ) {
		return '';
	}

	return gravity_form( $form_id, false, false, false, null, true, 0, false );
}
add_shortcode( 'kwv_career_form', 'kwv_careers_render_form' );

/**
 * Schedule the daily job-expiry check.
 */
function kwv_careers_schedule_expiry_event() {
	if ( ! wp_next_scheduled( KWV_CAREERS_EXPIRY_HOOK ) ) {
		wp_schedule_event( time(), 'daily', KWV_CAREERS_EXPIRY_HOOK );
	}
}

/**
 * Clear the daily job-expiry check.
 */
function kwv_careers_unschedule_expiry_event() {
	$timestamp = wp_next_scheduled( KWV_CAREERS_EXPIRY_HOOK );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, KWV_CAREERS_EXPIRY_HOOK );
	}
}

// Self-heal the schedule for existing installs (e.g. after a plugin update).
add_action( 'init', 'kwv_careers_schedule_expiry_event' );

/**
 * Move any published job whose closing date has passed to Draft.
 *
 * Closing dates are stored by SCF's date picker as Ymd (e.g. 20260731). A job
 * expires the day AFTER its closing date, so the role is still live on the
 * closing day itself. Drafting (rather than trashing) is reversible and keeps
 * the job out of the front-end query, which only shows published posts.
 */
function kwv_careers_expire_jobs() {

	$today = (int) current_time( 'Ymd' );

	$jobs = get_posts(
		array(
			'post_type'      => 'kwv_career',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'     => 'kwv_career_closing_date',
					'value'   => '',
					'compare' => '!=',
				),
			),
		)
	);

	foreach ( $jobs as $job_id ) {

		$closing = (int) get_post_meta( $job_id, 'kwv_career_closing_date', true );

		if ( $closing > 0 && $closing < $today ) {
			wp_update_post(
				array(
					'ID'          => $job_id,
					'post_status' => 'draft',
				)
			);
		}
	}
}
add_action( KWV_CAREERS_EXPIRY_HOOK, 'kwv_careers_expire_jobs' );
