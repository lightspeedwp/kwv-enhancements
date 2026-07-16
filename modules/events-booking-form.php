<?php
/**
 * Events — dynamic booking-form integration.
 *
 * Each "Book Experience" Gravity Form (one per venue) has an "Event you wish to
 * book for" dropdown that ships with placeholder choices. This module replaces
 * those choices at render time with the published Events tagged to that venue's
 * Brand, so the dropdown always reflects the current programme and empties out
 * as events expire (the daily cron drafts past events — see modules/events.php).
 *
 * The forms are created and owned by the separate kwv-forms plugin; this module
 * only reads them and swaps the one field's choices. It is inert when Gravity
 * Forms is not active.
 *
 * @package KWV_Enhancements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Label of the dropdown field to populate. Matched by label (not id) so it is
 * robust to field re-ordering; filterable for other environments.
 *
 * @return string
 */
function kwv_events_booking_field_label() {
	return (string) apply_filters( 'kwv_events_booking_field_label', 'Event you wish to book for' );
}

/**
 * Resolve the Brand term slug a booking form is for.
 *
 * The kwv-forms plugin records which form is which venue in the
 * kwv_forms_installer_ids option (e.g. booking-emporium => 4). We map those
 * venue keys onto Brand term slugs. Returns '' for forms we don't manage.
 *
 * Filter kwv_events_venue_brand_map to change the venue-key => brand-slug map;
 * filter kwv_events_form_brand_slug to override the resolved slug per form id.
 *
 * @param int $form_id Gravity Forms form ID.
 * @return string Brand term slug, or '' if the form is not a managed booking form.
 */
function kwv_events_form_brand_slug( $form_id ) {

	$installer_ids = get_option( 'kwv_forms_installer_ids', array() );

	$slug = '';

	if ( is_array( $installer_ids ) ) {
		$venue_key = array_search( (int) $form_id, array_map( 'intval', $installer_ids ), true );

		$map = apply_filters(
			'kwv_events_venue_brand_map',
			array(
				'booking-emporium'         => 'kwv-emporium',
				'booking-house-of-fire'    => 'house-of-fire',
				'booking-cathedral-cellar' => 'cathedral-cellar',
			)
		);

		if ( $venue_key && isset( $map[ $venue_key ] ) ) {
			$slug = $map[ $venue_key ];
		}
	}

	return (string) apply_filters( 'kwv_events_form_brand_slug', $slug, (int) $form_id );
}

/**
 * Build Gravity Forms choices for the published events tagged to a Brand.
 *
 * Ordered by event date ascending (soonest first). Each choice reads
 * "Title — 15 Aug 2026, 18:00" (date/time appended when present) so both the
 * dropdown and the submitted entry carry the full occurrence.
 *
 * @param string $brand_slug Brand term slug.
 * @return array[] Gravity Forms choice arrays (empty when the venue has no events).
 */
function kwv_events_brand_choices( $brand_slug ) {

	$events = get_posts(
		array(
			'post_type'      => 'kwv_event',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'meta_value_num',
			'meta_key'       => 'kwv_event_date',
			'order'          => 'ASC',
			'tax_query'      => array(
				array(
					'taxonomy' => KWV_EVENTS_BRAND_TAXONOMY,
					'field'    => 'slug',
					'terms'    => $brand_slug,
				),
			),
		)
	);

	$choices = array();

	foreach ( $events as $event ) {
		$label = kwv_events_choice_label( $event->ID );

		$choices[] = array(
			'text'       => $label,
			'value'      => $label,
			'isSelected' => false,
		);
	}

	return $choices;
}

/**
 * Compose an event's dropdown label: "Title — 15 Aug 2026, 18:00".
 *
 * The date meta is stored as Ymd (SCF's date picker); the time as a plain
 * string (e.g. 18:00). Either may be absent — the label degrades gracefully.
 *
 * @param int $event_id Event post ID.
 * @return string
 */
function kwv_events_choice_label( $event_id ) {

	$label = get_the_title( $event_id );

	$raw_date = (string) get_post_meta( $event_id, 'kwv_event_date', true );
	$time     = trim( (string) get_post_meta( $event_id, 'kwv_event_time', true ) );

	$when = '';

	if ( '' !== $raw_date ) {
		$date = DateTime::createFromFormat( 'Ymd', $raw_date );
		$when = $date ? wp_date( 'j M Y', $date->getTimestamp() ) : $raw_date;
	}

	if ( '' !== $time ) {
		$when = '' !== $when ? $when . ', ' . $time : $time;
	}

	return '' !== $when ? $label . ' — ' . $when : $label;
}

/**
 * Populate the "Event" dropdown of a booking form with its venue's events.
 *
 * Attached to the render/validation/submission filters so the dynamic choices
 * are consistent across display, admin, and (crucially) submission validation —
 * Gravity Forms validates a select against its choices, so the choices must be
 * present when the form is processed, not just when it renders.
 *
 * @param array $form The Gravity Forms form array.
 * @return array The form, with the event field's choices replaced when managed.
 */
function kwv_events_populate_booking_form( $form ) {

	if ( empty( $form['id'] ) || empty( $form['fields'] ) ) {
		return $form;
	}

	$brand_slug = kwv_events_form_brand_slug( $form['id'] );

	if ( '' === $brand_slug ) {
		return $form;
	}

	$label   = kwv_events_booking_field_label();
	$choices = kwv_events_brand_choices( $brand_slug );

	foreach ( $form['fields'] as $field ) {

		if ( 'select' !== $field->type || $label !== $field->label ) {
			continue;
		}

		$field->choices     = $choices;
		$field->placeholder = $choices
			? __( 'Select an event', 'kwv-enhancements' )
			: __( 'No events currently scheduled', 'kwv-enhancements' );
	}

	return $form;
}

/**
 * Hook the population onto Gravity Forms' render, admin, validation and
 * submission filters. Guarded so the module is inert without Gravity Forms.
 */
function kwv_events_register_booking_hooks() {

	if ( ! class_exists( 'GFAPI' ) ) {
		return;
	}

	add_filter( 'gform_pre_render', 'kwv_events_populate_booking_form' );
	add_filter( 'gform_admin_pre_render', 'kwv_events_populate_booking_form' );
	add_filter( 'gform_pre_validation', 'kwv_events_populate_booking_form' );
	add_filter( 'gform_pre_submission_filter', 'kwv_events_populate_booking_form' );
}
add_action( 'init', 'kwv_events_register_booking_hooks' );
