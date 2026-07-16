<?php
/**
 * Events — dynamic booking-form integration.
 *
 * Each "Book Experience" Gravity Form (one per venue) has an "Event you wish to
 * book for" dropdown that ships with placeholder choices. This module replaces
 * those choices at render time with the published Events tagged to that venue's
 * Brand, so the dropdown always reflects the current programme and empties out
 * as events expire (the daily cron drafts past events — see {@see Events}).
 *
 * The forms are created and owned by the separate kwv-forms plugin; this module
 * only reads them and swaps the one field's choices. It is inert when Gravity
 * Forms is not active.
 *
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Populates each venue booking form's event dropdown with live events.
 */
class BookingForm implements Module {

	/**
	 * Defer hook registration to init so Gravity Forms has loaded.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_hooks' ) );
	}

	/**
	 * Hook the population onto Gravity Forms' render, admin, validation and
	 * submission filters. Guarded so the module is inert without Gravity Forms.
	 *
	 * Attaching to all four keeps the dynamic choices consistent across display,
	 * admin, and (crucially) submission validation — Gravity Forms validates a
	 * select against its choices, so the choices must be present when the form
	 * is processed, not just when it renders.
	 */
	public function register_hooks(): void {

		if ( ! class_exists( 'GFAPI' ) ) {
			return;
		}

		add_filter( 'gform_pre_render', array( $this, 'populate' ) );
		add_filter( 'gform_admin_pre_render', array( $this, 'populate' ) );
		add_filter( 'gform_pre_validation', array( $this, 'populate' ) );
		add_filter( 'gform_pre_submission_filter', array( $this, 'populate' ) );
	}

	/**
	 * Label of the dropdown field to populate. Matched by label (not id) so it
	 * is robust to field re-ordering; filterable for other environments.
	 *
	 * @return string
	 */
	public function field_label(): string {
		return (string) apply_filters( 'kwv_events_booking_field_label', 'Event you wish to book for' );
	}

	/**
	 * Resolve the Brand term slug a booking form is for.
	 *
	 * The kwv-forms plugin records which form is which venue in the
	 * kwv_forms_installer_ids option (e.g. booking-emporium => 4). We map those
	 * venue keys onto Brand term slugs. Returns '' for forms we don't manage.
	 *
	 * Filter kwv_events_venue_brand_map to change the venue-key => brand-slug
	 * map; filter kwv_events_form_brand_slug to override the resolved slug per
	 * form id.
	 *
	 * @param int $form_id Gravity Forms form ID.
	 * @return string Brand term slug, or '' if the form is not a managed booking form.
	 */
	public function form_brand_slug( $form_id ): string {

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
	public function brand_choices( $brand_slug ): array {

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
						'taxonomy' => Events::BRAND_TAXONOMY,
						'field'    => 'slug',
						'terms'    => $brand_slug,
					),
				),
			)
		);

		$choices = array();

		foreach ( $events as $event ) {
			$label = $this->choice_label( $event->ID );

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
	public function choice_label( $event_id ): string {

		$label = get_the_title( $event_id );

		$raw_date = (string) get_post_meta( $event_id, 'kwv_event_date', true );
		$time     = trim( (string) get_post_meta( $event_id, 'kwv_event_time', true ) );

		$when = '';

		if ( '' !== $raw_date ) {
			$date = \DateTime::createFromFormat( 'Ymd', $raw_date );
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
	 * @param array $form The Gravity Forms form array.
	 * @return array The form, with the event field's choices replaced when managed.
	 */
	public function populate( $form ) {

		if ( empty( $form['id'] ) || empty( $form['fields'] ) ) {
			return $form;
		}

		$brand_slug = $this->form_brand_slug( $form['id'] );

		if ( '' === $brand_slug ) {
			return $form;
		}

		$label   = $this->field_label();
		$choices = $this->brand_choices( $brand_slug );

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
}
