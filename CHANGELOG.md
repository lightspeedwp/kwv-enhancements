# Changelog

All notable changes to KWV Enhancements are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
