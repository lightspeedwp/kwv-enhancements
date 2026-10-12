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
- **Careers — job modal on the jobs board** (`modules/careers-modal.php`,
  `assets/careers-modal.{css,js}`). A job's "Read More" link and linked title on the
  Careers listing now open a modal — title, reference, location/department/closing
  date, the job description, the LinkedIn Easy Apply button (when set) and the
  application form — instead of navigating. Progressive enhancement: the links are
  tagged (`data-kwv-career-open`) via `render_block_core/read-more` and
  `render_block_core/post-title`, so without JavaScript (or on Cmd/Ctrl-click) they
  still go to the single-job page. One shared copy of the form lives in the dialog
  and submits over AJAX, so the confirmation appears in the modal; the script sets
  its hidden Job ID per job. Native `<dialog>`: focus moves to the close button,
  Escape/backdrop close, focus returns to the link, page scroll is locked.
- **Careers — LinkedIn Easy Apply link** field (`kwv_career_linkedin_url`, SCF
  `url` field, REST-exposed, sanitised with `esc_url_raw`). When set, an "Easy Apply
  with LinkedIn" button (opens in a new tab) shows above the application form in the
  modal and on the single-job page; when blank the CV form is the only route.
- **Careers — server-side job context** (`modules/careers-application-context.php`).
  Applications record the job's title, reference, link, location, department and
  closing date in hidden fields filled from the posted Job ID at submission — browser
  values are overwritten — and an application for a closed job is refused with
  "Sorry — applications for this position have closed."
- **`EmailTemplate`** (`modules/email-template.php`) — a code copy of the branded KWV
  email shell the Contact/Booking notifications use (dark header + logo, details
  table, gold CTA, footer), for plugin-provisioned notifications.
- **Careers — application form readme** (`docs/careers-application-form.md`): how the
  form, job context, notifications and their conditional rows work.

### Removed

- **Careers — per-job "Application Form" field.** Every job now uses the shared
  "KWV Job Application" form; `Careers::get_form_id()` takes no argument and ignores
  any `kwv_career_form_id` meta left on older jobs.

### Fixed

- **Booking dropdown labels are plain text** (`BookingForm::choice_label()`). `get_the_title()`
  encodes `&` as `&#038;`, which leaked into the stored choice values and entries; titles are now
  entity-decoded (Gravity Forms escapes on output). Needed so `?event=` matches the dropdown and
  reads naturally in the generic form.
- **Careers form — CV "Choose file" button** now centres with the "No file chosen"
  text (an inherited 36px line-height + top alignment pushed it down).
- **Careers modal — close button** no longer shrinks to 26px tall when the modal
  scrolls (`flex-shrink: 0`), which lifted it above the title and squashed its hover fill.


### Changed

- **Careers — simplified application form (schema 2).** The shared "KWV Job
  Application" form is now Name, Surname, Email, Phone and CV upload (PDF/Word, 5MB),
  plus hidden job fields (Job ID 17, Job Title 15, Job Reference 16, Job Link 18,
  Location 19, Department 20, Closing Date 21). Gender, Race, CTC, expected
  remuneration, location, notice period, qualification upload, relocation and message
  are gone. Kept field ids (Email 1, Name 2, Surname 3, Phone 10, CV 11) so existing
  entries still show those values; data from removed fields stays in the database.
  The form is now **versioned**: `ApplicationForm::SCHEMA_VERSION` (2) vs the
  `kwv_careers_form_schema` option. An installed schema-1 form is migrated in place
  once on the next request after deploy — fields, form class (`kwv-career-form`),
  button ("Submit application") and default confirmation rewritten, the old bare
  `{all_fields}` admin notification removed, any other admin-added notification kept.
- **Careers — notifications.** *HR — New Application*: plain text to the
  `kwv_careers_hr_email` option (else the site admin email), Reply-To the applicant,
  CV attached, listing the position (title, ref, department, location, closing date,
  posting link), the applicant (name, email, phone, CV link) and the submission
  (date, page, entry ID + admin link). *Applicant Confirmation*: branded HTML with the
  same summary, Reply-To HR, and a "View current vacancies" button. The on-page
  confirmation names the job and ref. Empty job details drop out (conditional
  shortcodes in the HTML, a `gform_pre_send_email` tidy for the HR subject).
- **`[kwv_career_form]`** now renders the whole apply panel (Easy Apply button +
  form, or a "closed" notice) inside a `.kwv-career-apply` wrapper, and
  `assets/careers-form.css` is scoped to that wrapper (was `.single-kwv_career`) so
  the same styling applies in the modal.

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

  The notifications were also rewritten as **enquiries the venue answers** — subjects
  "New/Your booking **enquiry** — {Venue}", the guest email stating that the venue's
  team will check availability and email to confirm (and that the booking is confirmed
  only once they reply), and the admin email asking staff to reply to confirm. The
  *Experience* and *Preferred date/time* details rows are now wrapped in Gravity Forms'
  conditional shortcode keyed to the event field being empty, so each email shows only
  the one the guest supplied rather than one permanently blank labelled row.

  **Launch dependency:** the admin notification still goes to
  `zared@lightspeedwp.dev`. The guest copy now promises a reply *from the venue*, so
  the recipient needs to become each venue's own inbox (`info@kwvemporium.co.za`,
  `cathedralcellar@kwv.co.za`, `houseoffire@kwv.co.za`) before launch.

### Fixed

- **Careers — auto-expiry lag.** Expiry drafted jobs from a *daily* cron that fires
  at whatever time it was first scheduled (on dev ~19:35, via Cron Control), so a
  job stayed on the listing for most of the day after its closing date — and the
  host edge cache could serve that listing longer still. Jobs are now filtered out
  of front-end listings at query time the moment the closing date has passed
  (`PostTypeModule::hides_expired()`, opted into by Careers), and the cron runs
  **hourly** (an existing daily event is rescheduled automatically). The cron query
  bypasses the new filter via the `kwv_include_expired` query var. Events share the
  hourly cadence but don't opt into the query-time filter.
- **Careers — form missing from the theme's single-job template.** WordPress runs
  `do_shortcode()` on a block template's raw markup *before* blocks render, so the
  `[kwv_career_form]` inside the `kwv/template-single-career` pattern was never
  expanded (dev only worked because its DB template override has the markup
  flattened). A Shortcode block holding exactly that shortcode is now expanded at
  render time — needed before the 182318 override is deleted on theme deploy.

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
