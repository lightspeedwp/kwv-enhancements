# Playbook: adding a content type (CPT) to KWV

A repeatable recipe for adding a custom post type with SCF fields, a listing and a single template, using **Careers (`kwv_career`)** as the worked example. Follow the same steps for **Events** or any future type.

It encodes the KWV split: **the plugin owns data** (post type, meta, field definitions, dynamic rendering), **the theme owns presentation** (templates, patterns, section styles). Nothing that owns data goes in the theme — it must survive a theme switch.

---

## 0. Architecture at a glance

```
KWV Enhancements plugin (this repo)            kwv-theme-2026 (theme repo)
├─ modules/<type>.php        CPT + meta class   ├─ patterns/template-single-<type>.php
├─ acf-json/group_<type>.json  SCF fields       ├─ templates/single-<cpt>.html
├─ modules/block-bindings.php  formatting       ├─ patterns/<type>-card.php   (listing item)
└─ modules/<type>-*.php      extras (forms…)    └─ styles/sections/cards/<type>-card.json
                                                └─ assets/styles/core-*.css   (:hover only)
```

The plugin is a small OO module system (namespace `KWV\Enhancements`). Each concern is a class implementing the `Module` interface; a content type extends the **`PostTypeModule`** base (`modules/post-type-module.php`), which already owns CPT registration, the meta-field loop, and the hourly auto-expiry cron. The `Plugin` container (`modules/plugin.php`) instantiates the modules and drives their lifecycle. See the README's Architecture section.

Key rule: **an SCF field's `name` MUST equal the `register_post_meta` key**, so SCF writes to the same REST-exposed key the theme's block bindings read. That single decision is what makes fields work end-to-end.

---

## 1. Plugin — register the post type + meta (`modules/<type>.php`)

Add a class that **extends `PostTypeModule`**. The base already registers the CPT and meta on `init`, self-heals the expiry schedule (including moving an old daily event onto the hourly cadence), and runs the expiry cron — you implement only the abstract hooks:

1. `post_type()` / `post_type_args()` — the slug and the `register_post_type()` args. Keep `show_in_rest => true`, set the rewrite slug, decide `has_archive`. **The CPT lives in code here — not in SCF's post-type UI** — so it doesn't depend on SCF being active and is portable/reviewable.
2. `meta_fields()` — return a `meta key => description` map. The base registers each as single, REST-exposed, sanitised string meta with an `edit_posts` `auth_callback`. This is the contract that makes meta REST-visible and binding-friendly. **SCF supplies the editor UI; `register_post_meta` supplies the contract — keep both.**
3. `expiry_hook()` / `expiry_meta_key()` — the cron hook name and the `Ymd` date meta key that drives auto-expiry (declare the hook name as a class const). The base handles scheduling, self-healing on `init`, and drafting past-dated items. Override `hides_expired()` to return `true` if listings should drop past-dated items the moment their date passes (Careers does); `is_expired()` is available for templates/forms. Override `meta_sanitize_callback()` for fields that aren't plain text (Careers sanitises its LinkedIn URL with `esc_url_raw`).
4. Anything type-specific (a taxonomy, a shortcode, an enqueue) goes in an overridden `register()` that calls `parent::register()` first — see how `Events` adds its Brand taxonomy and `Careers` adds the form shortcode. If a module needs extra activation work, override `activate()` (call the parent or re-run the pieces in order).
5. `require` the file in `kwv-enhancements.php` and add the class to the module list in `Plugin::__construct()`. Activation/deactivation are automatic for any module implementing `Activatable`/`Deactivatable` (which `PostTypeModule` does).

Careers reference: [`modules/careers.php`](../modules/careers.php); base class [`modules/post-type-module.php`](../modules/post-type-module.php).

## 2. Plugin — author fields in SCF, stored as code (`acf-json/group_<type>.json`)

The bootstrap already points SCF's **load *and* save** paths at `acf-json/` (`acf/settings/load_json` + `save_json`). So:

- **Author** the field group in the SCF admin UI on a dev/local site (Custom Fields → Field Groups) — SCF writes the JSON into `acf-json/`. **Or** hand-write the JSON (we did — the format is stable).
- **Each field's `name` = the meta key** from step 1 (e.g. field `name: kwv_career_reference`).
- Location rule: `post_type == <cpt>`.
- Commit the JSON. Never let the DB diverge from it.

Gotchas that bit us:
- **Tokens:** this theme has **no** `--wp--custom--spacing--small/medium`, `--border-width--thin`, `--font-size--small`, or `--custom--color--*`. Colours are **preset** (`--wp--preset--color--contrast`), spacing/size are **numeric** scales. Use real values.
- **Date picker stores `Ymd`** (e.g. `20260815`) in the DB regardless of the display/return format. Plan formatting around that (see step 3).
- **Fields only appear when the plugin is active** — SCF alone shows nothing; it's the plugin that feeds SCF the `acf-json` path.

Careers reference: [`acf-json/group_kwv_careers.json`](../acf-json/group_kwv_careers.json).

## 3. Plugin — reusable rendering primitives (`modules/block-bindings.php`, shortcodes)

Some values need light server-side logic the core `core/post-meta` binding can't do:

- **Formatted dates** → the `kwv/meta-date` block-binding source. Bind a paragraph's `content` to it with `args: { key, format }`. It reads the `Ymd` meta and returns it via `wp_date()`. **Reusable for Events dates as-is.**
  ```
  "bindings":{"content":{"source":"kwv/meta-date","args":{"key":"<date_key>","format":"j F Y"}}}
  ```
