# Brand pages — how they work

Internal reference for the redesigned brand pages (`/brand/{slug}/`), built for
Estimate 3439 ("KWV - Brand page redesign"). It covers the moving parts, where
each one lives, and what to check when something looks wrong.

The client-facing guide (how to fill in a brand) is separate:
`reports/brand-page/kwv-brand-pages-guide.md` in the workspace.

## The short version

A brand page is the normal WooCommerce `product_brand` archive. Two codebases
share the work:

| Concern | Lives in | Why there |
|---|---|---|
| Brand content fields (logo, intro, awards, links) and how they reach blocks | **KWV Enhancements** plugin | Content must survive a theme change |
| Layout, the product slider, styling | **kwv-theme-2026** | Presentation |

Nothing on the page is hard-coded per brand. Everything comes from the brand
term's fields or from the products assigned to it, and every field is optional.

```
templates/taxonomy-product_brand.html
└─ patterns/woo-brand-archive.php
   ├─ patterns/term-banner-hero.php      banner (existing kwv/term-banner binding)
   ├─ woocommerce/breadcrumbs
   ├─ Brand Introduction group (.kwv-brand-intro)
   │  ├─ core/image      ← kwv/brand {key: logo}
   │  ├─ core/paragraph  ← kwv/brand {key: intro}
   │  ├─ kwv/brand-awards (dynamic block)
   │  └─ separator
   ├─ woocommerce/product-collection (.kwv-brand-slider, inherits the brand query)
   │  └─ product-template
   │     └─ template part "brand-product-slide"   ← the editable slide
   └─ Thumbnails + Brand Links columns
      ├─ .kwv-brand-slider-thumbs (filled by JS)
      ├─ core/social-links, each social-link ← kwv/brand {key: facebook|instagram|x|youtube|tiktok|linkedin}
      └─ core/button (Text Link style) ← kwv/brand {key: website}
```

## Plugin side (`kwv-enhancements`)

### Fields — `acf-json/group_kwv_brands.json`

SCF field group "Brand Page Content", location `taxonomy == product_brand`.
SCF stores a term field as term meta under the field name, so values are
readable with plain `get_term_meta()` and the page still renders if SCF is
switched off (only the admin UI disappears).

| Field | Meta key | Stored as |
|---|---|---|
| Brand logo | `kwv_brand_logo` | attachment ID |
| Brand introduction | `kwv_brand_intro` | plain text (textarea, line breaks kept) |
| Awards & accolades | `kwv_brand_awards` | serialised array of attachment IDs (gallery, max 8) |
| Brand website link | `kwv_brand_website` | URL |
| Facebook … LinkedIn | `kwv_brand_{facebook,instagram,x,youtube,tiktok,linkedin}` | URL |

SCF also writes `_kwv_brand_*` rows holding the field key. If you seed values
by SQL, write those too, or the admin screen won't recognise the values.

The banner image is **not** in this group. It is the older theme-owned
`kwv_banner_image_id` term meta (`inc/term-banner.php`), shared with product
categories.

### `modules/brands.php` — the `Brands` module

Registered in `modules/plugin.php` like every other module. It does four things:

1. **`kwv/brand` block-binding source.** Blocks ask for a value with
   `{"source":"kwv/brand","args":{"key":"…"}}`. `Brands::value()` normalises it:
   intro is trimmed and escaped (`nl2br(esc_html())` for `content`), logo must be
   an image attachment, links must be http(s) (`esc_url_raw` with the
   protocol list). The brand is always `get_queried_object()`. Outside a brand
   archive (the Site Editor, any other template) the source returns `null`, so
   the block keeps its saved placeholder value. That's what you see in the editor.
2. **Social icons are bindable.** Core doesn't list `core/social-link` as a
   bindings-capable block. The module adds `url` through the
   `block_bindings_supported_attributes_core/social-link` filter. Core already
   renders nothing for a social link without a URL.
