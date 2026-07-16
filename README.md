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

- **Auto-expiry** — a daily WP-Cron event (`kwv_events_expire_events`) moves any published event past its date to **Draft**. An event stays live *on* its date and drops out of the booking dropdowns once drafted.
- **Booking dropdowns** — `modules/events-booking-form.php` replaces the placeholder choices in each venue's "Book Experience" Gravity Form (the *Event you wish to book for* field) with the published events for that venue's Brand, soonest first. Forms are matched to Brands via the `kwv_forms_installer_ids` option (see the `kwv-forms` plugin); the `kwv_events_venue_brand_map` / `kwv_events_form_brand_slug` filters override the mapping. Inert without Gravity Forms.

## Requirements

- WordPress 6.7+, PHP 7.4+
- **Secure Custom Fields** (or ACF) — supplies the field editor UI and loads `acf-json`. Field *values* are plain post meta and survive without it; only the editing UI would be lost.
- **Gravity Forms** — for the application form (optional; the CPT works without it).

## Editing fields

Field definitions are code. Edit them in the SCF admin UI on a dev environment — the plugin points SCF's load **and** save paths at `acf-json/`, so changes write back to `group_kwv_careers.json`. Commit that file. Do not hand-diverge the DB from the JSON.

## Data contracts (do not rename)

The post type slug (`kwv_career`), meta keys, and the `kwv_careers_form_id` option are relied on by existing content and the theme. They are intentionally stable across the rename from the former `kwv-careers` plugin.
