# Changelog

All notable changes to KWV Enhancements are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