- **Dynamic embeds** (e.g. a per-item Gravity Form) → a shortcode that resolves per render. Careers uses `[kwv_career_form]`, resolving the per-job form id (falling back to a shared form). Resolve the post as `id att → get_the_ID() → get_queried_object_id()` so it works inside block templates.

Careers reference: the `BlockBindings` module in [`modules/block-bindings.php`](../modules/block-bindings.php), `Careers::render_form()` in [`modules/careers.php`](../modules/careers.php).

**Styling a plugin-rendered component.** Presentation normally lives in the theme — but when the plugin *renders* a component (a shortcode/form) and it needs CSS a block-style JSON can't express (third-party form markup, `:hover`, `::file-selector-button`), style it **from the plugin** with a scoped stylesheet enqueued on the relevant view. This is both correct (the plugin owns what it renders) and practical (the plugin deploys to every environment; a theme may not be writable on a given host). Scope to a stable hook like the `.single-<cpt>` body class, use the theme's `--wp--*` custom properties to stay token-driven, and `!important` where you must beat a third-party plugin's own stylesheet. Careers reference: [`assets/careers-form.css`](../assets/careers-form.css) + `Careers::enqueue_form_style()`.

## 4. Theme — the single template

1. **Pattern** `patterns/template-single-<type>.php` holds the body; **`templates/single-<cpt>.html`** is a one-liner that references it: `<!-- wp:pattern {"slug":"kwv/template-single-<type>"} /-->`.
2. Header set (`Template Types: single`, `Post Types: wp_template`, `Inserter: false`, `Categories: hidden`).
3. Surface meta with **block bindings**: `core/post-meta` for plain text (reference, location, department), `kwv/meta-date` for dates. Put static labels in sibling paragraphs.
4. Embed dynamic pieces via the shortcode block (`<!-- wp:shortcode -->[kwv_career_form]<!-- /wp:shortcode -->`).
5. **Inline sub-patterns with `require`**, never a nested `wp:pattern` — a nested pattern silently drops on the front end. Careers' hero is pulled in with `require __DIR__ . '/careers-hero.php';`.

Careers reference: [`patterns/template-single-career.php`](../../../themes/kwv-theme-2026/patterns/template-single-career.php).

## 5. Theme — the listing card + section style

1. **Card pattern** `patterns/<type>-card.php` = one query-loop item, `Block Types: core/post-template`, carrying an `is-style-<type>-card` class on its root block. Structure + bindings only — **no inline styling**.
2. **Section style** `styles/sections/cards/<type>-card.json` (`blockTypes: ["core/columns"]` or `core/group`) holds all the card's inner-block styling under `styles.blocks."core/…"`, token-referenced by slug. This is the single source of truth for the look.
3. **`:hover`/`:focus` only** go in a per-block `assets/styles/core-<block>.css` (auto-enqueued by the theme's `core-*.css` glob). JSON's `css` field / structured `:hover` compile to zero specificity and are unreliable for hover — mirror the `outline-dark` → `core-button.css` pattern with a real selector (no `!important`).
4. In the listing pattern, **inline the card with `require`** inside the `post-template` (not `wp:pattern`).

Careers reference: [`patterns/career-card.php`](../../../themes/kwv-theme-2026/patterns/career-card.php), [`styles/sections/cards/career-card.json`](../../../themes/kwv-theme-2026/styles/sections/cards/career-card.json), [`assets/styles/core-read-more.css`](../../../themes/kwv-theme-2026/assets/styles/core-read-more.css).

## 6. Deploy to dev

The dev site's active theme is **`editable: false`** — theme files **cannot** be written over MCP. So:

- **Plugin** → *is* writable: `wp_create_plugin` / `wp_plugin_put_file` / `wp_plugin_mkdir`, then `wp_activate_plugin`. Deactivate/delete the old plugin *before* activating a rename so functions/CPT aren't declared twice.
- **Theme presentation** → mirror it into the DB `wp_template` overrides that shadow the theme files: edit the relevant `wp_template` post (`page-<type>` listing, `single-<cpt>`) with a surgical SQL `REPLACE` (`wp_db_query`, `confirm_write: true`). Keep the edits minimal + uniquely-anchored.
- **⚠️ On the real theme deploy, delete these DB overrides** so the committed theme files win. Track the post IDs.

Verify after: SQL writes **don't bust the object cache**, so confirm against the rendered page (`curl` the permalink and grep for the expected output) rather than trusting the DB alone. Populate one sample item so bindings have data.

Careers dev artifacts: plugin `kwv-enhancements` (active); DB overrides `single-kwv_career` = **182318**, `page-careers` = **182319**; sample job **182320**.

## 7. Verify

- Fields show + save in the CPT editor (plugin active).
- Listing renders the card; single renders meta + description + dynamic embed.
- Dates render in the intended format; the dynamic embed resolves per item.
- `php -l` all PHP; JSON validates; `theme-orphaned-refs` clean after token changes.

---

## Events quick-swap checklist

Repeat 1–7 with: `<cpt>` = `kwv_event`, module `modules/events.php`, field group `acf-json/group_kwv_events.json` (field names = meta keys), likely fields (date/time, venue, ticket link…). Reuse `kwv/meta-date` for the event date. Theme: `template-single-event.php` + `single-kwv_event.html`, `event-card.php` + `styles/sections/cards/event-card.json`. Deploy per step 6; record the new DB-override post IDs and delete them on theme deploy.
