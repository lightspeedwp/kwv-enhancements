<?php
/**
 * Events module — the kwv_event post type, its Brand taxonomy, custom fields,
 * and the date-based auto-expiry.
 *
 * Mirrors the Careers module (see modules/careers.php and
 * docs/adding-a-content-type.md): the shared CPT/meta/expiry machinery comes
 * from {@see PostTypeModule}, and this class adds the Brand taxonomy and its
 * one-time term seeding. The booking-form integration (populating each venue's
 * "Event" dropdown) lives in {@see BookingForm}.
 *
 * The plugin owns the data — post type, taxonomy, meta and field definitions —
 * so events survive a theme switch.
 *
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Events content type and its Brand taxonomy.
 */
class Events extends PostTypeModule {

	/**
	 * Cron hook for the daily event-date expiry check.
	 */
	public const EXPIRY_HOOK = 'kwv_events_expire_events';

	/**
	 * The Brand (venue) taxonomy slug.
	 */
	public const BRAND_TAXONOMY = 'kwv_brand';

	/**
	 * Option guarding the one-time Brand term seed.
	 */
	public const BRANDS_SEEDED_OPTION = 'kwv_events_brands_seeded';

	/**
	 * Wire the shared post-type behaviour, the Brand taxonomy, and term seeding.
	 *
	 * The taxonomy is registered before the post type (matching the original
	 * load order), and seeding runs at priority 20 so the taxonomy exists first.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_taxonomy' ) );
		parent::register();
		add_action( 'init', array( $this, 'maybe_seed_brands' ), 20 );
	}

	/**
	 * Activation: register the taxonomy and post type, seed the default brands,
	 * and schedule the expiry cron.
	 */
	public function activate(): void {
		$this->register_taxonomy();
		$this->register_post_type();
		$this->maybe_seed_brands();
		$this->schedule_expiry();
	}

	/**
	 * The Events post-type slug.
	 *
	 * @return string
	 */
	protected function post_type(): string {
		return 'kwv_event';
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
		return 'kwv_event_date';
	}

	/**
	 * The initial Brand (venue) terms, seeded on activation.
	 *
	 * Slugs are chosen so they match the venue keys the booking forms map to
	 * (see BookingForm::form_brand_slug()). More brands can be added later in
	 * the admin — this list only seeds the starting set and never overwrites
	 * edits.
	 *
	 * @return array<string,string> slug => display name.
	 */
	public function default_brands(): array {
		return array(
			'kwv-emporium'     => 'KWV Emporium',
			'house-of-fire'    => 'House of Fire',
			'cathedral-cellar' => 'Cathedral Cellar',
		);
	}

	/**
	 * Register the Brand taxonomy for Events.
	 *
	 * Hierarchical so it gets the checkbox-style meta box and an admin column,
	 * and so brands read like a curated list rather than free-tagging.
	 * REST-exposed so the editor and any bindings can use it.
	 */
	public function register_taxonomy(): void {

		$labels = array(
			'name'              => _x( 'Brands', 'Taxonomy general name', 'kwv-enhancements' ),
			'singular_name'     => _x( 'Brand', 'Taxonomy singular name', 'kwv-enhancements' ),
			'menu_name'         => __( 'Brands', 'kwv-enhancements' ),
			'all_items'         => __( 'All Brands', 'kwv-enhancements' ),
			'edit_item'         => __( 'Edit Brand', 'kwv-enhancements' ),
			'view_item'         => __( 'View Brand', 'kwv-enhancements' ),
			'update_item'       => __( 'Update Brand', 'kwv-enhancements' ),
			'add_new_item'      => __( 'Add New Brand', 'kwv-enhancements' ),
			'new_item_name'     => __( 'New Brand Name', 'kwv-enhancements' ),
			'search_items'      => __( 'Search Brands', 'kwv-enhancements' ),
			'parent_item'       => __( 'Parent Brand', 'kwv-enhancements' ),
			'parent_item_colon' => __( 'Parent Brand:', 'kwv-enhancements' ),
			'not_found'         => __( 'No brands found.', 'kwv-enhancements' ),
		);

		$args = array(
			'labels'            => $labels,
			'public'            => true,
			'hierarchical'      => true,
			'show_in_rest'      => true,
			'show_admin_column' => true,
			'show_ui'           => true,
			'rewrite'           => array(
				'slug'       => 'brand',
				'with_front' => false,
			),
		);

		register_taxonomy( self::BRAND_TAXONOMY, array( 'kwv_event' ), $args );
	}

