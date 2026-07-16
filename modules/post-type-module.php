<?php
/**
 * Shared behaviour for content-type modules.
 *
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class for a custom-post-type module with REST-exposed meta and a daily
 * date-based auto-expiry.
 *
 * Careers and Events are the same shape: a public, REST-exposed CPT; a set of
 * string meta fields registered so the block editor and `core/post-meta` block
 * bindings can read them; and a daily cron that drafts any published item whose
 * stored Ymd date has passed. That machinery lives here once — subclasses
 * supply only what differs (labels, post-type args, the field list, and which
 * meta key drives expiry).
 *
 * Fields are edited with Secure Custom Fields (field group in /acf-json) but
 * their meta keys are registered here with register_post_meta so they are
 * REST-exposed. An SCF field's NAME deliberately matches its meta key, so SCF
 * writes to the same key the theme's bindings read.
 */
abstract class PostTypeModule implements Module, Activatable, Deactivatable {

	/**
	 * The cron recurrence the expiry check runs on.
	 */
	protected const EXPIRY_SCHEDULE = 'daily';

	/**
	 * The post-type slug this module owns (e.g. kwv_career).
	 *
	 * @return string
	 */
	abstract protected function post_type(): string;

	/**
	 * Arguments passed to register_post_type().
	 *
	 * @return array
	 */
	abstract protected function post_type_args(): array;

	/**
	 * The type's meta fields as a map of meta key => human description. Every
	 * field is registered as single, REST-exposed, sanitised string meta.
	 *
	 * @return array<string,string>
	 */
	abstract protected function meta_fields(): array;

	/**
	 * The cron hook name for this type's daily expiry check.
	 *
	 * @return string
	 */
	abstract protected function expiry_hook(): string;

	/**
	 * The Ymd date meta key that drives auto-expiry.
	 *
	 * @return string
	 */
	abstract protected function expiry_meta_key(): string;

	/**
	 * Wire the post type, its meta, the self-healing expiry schedule, and the
	 * expiry cron callback.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'init', array( $this, 'register_meta' ) );
		// Self-heal the schedule for existing installs (e.g. after an update).
		add_action( 'init', array( $this, 'schedule_expiry' ) );
		add_action( $this->expiry_hook(), array( $this, 'expire' ) );
	}

	/**
	 * Activation: register the post type so its rewrite rules are present when
	 * the container flushes, and schedule the expiry cron.
	 */
	public function activate(): void {
		$this->register_post_type();
		$this->schedule_expiry();
	}

	/**
	 * Deactivation: clear the expiry cron.
	 */
	public function deactivate(): void {
		$this->unschedule_expiry();
	}

	/**
	 * Register the post type.
	 */
	public function register_post_type(): void {
		register_post_type( $this->post_type(), $this->post_type_args() );
	}

	/**
	 * Register every meta field for this type on the same REST-exposed contract.
	 */
	public function register_meta(): void {
		foreach ( $this->meta_fields() as $key => $description ) {
			register_post_meta(
				$this->post_type(),
				$key,
				array(
					'type'              => 'string',
					'description'       => $description,
					'single'            => true,
					'default'           => '',
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => static function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}
	}

	/**
	 * Schedule the daily expiry check if it is not already scheduled.
	 */
	public function schedule_expiry(): void {
		if ( ! wp_next_scheduled( $this->expiry_hook() ) ) {
			wp_schedule_event( time(), self::EXPIRY_SCHEDULE, $this->expiry_hook() );
		}
	}

	/**
	 * Clear the daily expiry check.
	 */
	public function unschedule_expiry(): void {
		$timestamp = wp_next_scheduled( $this->expiry_hook() );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, $this->expiry_hook() );
		}
	}

	/**
	 * Move any published item whose stored date has passed to Draft.
	 *
	 * Dates are stored by SCF's date picker as Ymd (e.g. 20260731). An item
	 * expires the day AFTER its date, so it stays live on the day itself.
	 * Drafting (rather than trashing) is reversible and keeps the item out of
	 * front-end queries, which only show published posts.
	 */
	public function expire(): void {

		$today = (int) current_time( 'Ymd' );

		$posts = get_posts(
			array(
				'post_type'      => $this->post_type(),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => $this->expiry_meta_key(),
						'value'   => '',
						'compare' => '!=',
					),
				),
			)
		);

		foreach ( $posts as $post_id ) {

			$stored = (int) get_post_meta( $post_id, $this->expiry_meta_key(), true );

			if ( $stored > 0 && $stored < $today ) {
				wp_update_post(
					array(
						'ID'          => $post_id,
						'post_status' => 'draft',
					)
				);
			}
		}
	}
}
