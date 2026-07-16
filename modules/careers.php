<?php
/**
 * Careers module — the kwv_career job post type, its custom fields, the
 * closing-date auto-expiry, and the per-job application-form resolution.
 *
 * The shared CPT/meta/expiry machinery lives in {@see PostTypeModule}; this
 * class adds the Careers-specific pieces: the SCF form-picker choices, the
 * per-job form resolution, the [kwv_career_form] shortcode, and the form
 * stylesheet.
 *
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Careers content type.
 */
class Careers extends PostTypeModule {

	/**
	 * Cron hook for the daily closing-date expiry check.
	 */
	public const EXPIRY_HOOK = 'kwv_careers_expire_jobs';

	/**
	 * Option holding the id of the shared "KWV Job Application" form.
	 */
	public const FORM_OPTION = 'kwv_careers_form_id';

	/**
	 * Wire the shared post-type behaviour, plus the Careers-specific hooks.
	 */
	public function register(): void {
		parent::register();

		add_filter( 'acf/load_field/name=kwv_career_form_id', array( $this, 'load_form_choices' ) );
		add_shortcode( 'kwv_career_form', array( $this, 'render_form' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_form_style' ) );
	}

	/**
	 * The Careers post-type slug.
	 *
	 * @return string
	 */
	protected function post_type(): string {
		return 'kwv_career';
	}

	/**
	 * @return string
	 */
	protected function expiry_hook(): string {
		return self::EXPIRY_HOOK;
	}

	/**
	 * @return string
	 */
	protected function expiry_meta_key(): string {
		return 'kwv_career_closing_date';
	}

	/**
	 * Post-type arguments.
	 *
	 * Slug (kwv_career), rewrite (careers/{slug}) and REST exposure are unchanged
	 * from the original kwv-careers plugin so existing jobs and URLs keep working.
	 *
	 * @return array
	 */
	protected function post_type_args(): array {

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

		return array(
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
	}

	/**
	 * The Careers meta fields (meta key => description).
	 *
	 * SCF supplies the editor UI; the field names in the SCF group match these
	 * keys, so SCF writes here directly and the theme's bindings can read them.
	 *
	 * @return array<string,string>
	 */
	protected function meta_fields(): array {
		return array(
			'kwv_career_reference'    => __( 'The job reference number, e.g. BMMS0128.', 'kwv-enhancements' ),
			'kwv_career_location'     => __( 'Where the role is based, e.g. Paarl, Western Cape.', 'kwv-enhancements' ),
			'kwv_career_department'   => __( 'The department the role sits in.', 'kwv-enhancements' ),
			'kwv_career_closing_date' => __( 'Closing date (stored as Ymd). The job auto-expires to Draft after this date.', 'kwv-enhancements' ),
			'kwv_career_form_id'      => __( 'Gravity Forms form ID to embed on this job. Empty falls back to the shared application form.', 'kwv-enhancements' ),
		);
	}

	/**
	 * Populate the "Application form" SCF select with the site's Gravity Forms.
	 *
	 * The field group in /acf-json declares this select with empty choices; the
	 * live list of forms is environment-specific, so it is injected at load time.
	 *
	 * @param array $field The ACF/SCF field being loaded.
	 * @return array The field with a choices map of form-id => title.
	 */
	public function load_form_choices( $field ) {

		$field['choices'] = array();

		if ( class_exists( 'GFAPI' ) ) {
			foreach ( \GFAPI::get_forms() as $form ) {
				$field['choices'][ (string) $form['id'] ] = sprintf( '%s (#%d)', $form['title'], $form['id'] );
			}
		}

		return $field;
	}

	/**
	 * Resolve which Gravity Forms form a job should embed.
	 *
	 * Per-job kwv_career_form_id wins; otherwise the shared form installed by
	 * {@see ApplicationForm} (stored in the FORM_OPTION option) is used.
	 *
	 * @param int $post_id Job post ID.
	 * @return int The form ID to render (0 if none is available).
	 */
	public function get_form_id( $post_id ) {

		$per_job = (int) get_post_meta( $post_id, 'kwv_career_form_id', true );

		if ( $per_job > 0 ) {
			return $per_job;
		}

		return (int) get_option( self::FORM_OPTION, 0 );
	}

	/**
	 * Render the application form for the current (or given) job.
	 *
	 * Registered as the [kwv_career_form] shortcode so the single-job template
	 * can embed whichever form the job resolves to at render time.
	 *
	 * @param array $atts Shortcode attributes: 'id' to force a specific job ID.
	 * @return string The Gravity Forms markup, or an empty string.
	 */
	public function render_form( $atts = array() ) {

		if ( ! function_exists( 'gravity_form' ) ) {
			return '';
		}

		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'kwv_career_form' );

		// Resolve the job: explicit id, else the current loop post, else the
		// queried object (covers block-template render where the loop isn't set up).
		$post_id = (int) $atts['id'];
		if ( $post_id < 1 ) {
			$post_id = (int) ( get_the_ID() ?: get_queried_object_id() );
		}

		$form_id = $this->get_form_id( $post_id );

		if ( $form_id < 1 ) {
			return '';
		}

		return gravity_form( $form_id, false, false, false, null, true, 0, false );
	}

	/**
	 * Style the job application form on single job pages.
	 *
	 * The form is embedded with the [kwv_career_form] shortcode (not the Gravity
	 * Forms block), so it can't ride a block stylesheet. This sheet is scoped to
	 * the `.single-kwv_career` body class and lives in the plugin because the
	 * plugin renders the form and is deployable to every environment (the theme
	 * is not always writable).
	 */
	public function enqueue_form_style(): void {

		if ( ! is_singular( 'kwv_career' ) ) {
			return;
		}

		$css_path = KWV_ENH_DIR . 'assets/careers-form.css';
		$version  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : KWV_ENH_VERSION;

		wp_enqueue_style(
			'kwv-careers-form',
			KWV_ENH_URL . 'assets/careers-form.css',
			array(),
			$version
		);
	}
}