3. **Blank fields disappear.** A `render_block` filter checks every block with a
   `kwv/brand` binding. If the brand's value for that key is empty, the block
   returns `''`. This is the single rule behind "a field left blank is not shown"
   (logo, intro, website button, each social icon).
4. **`kwv/brand-awards` block** (`blocks/brand-awards/`). Dynamic. It renders the
   gallery IDs as `core/image` blocks in a centred flex group through
   `do_blocks()`, so the theme styles apply. It renders nothing when there are no
   awards, and shows an editor-only note in the Site Editor (ServerSideRender).
   Each image's Alt Text is its accessible name. The wrapper is a labelled
   `role="group"`.

## Theme side (`kwv-theme-2026`)

### Template and pattern

- `templates/taxonomy-product_brand.html` inserts `kwv/woo-brand-archive`. Shop
  and category archives still use `kwv/woo-product-archive` and are unchanged.
- The Product Collection has `inherit: true`, so it shows exactly the main brand
  query. Order is therefore the shop's default sorting (WooCommerce →
  Customizer → Product Catalog → Default product sorting; **Popularity** on dev).
  `inc/brand-page.php` raises `loop_shop_per_page` to 100 on brand archives, so
  the whole range is one slider and there is no pagination.
- Draft and catalogue-hidden products drop out because that is how the main
  query behaves.

### The editable slide — `parts/brand-product-slide.html`

A template part registered in `theme.json` → `templateParts` ("Brand Product
Slide"). It's ordinary WooCommerce/core blocks inside a group with the section
style `is-style-brand-product-slide` (`styles/sections/brand-product-slide.json`):

- product image (`imageSizing: single`, `contain`, not linked)
- brand name (`core/paragraph` bound to `kwv/brand {key:name}`)
- `core/post-title` (h2), product summary (short description, font size 300)
- "Product detail" heading + `woocommerce/product-specifications` (weight and
  dimensions off, so only attributes ticked *Visible on the product page* show)
- price, then a row with `woocommerce/product-button` (`is-style-add-to-cart`)
  and the stock indicator
- `core/read-more` "View product"

Editing this part in the Site Editor changes every brand page. A saved edit lives in
the database (`wp_template_part`, slug `brand-product-slide`) and shadows the
file. Resetting the part in the Site Editor returns to the file version.

### Slider — `assets/js/brand-slider.js` + `inc/brand-page.php`

- **Swiper** comes from the Carousel Block plugin's *full* bundle
  (`carousel-block/blocks/vendor/swiper/swiper-bundle.min.{js,css}`). The block's
  own build lacks the Thumbs/Keyboard/A11y modules. Loaded on brand archives only.
  If the plugin is removed, the script isn't enqueued and the page falls back to
  a stacked list.
- The script **decorates, never moves** markup: it adds `swiper`,
  `swiper-wrapper` and `swiper-slide` classes to the Product Collection, its
  `<ul>` and each `<li>`. WooCommerce's interactivity (Add to Cart, mini-cart
  update) keeps working untouched.
- It creates the arrow buttons and builds the thumbnail strip from each slide's
  own product image, inside `.kwv-brand-slider-thumbs`. Thumbnails have their
  own click handler, because Swiper's Thumbs module only reacts to pointer taps,
  and a click handler also covers Enter/Space.
- Accessibility: slides that aren't showing get `inert`, the current thumbnail
  gets `aria-current="true"`, slides keep their list semantics with an "Product
  n of N" label, reduced motion means instant changes, and arrow keys work while
  the slider is in view.
- **No flash:** `inc/brand-page.php` prints a one-line script in `<head>` that
  adds `kwv-brand-slider-js` to `<html>`. CSS then hides every product but the
  first until Swiper starts. If Swiper is missing the script removes the class
  again. Without JavaScript the class never appears and all products show.
- A brand with one product gets `kwv-brand-slider--single` and no slider.

### Styling — `assets/styles/woocommerce-brand-page.css`

