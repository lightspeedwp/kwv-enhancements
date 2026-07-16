<?php
/**
 * Events module — the kwv_event post type, its Brand taxonomy, custom fields,
 * and the date-based auto-expiry.
 *
 * Mirrors the Careers module (see modules/careers.php and
 * docs/adding-a-content-type.md). The plugin owns the data — post type,
 * taxonomy, meta and field definitions — so events survive a theme switch.
 * The booking-form integration (populating each venue's "Event" dropdown)
 * lives in modules/events-booking-form.php.
 *
 * Fields are edited with Secure Custom Fields (field group in /acf-json) but
 * their meta keys are registered here with register_post_meta so they are
 * REST-exposed and available to the theme's block bindings. Field NAMES match
 * these meta keys so SCF writes to the same key.
 *
 * @package KWV_Enhancements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const KWV_EVENTS_EXPIRY_HOOK = 'kwv_events_expire_events';
const KWV_EVENTS_BRAND_TAXONOMY = 'kwv_brand';

/**
 * The initial Brand (venue) terms, seeded on activation.
 *
 * Slugs are chosen so they match the venue keys the booking forms map to
 * (see kwv_events_form_brand_slug() in modules/events-booking-form.php). More
 * brands can be added later in the admin — this list only seeds the starting
 * set and never overwrites edits.
 *
 * @return array<string,string> slug => display name.
 */
function kwv_events_default_brands() {
	return array(
		'kwv-emporium'     => 'KWV Emporium',
		'house-of-fire'    => 'House of Fire',
		'cathedral-cellar' => 'Cathedral Cellar',
	);
}

/**
 * Register the Brand taxonomy for Events.
 *
 * Hierarchical so it gets the checkbox-style meta box and an admin column, and
 * so brands read like a curated list rather than free-tagging. REST-exposed so
 * the editor and any bindings can use it.
 */
function kwv_events_register_taxonomy() {

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

	register_taxonomy( KWV_EVENTS_BRAND_TAXONOMY, array( 'kwv_event' ), $args );
}
add_action( 'init', 'kwv_events_register_taxonomy' );

/**
 * Register the Events post type.
 *
 * Slug kwv_event, rewrite events/{slug}, REST-exposed. Supports the native
 * Featured Image (thumbnail) — that is the event's "featured image".
 */
function kwv_events_register_post_type() {

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

	$args = array(
		'labels'          => $labels,
		'public'          => true,
		'show_in_rest'    => true,
		'menu_icon'       => 'dashicons-calendar-alt',
		'menu_position'   => 21,
		'has_archive'     => false,
		'hierarchical'    => false,
		'supports'        => array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'revisions' ),
		'taxonomies'      => array( KWV_EVENTS_BRAND_TAXONOMY ),
		'rewrite'         => array(
			'slug'       => 'events',
			'with_front' => false,
		),
		'capability_type' => 'post',
	);

	register_post_type( 'kwv_event', $args );
}
add_action( 'init', 'kwv_events_register_post_type' );

/**
 * Register the Events meta fields.
 *
 * REST-exposed so the block editor and block bindings can read them. SCF
 * supplies the editor UI; the field names in the SCF group match these keys.
 *
 * The event date is stored as Ymd (SCF's date-picker format) and drives the
 * auto-expiry; reuse the kwv/meta-date binding to render it. The time is stored
 * as SCF's time-picker string (e.g. 18:00).
 */
function kwv_events_register_meta() {

	$fields = array(
		'kwv_event_date' => __( 'Event date (stored as Ymd). The event auto-expires to Draft after this date.', 'kwv-enhancements' ),
		'kwv_event_time' => __( 'Event start time, e.g. 18:00.', 'kwv-enhancements' ),
	);

	foreach ( $fields as $key => $description ) {
		register_post_meta(
			'kwv_event',
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
add_action( 'init', 'kwv_events_register_meta' );

/**
 * Seed the default Brand terms once.
 *
 * Guarded by an option so it runs a single time (on activation, or self-healing
 * on init for installs updated in place). Never overwrites existing terms, so
 * admin edits and additional brands are safe.
 */
function kwv_events_maybe_seed_brands() {

	if ( get_option( 'kwv_events_brands_seeded' ) ) {
		return;
	}

	// The taxonomy must be registered before terms can be inserted.
	if ( ! taxonomy_exists( KWV_EVENTS_BRAND_TAXONOMY ) ) {
		return;
	}

	foreach ( kwv_events_default_brands() as $slug => $name ) {
		if ( ! term_exists( $slug, KWV_EVENTS_BRAND_TAXONOMY ) ) {
			wp_insert_term( $name, KWV_EVENTS_BRAND_TAXONOMY, array( 'slug' => $slug ) );
		}
	}

	update_option( 'kwv_events_brands_seeded', 1 );
}
add_action( 'init', 'kwv_events_maybe_seed_brands', 20 );

/**
 * Schedule the daily event-expiry check.
 */
function kwv_events_schedule_expiry_event() {
	if ( ! wp_next_scheduled( KWV_EVENTS_EXPIRY_HOOK ) ) {
		wp_schedule_event( time(), 'daily', KWV_EVENTS_EXPIRY_HOOK );
	}
}

/**
 * Clear the daily event-expiry check.
 */
function kwv_events_unschedule_expiry_event() {
	$timestamp = wp_next_scheduled( KWV_EVENTS_EXPIRY_HOOK );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, KWV_EVENTS_EXPIRY_HOOK );
	}
}

// Self-heal the schedule for existing installs (e.g. after a plugin update).
add_action( 'init', 'kwv_events_schedule_expiry_event' );

/**
 * Move any published event whose date has passed to Draft.
 *
 * Dates are stored by SCF's date picker as Ymd (e.g. 20260815). An event
 * expires the day AFTER its date, so it stays live on the day itself. Drafting
 * (rather than trashing) is reversible and keeps the event out of front-end
 * queries and the booking dropdowns, which only include published events.
 */
function kwv_events_expire_events() {

	$today = (int) current_time( 'Ymd' );

	$events = get_posts(
		array(
			'post_type'      => 'kwv_event',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'     => 'kwv_event_date',
					'value'   => '',
					'compare' => '!=',
				),
			),
		)
	);

	foreach ( $events as $event_id ) {

		$event_date = (int) get_post_meta( $event_id, 'kwv_event_date', true );

		if ( $event_date > 0 && $event_date < $today ) {
			wp_update_post(
				array(
					'ID'          => $event_id,
					'post_status' => 'draft',
				)
			);
		}
	}
}
add_action( KWV_EVENTS_EXPIRY_HOOK, 'kwv_events_expire_events' );