	/**
	 * Post-type arguments.
	 *
	 * Slug kwv_event, rewrite events/{slug}, REST-exposed. Supports the native
	 * Featured Image (thumbnail) — that is the event's "featured image".
	 *
	 * @return array
	 */
	protected function post_type_args(): array {

		$labels = array(
			'name'               => _x( 'Events', 'Post type general name', 'kwv-enhancements' ),
			'singular_name'      => _x( 'Event', 'Post type singular name', 'kwv-enhancements' ),
			'menu_name'          => _x( 'Events', 'Admin Menu text', 'kwv-enhancements' ),
			'name_admin_bar'     => _x( 'Event', 'Add New on Toolbar', 'kwv-enhancements' ),
			'add_new'            => __( 'Add New', 'kwv-enhancements' ),
			'add_new_item'       => __( 'Add New Event', 'kwv-enhancements' ),
			'new_item'           => __( 'New Event', 'kwv-enhancements' ),
			'edit_item'          => __( 'Edit Event', 'kwv-enhancements' ),
			'view_item'          => __( 'View Event', 'kwv-enhancements' ),
			'all_items'          => __( 'All Events', 'kwv-enhancements' ),
			'search_items'       => __( 'Search Events', 'kwv-enhancements' ),
			'not_found'          => __( 'No events found.', 'kwv-enhancements' ),
			'not_found_in_trash' => __( 'No events found in Trash.', 'kwv-enhancements' ),
			'archives'           => __( 'Event Archives', 'kwv-enhancements' ),
			'item_published'     => __( 'Event published.', 'kwv-enhancements' ),
			'item_updated'       => __( 'Event updated.', 'kwv-enhancements' ),
		);

		return array(
			'labels'          => $labels,
			'public'          => true,
			'show_in_rest'    => true,
			'menu_icon'       => 'dashicons-calendar-alt',
			'menu_position'   => 21,
			'has_archive'     => false,
			'hierarchical'    => false,
			'supports'        => array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'revisions' ),
			'taxonomies'      => array( self::BRAND_TAXONOMY ),
			'rewrite'         => array(
				'slug'       => 'events',
				'with_front' => false,
			),
			'capability_type' => 'post',
		);
	}

	/**
	 * The Events meta fields (meta key => description).
	 *
	 * The event date is stored as Ymd (SCF's date-picker format) and drives the
	 * auto-expiry; reuse the kwv/meta-date binding to render it. The time is
	 * stored as SCF's time-picker string (e.g. 18:00).
	 *
	 * @return array<string,string>
	 */
	protected function meta_fields(): array {
		return array(
			'kwv_event_date' => __( 'Event date (stored as Ymd). The event auto-expires to Draft after this date.', 'kwv-enhancements' ),
			'kwv_event_time' => __( 'Event start time, e.g. 18:00.', 'kwv-enhancements' ),
		);
	}

	/**
	 * Seed the default Brand terms once.
	 *
	 * Guarded by an option so it runs a single time (on activation, or
	 * self-healing on init for installs updated in place). Never overwrites
	 * existing terms, so admin edits and additional brands are safe.
	 */
	public function maybe_seed_brands(): void {

		if ( get_option( self::BRANDS_SEEDED_OPTION ) ) {
			return;
		}

		// The taxonomy must be registered before terms can be inserted.
		if ( ! taxonomy_exists( self::BRAND_TAXONOMY ) ) {
			return;
		}

		foreach ( $this->default_brands() as $slug => $name ) {
			if ( ! term_exists( $slug, self::BRAND_TAXONOMY ) ) {
				wp_insert_term( $name, self::BRAND_TAXONOMY, array( 'slug' => $slug ) );
			}
		}

		update_option( self::BRANDS_SEEDED_OPTION, 1 );
	}
}
