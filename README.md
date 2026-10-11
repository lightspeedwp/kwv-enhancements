# KWV Enhancements

Site-specific enhancements and content fixes for the [KWV 2026](https://kwv.co.za) WordPress site, delivered by [LightSpeed](https://lightspeedwp.agency).

This plugin is the home for functionality that must **survive a theme switch** — post types, custom fields, and the data behind them. Presentation stays in the `kwv-theme-2026` block theme; anything that owns data lives here. New fixes and features are added as self-contained **modules**.

## Architecture

A small object-oriented module system under the `KWV\Enhancements` namespace:

- **`Module`** (`modules/module.php`) — every module implements this one-method interface (`register()`, where it wires its hooks). Modules that need setup/teardown also implement **`Activatable`** / **`Deactivatable`**.
- **`PostTypeModule`** (`modules/post-type-module.php`) — an abstract base that holds the machinery shared by every content type: registering a REST-exposed CPT, registering its string meta fields, and running a daily Ymd-date auto-expiry cron. `Careers` and `Events` extend it and supply only what differs (labels, args, field list, and which meta key drives expiry).
- **`Plugin`** (`modules/plugin.php`) — the container. It instantiates the modules, calls `register()` on each, and delegates activation/deactivation (flushing rewrite rules once at the end).
- **`kwv-enhancements.php`** — a thin bootstrap: defines the `KWV_ENH_*` constants, `require`s the module files, and boots `Plugin::instance()`.

The concrete modules are `AcfJson`, `BlockBindings`, `Careers`, `ApplicationForm`, `Events` and `BookingForm`.

## Modules

### Careers

The `kwv_career` job post type and its Gravity Forms application form.

- **Post type** `kwv_career` — title = role, content = job description, rewrite `careers/{slug}`, no archive (the Careers *page* is the listing). Registered in `modules/careers.php`.
- **Custom fields** — authored with **Secure Custom Fields** and stored as code in [`acf-json/group_kwv_careers.json`](acf-json/group_kwv_careers.json). Field *names* match the `register_post_meta` keys, so SCF writes to the same REST-exposed keys the theme's block bindings read.

  | Field | Meta key | Notes |
  |---|---|---|
  | Reference Number | `kwv_career_reference` | e.g. `BMMS0128`. Bound on the listing; merged into applications. |
  | Location | `kwv_career_location` | Free text. |
  | Department | `kwv_career_department` | Free text. |
  | Closing Date | `kwv_career_closing_date` | Date picker. **Auto-expires** the job (see below). |
  | Application Form | `kwv_career_form_id` | Gravity Forms form to embed. Blank = shared form. |

- **Auto-expiry** — a daily WP-Cron event (`kwv_careers_expire_jobs`) moves any published job past its closing date to **Draft** (reversible, and it drops off the front-end listing which only queries published posts). A job stays live *on* its closing day.
- **Application form** — `modules/careers-application-form.php` (the `ApplicationForm` module) installs the shared "KWV Job Application" Gravity Form once and stores its id in the `kwv_careers_form_id` option. Per-job override via the Application Form field; resolution order is handled by `Careers::get_form_id()`. The `[kwv_career_form]` shortcode renders whichever form a job resolves to.

### Events

The `kwv_event` post type for the Visit Us venues, its **Brand** taxonomy, and the dynamic booking-form dropdowns.

- **Post type** `kwv_event` — title = event name, content = description, native **Featured Image**, rewrite `events/{slug}`, no archive. Registered in `modules/events.php`.
- **Brand taxonomy** `kwv_brand` — ties each event to a venue. Seeded with **KWV Emporium**, **House of Fire** and **Cathedral Cellar** (slugs `kwv-emporium`, `house-of-fire`, `cathedral-cellar`); more can be added in the admin. Term seeding is one-time and guarded by the `kwv_events_brands_seeded` option.
- **Custom fields** — authored with **Secure Custom Fields**, stored as code in [`acf-json/group_kwv_events.json`](acf-json/group_kwv_events.json). Field *names* match the `register_post_meta` keys.

  | Field | Meta key | Notes |
  |---|---|---|
  | Date | `kwv_event_date` | Date picker (stored `Ymd`). **Auto-expires** the event. |
  | Time | `kwv_event_time` | Time picker, e.g. `18:00`. |
  | Accommodation nearby | `kwv_event_accommodation` | Repeater: *Link* (`url`), *Name* (`title`), optional *Image* (`image`, attachment ID). Stored as `kwv_event_accommodation_{n}_{sub_field}`. |

- **Book now links** — the `kwv/event-booking-url` binding source (`modules/block-bindings.php`) gives the single-event "Book now" buttons a URL of `{path}?event={label}&event_url={permalink}`. The venue booking forms (field 3, parameter `event`) and the generic Event Enquiry form (`event` → Event field, `event_url` → hidden Event page field) pre-populate from it. Which button shows is decided by Block Visibility rules on the Brand taxonomy in the template, not by code.
- **Accommodation nearby** — `modules/event-accommodation.php` registers the dynamic `kwv/event-accommodation` block (`blocks/event-accommodation/`), which the theme places on the single-event template. It renders a heading and a grid of linked cards from the repeater, and nothing when an event has no entries. The platform label ("via Airbnb") comes from the link's host. Cards are core block markup styled by the theme's Event Card section style, so the plugin ships no CSS.

- **Auto-expiry** — a daily WP-Cron event (`kwv_events_expire_events`) moves any published event past its date to **Draft**. An event stays live *on* its date and drops out of the booking dropdowns once drafted.
- **Booking dropdowns** — `modules/events-booking-form.php` replaces the placeholder choices in each venue's "Book Experience" Gravity Form (the *Event you wish to book for* field) with the published events for that venue's Brand, soonest first. Forms are matched to Brands via the `kwv_forms_installer_ids` option (written by the retired `kwv-forms` installer plugin; the forms themselves are now database-owned and edited in the Gravity Forms admin); the `kwv_events_venue_brand_map` / `kwv_events_form_brand_slug` filters override the mapping. Inert without Gravity Forms.
- **Booking visibility rules** — two rules follow from that dynamic list, so they are computed per request rather than stored in the form:
  - **No events for the venue → no dropdown.** An empty select is a dead end, so the field is marked `administrative`, which removes it from both the rendered form and validation.
  - **An event chosen → no Date/Time.** Each event choice carries its own date and time, so the manual *Date* + *Time* pair is only for open-ended requests. The conditional-logic rule (*show while the event field is empty*) is seeded in the form definition so it is visible in the form editor, and re-applied on every render so an editor save cannot drift it; it is dropped when the dropdown is hidden, leaving Date/Time always visible. Gravity Forms skips validation for conditionally-hidden fields, so the pair stays *required* without blocking an event booking.

  These run on `gform_pre_render` / `gform_pre_validation` / `gform_pre_submission_filter` only — `gform_admin_pre_render` gets the choices alone, so the editor never persists a per-request visibility state.

### Payflex configuration cache

A performance workaround for a bug in **Payflex Payment Gateway 2.7.1**, in `modules/payflex-configuration-cache.php`.

`WC_Gateway_Payflex::get_payflex_limits()` (`includes/class-wc-gateway-payflex.php:964-970`) reads `$settings` on the line *before* it is assigned, so its `isset( $settings['payflex_limit_last_updated'] )` guard is always false and the intended 24-hour throttle never applies. `update_payment_limits()` therefore makes a blocking remote GET to the Payflex `/configuration` endpoint every time it is called — and it is called from `check_cart_within_limits()`, hooked to `woocommerce_available_payment_gateways`, which WooCommerce fires several times per page render.

Measured on staging, 2026-08-10: eight blocking Payflex calls per render at ~0.81s each, for a **7.9s** origin TTFB on `/product-category/wine/`. Caching this one response brought it to **~1.1s**.

The module caches only the successful `/configuration` GET, for 12 hours, via `pre_http_request` / `http_response`. Failures are never cached, so the gateway stays free to retry. The auth token is deliberately left alone — the gateway already caches it in the `payflex_access_token` transient with the expiry the API returns.

**Remove this module once Payflex ship a fix**, and confirm limits still refresh.

## Requirements

- WordPress 6.7+, PHP 7.4+
- **Secure Custom Fields** (or ACF) — supplies the field editor UI and loads `acf-json`. Field *values* are plain post meta and survive without it; only the editing UI would be lost.
- **Gravity Forms** — for the application form (optional; the CPT works without it).

## Editing fields

Field definitions are code. Edit them in the SCF admin UI on a dev environment — the plugin points SCF's load **and** save paths at `acf-json/`, so changes write back to `group_kwv_careers.json`. Commit that file. Do not hand-diverge the DB from the JSON.

## Data contracts (do not rename)

The post type slug (`kwv_career`), meta keys, and the `kwv_careers_form_id` option are relied on by existing content and the theme. They are intentionally stable across the rename from the former `kwv-careers` plugin.