This is a stylesheet rather than block-style JSON because almost everything in it
targets run-time state (Swiper classes, WooCommerce's `instock`/`outofstock`/
`product-type-*` classes on each `<li>`), JS-created elements, or pseudo-states.
That's the AGENTS.md exception. Points worth knowing:

- **Add to Cart visibility** is CSS on the `<li>` classes: hidden for
  `.outofstock` and for anything that isn't `.product-type-simple`. WooCommerce's
  stock indicator prints "Out of stock"; "In stock" is hidden.
- The Add to Cart look is shared with the shop grid (`woocommerce-product-grid.css`,
  selector `:is(.is-style-product-card, .kwv-brand-slide)`). The brand page
  widens the button's side padding with one extra class of specificity, because
  the grid sheet loads later (it's a block style printed in the body).
- `.kwv-brand-intro` is hidden when it contains nothing but its separator
  (`:has()`), so a brand with no content doesn't leave a lone divider.

### Pasted short descriptions

Product short descriptions are often pasted from elsewhere. KWV 10 Year Old
Brandy's is a `<table width="1876">` with inline centring, which pushed text out of
its slide into the neighbours. Two layers of defence:

1. `inc/brand-page.php` → `clean_product_summary()` filters
   `render_block_woocommerce/product-summary` **on brand pages only**. It uses
   `WP_HTML_Tag_Processor` to strip `style`, `width`, `height`, `align` and
   `data-*` from everything inside the summary (the block's own two wrappers are
   left alone). Product data isn't changed, and the single product page still
   shows it as entered.
2. CSS flows table elements as blocks, and each slide has `overflow: hidden` as a
   backstop.

Duplicate short descriptions (the title repeated, e.g. "KWV 12Yr Old Brandy")
are a data issue for KWV, not something the page hides.

## Tests

`wp-content/themes/kwv-theme-2026/tests/e2e/brand/brand-page.spec.ts`
(BRAND-TC1–TC10): layout and breadcrumbs, field content, blank fields hidden,
slider by arrow/key/thumbnail (pointer and keyboard), price above Add to Cart,
no description overflow, Add to Cart → mini-cart, stock states, phone layout,
and the no-JavaScript fallback.

```bash
cd wp-content/themes/kwv-theme-2026/tests/e2e
KWV_BRAND_SLUG=kwv-brandy KWV_EMPTY_BRAND_SLUG=wild-africa-cream \
  npx playwright test brand/ --project=chromium
```

`KWV_BRAND_SLUG` must be a brand with content and at least two products.
`KWV_EMPTY_BRAND_SLUG` must be a brand with no brand content. TC7 adds one
product to a fresh guest cart. Everything else is read-only.

## Deploy checklist

- Deploy the theme **and** the plugin. The template needs the `kwv/brand` source
  and the awards block.
- Remove any database copy of the `taxonomy-product_brand` template. It shadows
  the theme file (on dev it was post 182616, now in the trash; its content is
  backed up in option `kwv_backup_taxonomy-product_brand_182616_2026-11-12`).
- Purge the host edge cache / WP Rocket.
- Brand content is entered per environment. Values entered on dev don't travel
  to live.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| Old grid with filters on `/brand/…` | A DB `taxonomy-product_brand` template is shadowing the file, or the cache is stale |
| Placeholder text ("The brand introduction appears here…") on the front end | The `Brands` module isn't loaded (plugin inactive or not deployed) |
| Products stacked, no arrows | Swiper bundle missing (Carousel Block plugin removed or moved) or a JS error earlier on the page |
| Field filled in but not showing | Link not starting with `http(s)://`; logo/award not an image; object cache serving old term meta (`wp cache flush`) |
| Slide layout changed on every brand | Someone edited the Brand Product Slide part in the Site Editor; reset it there |
| An attribute missing from "Product detail" | Attribute not ticked *Visible on the product page* on that product |
