# Changelog

All notable changes to KWV Enhancements are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Events — "Accommodation nearby"** (`modules/event-accommodation.php`, `blocks/event-accommodation/`).
  Client change request (quoted and approved separately). Larger events can list places to stay
  from Booking.com, Airbnb, LekkeSlaap or any other site:
  - New **Accommodation nearby** repeater on the Event Details field group
    (`acf-json/group_kwv_events.json`): *Link* (required), *Name* (required) and an optional
    *Image* from the media library. Values are plain post meta in SCF's repeater shape
    (`kwv_event_accommodation` = row count, `kwv_event_accommodation_{n}_{url|title|image}`).
  - New dynamic block **`kwv/event-accommodation`** (the `EventAccommodation` module) renders an
    "Accommodation nearby" heading and a grid of cards (up to four per row, one column on phones),
    and **nothing at all when the event has no entries**. Each card links out in a new tab
    (`rel="noopener noreferrer nofollow"`, announced to screen readers) with a "via {platform}" line;
    the platform is detected from the link's host, falling back to the bare domain. Without an
    image the card shows a tinted brand panel naming the platform.
  - Cards are core block markup run through `do_blocks()`, so they take the theme's **Event Card**
    section style and **Image Hover Zoom** image style — no plugin CSS. Rows without a valid
    http(s) link or a name are skipped.
  - Images are uploaded by the editor, not scraped from the listing: Airbnb and Booking.com block
    server-side fetches, and listing photos belong to the host/platform.
  - The editor previews the block via ServerSideRender (plain ES5 `index.js`, no build step) and
    shows a short note when there is nothing to render.

  The theme places the block on the single-event template (kwv-theme-2026, same branch name).
- **Events — "Book now" links carry the event** (`modules/block-bindings.php`).
  New block-binding source **`kwv/event-booking-url`** for a button's `url`: returns the page in
  `args.path` (e.g. `/visit/cellar/`) with `?event=` (the event's dropdown label,
  "Title — 15 Aug 2026, 18:00") and `?event_url=` (its permalink). Gravity Forms pre-populates
  from these: the venue booking forms pre-select the event in their *Event you wish to book for*
  dropdown (which in turn hides Date/Time), and the generic *Event Enquiry* form fills its Event
  field and a hidden *Event page* field. The query string also keeps the form page out of the
  full-page cache, so pre-filled values are never shared between visitors. Returns null (keeps the
  button's saved URL) outside an event.

### Fixed

- **Booking dropdown labels are plain text** (`BookingForm::choice_label()`). `get_the_title()`
  encodes `&` as `&#038;`, which leaked into the stored choice values and entries; titles are now
  entity-decoded (Gravity Forms escapes on output). Needed so `?event=` matches the dropdown and
  reads naturally in the generic form.


### Changed

- **Booking forms — event-driven field visibility** (`modules/events-booking-form.php`).
  The venue booking forms now adapt to what is actually scheduled:
  - The *Event you wish to book for* dropdown is hidden (`visibility = administrative`,
    so it is neither rendered nor validated) when the venue has no published events,
    instead of rendering an empty select with a "No events currently scheduled"
    placeholder.
  - The *Date* and *Time* fields hide while an event is selected — each event choice
    already carries its own date and time. The conditional-logic rule is re-applied
    on every render so a form-editor save cannot drift it, and dropped when the
    dropdown is hidden.

  `populate()` (choices only) still runs on `gform_admin_pre_render`; the new
  `prepare()` runs on `gform_pre_render`, `gform_pre_validation` and
  `gform_pre_submission_filter`, so a per-request visibility state is never
  persisted by an editor save.

  Applied to the three forms on dev alongside this (in the database, which owns
  them): the event dropdown is no longer required, forms 5 and 6 got the split
  Date + Time pair that form 4 already had, all three carry the `kwv-booking-form`
  class the theme styles against, and the `{Date and time:4}` notification merge tag
  became `{Date:7} {Time:8}`.

## [1.1.0] - 2026-08-10

### Added

- **Payflex configuration cache** module (`modules/payflex-configuration-cache.php`).
  Caches the Payflex `/configuration` API response for 12 hours, working around a
  bug in Payflex Payment Gateway 2.7.1 where `get_payflex_limits()` reads
  `$settings` before it is assigned, so the gateway's intended 24-hour throttle
  never applies and it issues a blocking remote request on every gateway
  enumeration. Origin TTFB on `/product-category/wine/` went from 7.9s to ~1.1s.

## [1.0.0] - 2026-08-07

### Added

- Initial release: `AcfJson`, `BlockBindings`, `Careers`, `ApplicationForm`,
  `Events`, `BookingForm` and `WinemakersClub` modules.

[Unreleased]: https://github.com/lightspeedwp/kwv-enhancements/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/lightspeedwp/kwv-enhancements/releases/tag/v1.1.0
[1.0.0]: https://github.com/lightspeedwp/kwv-enhancements/releases/tag/v1.0.0
