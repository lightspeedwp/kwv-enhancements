# KWV Enhancements

Site-specific enhancements and content fixes for the [KWV 2026](https://kwv.co.za) WordPress site, delivered by [LightSpeed](https://lightspeedwp.agency).

This plugin is the home for functionality that must **survive a theme switch** — post types, custom fields, and the data behind them. Presentation stays in the `kwv-theme-2026` block theme; anything that owns data lives here. New fixes and features are added as self-contained **modules**.

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
- **Application form** — `modules/careers-application-form.php` installs the shared "KWV Job Application" Gravity Form once and stores its id in the `kwv_careers_form_id` option. Per-job override via the Application Form field; resolution order is handled by `kwv_careers_get_form_id()`. The `[kwv_career_form]` shortcode renders whichever form a job resolves to.

## Requirements

- WordPress 6.7+, PHP 7.4+
- **Secure Custom Fields** (or ACF) — supplies the field editor UI and loads `acf-json`. Field *values* are plain post meta and survive without it; only the editing UI would be lost.
- **Gravity Forms** — for the application form (optional; the CPT works without it).

## Editing fields

Field definitions are code. Edit them in the SCF admin UI on a dev environment — the plugin points SCF's load **and** save paths at `acf-json/`, so changes write back to `group_kwv_careers.json`. Commit that file. Do not hand-diverge the DB from the JSON.

## Data contracts (do not rename)

The post type slug (`kwv_career`), meta keys, and the `kwv_careers_form_id` option are relied on by existing content and the theme. They are intentionally stable across the rename from the former `kwv-careers` plugin.
