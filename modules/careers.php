<?php
/**
 * Careers module — the kwv_career job post type, its custom fields, the
 * closing-date auto-expiry, and the shared application form lookup.
 *
 * The shared CPT/meta/expiry machinery lives in {@see PostTypeModule}; this
 * class adds the Careers-specific pieces: the shared application form
 * lookup, the LinkedIn Easy Apply link, the
 * [kwv_career_form] shortcode (the "apply" panel: Easy Apply button + form),
 * and the form stylesheet. It also opts in to hiding expired jobs from
 * listings at query time, so a job leaves the board the moment its closing
 * date passes rather than when the cron next drafts it.
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
	 * Option holding the HR inbox job applications are sent to. Read when the
	 * application form's notifications are provisioned (see ApplicationForm).
	 */
	public const HR_EMAIL_OPTION = 'kwv_careers_hr_email';

	/**
	 * Meta key of the LinkedIn Easy Apply link.
	 */
	public const LINKEDIN_META = 'kwv_career_linkedin_url';

	/**
	 * Display format for the closing date (stored Ymd).
	 */
	public const DATE_FORMAT = 'j F Y';

	/**
	 * Style handle for the application form + Easy Apply styling.
	 */
	public const STYLE_HANDLE = 'kwv-careers-form';

	/**
	 * Wire the shared post-type behaviour, plus the Careers-specific hooks.
	 */
	public function register(): void {
		parent::register();

		add_shortcode( 'kwv_career_form', array( $this, 'render_form' ) );
		add_filter( 'render_block_core/shortcode', array( $this, 'render_form_block' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_form_style' ) );
	}

	/**
	 * Hide jobs past their closing date from listings straight away.
	 *
	 * @return bool
	 */
	protected function hides_expired(): bool {
		return true;
	}

	/**
	 * The LinkedIn link is a URL; everything else is plain text.
	 *
	 * @param string $key Meta key.
	 * @return callable
	 */
	protected function meta_sanitize_callback( string $key ): callable {
		return self::LINKEDIN_META === $key ? 'esc_url_raw' : parent::meta_sanitize_callback( $key );
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
			self::LINKEDIN_META       => __( 'LinkedIn Easy Apply link. When set, the job shows an "Easy Apply with LinkedIn" button beside the application form.', 'kwv-enhancements' ),
		);
	}

	/**
	 * The Gravity Forms form every job embeds: the shared application form
	 * installed by {@see ApplicationForm} (stored in the FORM_OPTION option).
	 *
	 * Jobs no longer choose their own form; any kwv_career_form_id meta left on
	 * older jobs is ignored.
	 *
	 * @return int The form ID to render (0 if none is available).
	 */
	public function get_form_id(): int {
		return (int) get_option( self::FORM_OPTION, 0 );
	}

	/**
	 * The job's LinkedIn Easy Apply link, or '' when none (or not a web URL).
	 *
	 * @param int $post_id Job post ID.
	 * @return string
	 */
	public function linkedin_url( int $post_id ): string {
		$url = (string) get_post_meta( $post_id, self::LINKEDIN_META, true );

		return wp_http_validate_url( $url ) ? $url : '';
	}

	/**
	 * Everything an application needs to know about a job, keyed by the
	 * inputName of the application form's hidden field that records it.
	 *
	 * Used to fill those hidden fields server-side at submission (see
	 * ApplicationContext), so the entry and both notifications carry the job
	 * details whichever page the form was submitted from.
	 *
	 * @param int $post_id Job post ID.
	 * @return array<string,string> Empty when the ID is not a job.
	 */
	public function job_details( int $post_id ): array {

		if ( $post_id < 1 || $this->post_type() !== get_post_type( $post_id ) ) {
			return array();
		}

		$closing = (string) get_post_meta( $post_id, 'kwv_career_closing_date', true );
		$date    = '' !== $closing ? \DateTime::createFromFormat( 'Ymd', $closing, wp_timezone() ) : false;

		return array(
			'job_id'         => (string) $post_id,
			'job_title'      => get_the_title( $post_id ),
			'job_ref'        => (string) get_post_meta( $post_id, 'kwv_career_reference', true ),
			'job_url'        => (string) get_permalink( $post_id ),
			'job_location'   => (string) get_post_meta( $post_id, 'kwv_career_location', true ),
			'job_department' => (string) get_post_meta( $post_id, 'kwv_career_department', true ),
			'job_closing'    => $date ? wp_date( self::DATE_FORMAT, $date->getTimestamp() ) : '',
		);
	}

	/**
	 * Whether a job can currently be applied for: published and not past its
	 * closing date.
	 *
	 * @param int $post_id Job post ID.
	 * @return bool
	 */
	public function is_open( int $post_id ): bool {
		return $this->post_type() === get_post_type( $post_id )
			&& 'publish' === get_post_status( $post_id )
			&& ! $this->is_expired( $post_id );
	}

	/**
	 * The "Easy Apply with LinkedIn" button (plus an "or apply below" divider),
	 * or '' when the job has no Easy Apply link.
	 *
	 * @param int $post_id Job post ID.
	 * @return string
	 */
	public function easy_apply_html( int $post_id ): string {

		$url = $this->linkedin_url( $post_id );

		if ( '' === $url ) {
			return '';
		}

		$icon = '<svg class="kwv-career-easy-apply__icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="currentColor" d="M20.45 20.45h-3.55v-5.57c0-1.33-.03-3.04-1.85-3.04-1.86 0-2.14 1.45-2.14 2.94v5.67H9.35V9h3.41v1.56h.05c.48-.9 1.64-1.85 3.37-1.85 3.6 0 4.27 2.37 4.27 5.46v6.28ZM5.34 7.43a2.06 2.06 0 1 1 0-4.13 2.06 2.06 0 0 1 0 4.13ZM7.12 20.45H3.56V9h3.56v11.45ZM22.23 0H1.77C.79 0 0 .77 0 1.73v20.54C0 23.23.79 24 1.77 24h20.45c.98 0 1.78-.77 1.78-1.73V1.73C24 .77 23.2 0 22.22 0h.01Z"/></svg>';

		return sprintf(
			'<div class="kwv-career-easy-apply"><a class="kwv-career-easy-apply__button" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span>%3$s</span><span class="kwv-sr-only"> %4$s</span></a><p class="kwv-career-easy-apply__or"><span>%5$s</span></p></div>',
			esc_url( $url ),
			$icon,
			esc_html__( 'Easy Apply with LinkedIn', 'kwv-enhancements' ),
			esc_html__( '(opens in a new tab)', 'kwv-enhancements' ),
			esc_html__( 'or apply with your CV below', 'kwv-enhancements' )
		);
	}

	/**
	 * The "apply" panel for a job: the LinkedIn Easy Apply button when the job
	 * has one, then the application form — or a "closed" notice once the job
	 * is past its closing date (or unpublished).
	 *
	 * @param int  $post_id Job post ID.
	 * @param bool $ajax    Whether Gravity Forms should submit over AJAX.
	 * @return string
	 */
	public function apply_panel_html( int $post_id, bool $ajax = false ): string {

		if ( ! $this->is_open( $post_id ) ) {
			return sprintf(
				'<div class="kwv-career-apply"><p class="kwv-career-apply__closed">%s</p></div>',
				esc_html__( 'Applications for this position have closed.', 'kwv-enhancements' )
			);
		}

		return '<div class="kwv-career-apply">' . $this->easy_apply_html( $post_id ) . $this->form_html( $this->get_form_id(), $ajax ) . '</div>';
	}

	/**
	 * Render a Gravity Forms form (title and description off), or '' when
	 * Gravity Forms is inactive or there is no form.
	 *
	 * @param int  $form_id Form ID.
	 * @param bool $ajax    Whether to submit over AJAX.
	 * @return string
	 */
	public function form_html( int $form_id, bool $ajax = false ): string {

		if ( $form_id < 1 || ! function_exists( 'gravity_form' ) ) {
			return '';
		}

		return (string) gravity_form( $form_id, false, false, false, null, $ajax, 0, false );
	}

	/**
	 * Render the apply panel for the current (or given) job.
	 *
	 * Registered as the [kwv_career_form] shortcode so the single-job template
	 * can embed whichever form the job resolves to at render time, together
	 * with the job's Easy Apply button.
	 *
	 * @param array $atts Shortcode attributes: 'id' to force a specific job ID.
	 * @return string The panel markup, or an empty string.
	 */
	public function render_form( $atts = array() ) {

		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'kwv_career_form' );

		// Resolve the job: explicit id, else the current loop post, else the
		// queried object (covers block-template render where the loop isn't set up).
		$post_id = (int) $atts['id'];
		if ( $post_id < 1 ) {
			$post_id = (int) ( get_the_ID() ?: get_queried_object_id() );
		}

		return $this->apply_panel_html( $post_id );
	}

	/**
	 * Expand [kwv_career_form] when it sits in a Shortcode block of a block
	 * template.
	 *
	 * Block templates only get do_shortcode() on their raw markup, BEFORE blocks
	 * render, so a shortcode inside a pattern the template references (the
	 * theme's kwv/template-single-career) is never seen, and core/shortcode
	 * itself only runs wpautop(). Expanding it here makes the theme file work
	 * as well as a flattened Site Editor copy does. Post content is unaffected:
	 * its own do_shortcode() pass simply finds nothing left to expand.
	 *
	 * @param string $content Rendered block markup.
	 * @param array  $block   Parsed block.
	 * @return string
	 */
	public function render_form_block( $content, $block ) {

		$shortcode = trim( (string) ( $block['innerHTML'] ?? '' ) );

		if ( ! preg_match( '/^\[kwv_career_form(\s[^\]]*)?\]$/', $shortcode ) ) {
			return $content;
		}

		return do_shortcode( $shortcode );
	}

	/**
	 * Style the job application form on single job pages.
	 *
	 * The form is embedded with the [kwv_career_form] shortcode (not the Gravity
	 * Forms block), so it can't ride a block stylesheet. The sheet is scoped to
	 * the `.kwv-career-apply` wrapper the apply panel renders, and lives in the
	 * plugin because the plugin renders the form and is deployable to every
	 * environment (the theme is not always writable). The jobs modal enqueues
	 * it too ({@see enqueue_styles()}).
	 */
	public function enqueue_form_style(): void {

		if ( is_singular( 'kwv_career' ) ) {
			$this->enqueue_styles();
		}
	}

	/**
	 * Enqueue the apply-panel stylesheet.
	 */
	public function enqueue_styles(): void {
		wp_enqueue_style(
			self::STYLE_HANDLE,
			KWV_ENH_URL . 'assets/careers-form.css',
			array(),
			self::asset_version( 'assets/careers-form.css' )
		);
	}

	/**
	 * A cache-busting version for a plugin asset: its modified time, falling
	 * back to the plugin version.
	 *
	 * @param string $relative_path Path relative to the plugin root.
	 * @return string
	 */
	public static function asset_version( string $relative_path ): string {
		$path = KWV_ENH_DIR . $relative_path;

		return file_exists( $path ) ? (string) filemtime( $path ) : KWV_ENH_VERSION;
	}
}
