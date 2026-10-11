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
 * Expiry is two-layered. The cron (hourly, so it lands shortly after midnight
 * rather than whenever the day's first visit happened to schedule it) is the
 * record-keeping layer: it drafts the post. A subclass can also opt in to
 * {@see hides_expired()}, which filters front-end listings by the date at query
 * time, so an item disappears the moment its date passes regardless of when the
 * cron next runs.
 *
 * Fields are edited with Secure Custom Fields (field group in /acf-json) but
 * their meta keys are registered here with register_post_meta so they are
 * REST-exposed. An SCF field's NAME deliberately matches its meta key, so SCF
 * writes to the same key the theme's bindings read.
 */
abstract class PostTypeModule implements Module, Activatable, Deactivatable {

	/**
	 * The cron recurrence the expiry check runs on.
	 *
	 * Hourly rather than daily: a daily event fires at whatever time of day it
	 * was first scheduled (on dev, ~19:35), so an item could stay live for most
	 * of the day after its date. Hourly bounds that to an hour past midnight.
	 */
	protected const EXPIRY_SCHEDULE = 'hourly';

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
	 * Whether front-end listings should hide items whose date has passed even
	 * before the cron drafts them. Off by default; subclasses opt in.
	 *
	 * @return bool
	 */
	protected function hides_expired(): bool {
		return false;
	}

	/**
	 * The sanitize callback for one meta field. Plain text by default; override
	 * for fields that hold something more specific (a URL, say).
	 *
	 * @param string $key Meta key.
	 * @return callable
	 */
	protected function meta_sanitize_callback( string $key ): callable {
		return 'sanitize_text_field';
	}

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

		if ( $this->hides_expired() ) {
			add_action( 'pre_get_posts', array( $this, 'exclude_expired' ) );
		}
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
					'sanitize_callback' => $this->meta_sanitize_callback( $key ),
					'auth_callback'     => static function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}
	}

	/**
	 * Schedule the expiry check if it is not already scheduled on the right
	 * recurrence.
	 *
	 * An event left over from an older version on a different recurrence (the
	 * original daily schedule) is replaced, so updating the plugin is enough to
	 * move existing installs onto the new cadence.
	 */
	public function schedule_expiry(): void {
		$event = wp_get_scheduled_event( $this->expiry_hook() );

		if ( $event && static::EXPIRY_SCHEDULE === $event->schedule ) {
			return;
		}

		if ( $event ) {
			$this->unschedule_expiry();
		}

		wp_schedule_event( time(), static::EXPIRY_SCHEDULE, $this->expiry_hook() );
	}

	/**
	 * Clear the expiry check.
	 */
	public function unschedule_expiry(): void {
		$timestamp = wp_next_scheduled( $this->expiry_hook() );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, $this->expiry_hook() );
		}
	}

	/**
	 * Today's date in the site's timezone, as the Ymd integer expiry compares.
	 *
	 * @return int
	 */
	protected function today(): int {
		return (int) current_time( 'Ymd' );
	}

	/**
	 * Whether an item's date has passed. An item with no date never expires,
	 * and an item stays live on its date itself.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function is_expired( int $post_id ): bool {
		$stored = (int) get_post_meta( $post_id, $this->expiry_meta_key(), true );

		return $stored > 0 && $stored < $this->today();
	}

	/**
	 * Hide items whose date has passed from front-end listings of this type.
	 *
	 * Runs on pre_get_posts, so it covers the main query and secondary queries
	 * alike (the Query Loop block builds a WP_Query). Singular requests are left
	 * alone — the item stays reachable until the cron drafts it, and whatever
	 * renders it can check {@see is_expired()} (Careers closes the form).
	 * A query can opt out with the `kwv_include_expired` query var — the expiry
	 * cron does, since expired items are exactly what it is looking for.
	 *
	 * @param \WP_Query $query The query being prepared.
	 */
	public function exclude_expired( $query ): void {

		if ( is_admin() || $query->is_singular() || $query->get( 'kwv_include_expired' ) || ! $this->queries_this_type( $query ) ) {
			return;
		}

		$not_expired = array(
			'relation' => 'OR',
			array(
				'key'     => $this->expiry_meta_key(),
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'   => $this->expiry_meta_key(),
				'value' => '',
			),
			array(
				'key'     => $this->expiry_meta_key(),
				'value'   => $this->today(),
				'compare' => '>=',
				'type'    => 'NUMERIC',
			),
		);

		$existing = $query->get( 'meta_query' );

		$query->set(
			'meta_query',
			$existing ? array( 'relation' => 'AND', $existing, $not_expired ) : array( $not_expired )
		);
	}

	/**
	 * Whether a query is for this post type only (and not a mixed-type query,
	 * which the expiry filter must not narrow).
	 *
	 * @param \WP_Query $query The query.
	 * @return bool
	 */
	protected function queries_this_type( $query ): bool {
		$post_type = (array) $query->get( 'post_type' );

		return array( $this->post_type() ) === array_values( $post_type );
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

		$posts = get_posts(
			array(
				'post_type'           => $this->post_type(),
				'post_status'         => 'publish',
				'posts_per_page'      => -1,
				'fields'              => 'ids',
				// Bypass exclude_expired(): expired items are the point here.
				'kwv_include_expired' => true,
				'meta_query'          => array(
					array(
						'key'     => $this->expiry_meta_key(),
						'value'   => '',
						'compare' => '!=',
					),
				),
			)
		);

		foreach ( $posts as $post_id ) {

			if ( $this->is_expired( (int) $post_id ) ) {
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
