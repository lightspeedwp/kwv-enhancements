# KWV Enhancements — PHP tests

Two PHPUnit suites, a merged 80% line-coverage gate on `modules/`, WordPress Coding Standards and PHPStan. Composer is used for this tooling only; the plugin itself still has no runtime dependencies.

| Suite | What it is | Config | Speed |
|---|---|---|---|
| **unit** | Pure PHP. WordPress functions are mocked per test with [Brain Monkey](https://giuseppe-mazzapica.gitbook.io/brain-monkey/); Gravity Forms is a small in-memory fake (`tests/Unit/Stubs/`). No database. | `phpunit.xml.dist` | < 1 s |
| **integration** | Real WordPress 7.1.3 with WooCommerce, Secure Custom Fields and (when available) Gravity Forms, installed into a throwaway MySQL database by the WordPress test library. | `phpunit.integration.xml.dist` | ~15–30 s |

## Install

Requirements: PHP 8.2+, Composer 2, MySQL (or MariaDB), and PCOV for coverage.

```bash
composer install

# Coverage driver (once per machine). On macOS with Homebrew PHP:
CPPFLAGS="-I$(brew --prefix pcre2)/include" pecl install pcov
```

Keep PCOV disabled by default (`pcov.enabled=0` in `php.ini`) so everyday PHP isn't slowed down; `composer test:coverage` switches it on for its own runs.

## The integration database

The WordPress test library **drops and recreates every table** in the database it is given on each run. Use a database that exists only for this.

Local setup (Homebrew MySQL, `root` with no password):

```bash
brew services start mysql
mysql -uroot -e 'CREATE DATABASE IF NOT EXISTS kwv_enhancements_tests'
```

Connection settings come from environment variables, with these defaults (`tests/Integration/wp-tests-config.php`):

| Variable | Default |
|---|---|
| `WP_TESTS_DB_NAME` | `kwv_enhancements_tests` |
| `WP_TESTS_DB_USER` | `root` |
| `WP_TESTS_DB_PASSWORD` | *(empty)* |
| `WP_TESTS_DB_HOST` | `127.0.0.1` |

WordPress core comes from Composer (`roots/wordpress-no-content`, pinned to the site's version). The local Studio site is never used: it runs on SQLite and holds real content.

### Third-party plugins

WooCommerce, Secure Custom Fields and Gravity Forms are **not** committed or installed by Composer. They are loaded from an existing plugins folder:

```bash
export KWV_WP_PLUGINS_DIR=/path/to/wp-content/plugins   # e.g. the local site's
```

The default is the folder this plugin sits in (fine when the plugin is checked out inside a site; a git worktree elsewhere needs the variable). WooCommerce and SCF are required; the suite stops with a clear message if they are missing. **Gravity Forms is optional**: it is commercial, so CI doesn't have it. Tests that need the real plugin call `$this->requireGravityForms()` and are skipped without it; the Gravity Forms logic itself is covered by the unit suite against the fake.

WooCommerce Subscriptions isn't loaded at all; `WinemakersClub` is unit-tested with mocked products.

## Running

```bash
composer test               # unit, then integration
composer test:unit
composer test:integration
composer test:coverage      # both suites with PCOV, merged; fails below 80% of lines in modules/
composer lint               # PHP syntax (parallel-lint) + WordPress Coding Standards
composer analyse            # PHPStan, level 5, WordPress + WooCommerce stubs
composer wpcs:fix           # auto-fix what phpcbf can
composer test:mutation      # mutation testing, both suites (slow: minutes)
composer test:snapshots:update   # rewrite golden files after an intended change
```

Single test or class: `vendor/bin/phpunit --filter BrandsTest -c phpunit.integration.xml.dist`.

Coverage output lands in `build/coverage/` (`html/index.html` to browse, `clover.xml` for tools). PHPUnit 9 has no coverage threshold of its own, so `tests/bin/coverage-check.php` reads the merged Clover report and exits non-zero below the floor.

## Adding tests for a new module

1. **Decide the suite.** Logic that takes inputs and returns values, or that only talks to WordPress through a handful of functions, belongs in **unit** (fast, runs everywhere). Behaviour that depends on WordPress itself — registration, queries, rendering blocks, REST, cron, templates — belongs in **integration**. Many modules have both.
2. **Unit:** add `tests/Unit/<Module>Test.php` extending `KWV\Enhancements\Tests\Unit\TestCase`, and add the module's file to the load list in `tests/Unit/bootstrap.php` (same order as `kwv-enhancements.php`). Mock functions with `Brain\Monkey\Functions\when()` / `expect()`. Helpers: `stubOptions()` (in-memory options), `captureRedirects()` + `expectRedirectFrom()` (for code that redirects and `exit`s).
3. **Integration:** add `tests/Integration/<Module>Test.php` extending `KWV\Enhancements\Tests\Integration\TestCase` (a `WP_UnitTestCase`). Every test runs in a transaction that is rolled back. Use `self::factory()` for posts/terms/users, `$this->go_to( $url )` to simulate a page view, `$this->image()` / `$this->pdf()` for attachments, `$this->ymd( $days )` for SCF dates.
4. Tag the class with `@covers \KWV\Enhancements\<Class>`.
5. Run `composer test:coverage` and check the module's figure in the output.

Things worth knowing:

- `WP_UnitTestCase` unregisters **all meta keys** after each test. `TestCase::set_up()` re-registers the Careers and Events meta; do the same for a new module that calls `register_post_meta()` / `register_term_meta()`.
- `new WP_Block( $parsed, $context )` keeps only the context keys its block type declares. To test a binding callback directly, set `$block->context` yourself.
- `wp_is_serving_rest_request()` reads the `REST_REQUEST` constant, so editor-only output is tested in a separate process (`EditorPlaceholderTest`). The bootstrap skips the reinstall and silences third-party load notices in child processes.
- Module code runs once when the plugin loads, before coverage starts. To measure a `register_*()` method, unregister and call it again inside the test (see `BlockBindingsTest`).

## Snapshots (golden files)

Some output is checked as a whole against a stored copy in `tests/__snapshots__/`:

| Snapshot | What it pins |
|---|---|
| `careers-application-form.json` | The provisioned Gravity Form: every field, both notification emails (full HTML), the confirmation |
| `email-with-logo.html`, `email-without-logo.html` | The branded email shell built from every `EmailTemplate` part |
| `brand-awards.html`, `event-accommodation.html`, `careers-dialog.html` | The markup of the dynamic blocks and the careers modal |

Any change to that output fails the test with a diff. If the change is intended, run `composer test:snapshots:update`, **review the diff in git**, and commit the updated files with the change. Run-specific values (post and attachment IDs, `uniqid()` confirmation IDs, `wp_unique_id()` counters) are replaced with placeholders such as `{OPEN_JOB}` before comparing. A missing snapshot is written on first run and the test is marked incomplete; on CI (`CI=true`) a missing snapshot fails.

## Hook contract

`tests/Support/PluginHooks.php` lists every hook the plugin adds, with its priority and accepted-argument count. Both suites assert it: the unit suite against Brain Monkey's record of `add_action()`/`add_filter()`, the integration suite against WordPress's own `$wp_filter` (plus a check that no callback requires more arguments than WordPress passes it). Adding, removing or re-wiring a hook means updating that table.

## Mutation testing

Line coverage says code ran; it doesn't say a test would notice if the code were wrong. [Infection](https://infection.github.io/) checks that: it makes small changes to `modules/` (flips `<` to `<=`, drops a call, negates a condition, changes a hook priority) and reruns the tests that cover the changed line. A change no test notices ("escaped mutant") points at a missing or weak assertion.

```bash
composer test:mutation:unit          # ~2 min; fails below 95% covered-code MSI
composer test:mutation:integration   # longer; one throwaway database per thread
```

Reports go to `build/infection/` (`unit.log`, `integration.log`); each escaped mutant is listed with its diff.

- The integration run uses one database per Infection thread (`kwv_enhancements_tests_t0` … `_t5`). Infection passes each process a `TEST_TOKEN`, `wp-tests-config.php` appends it to the database name, and `tests/bin/mutation-databases.php` creates and installs them first. Mutant runs then set `WP_TESTS_SKIP_INSTALL=1` and reuse them.
- Expect a few survivors that can't be killed, because the mutated code behaves identically: mostly removed `(int)`/`(string)` casts, which PHP's type coercion makes harmless. Visibility mutators are disabled for the same reason.
- When you add a module, run `composer test:mutation:unit` and read any escaped mutants in its file before merging.

## CI

`.github/workflows/php-tests.yml` runs on pull requests to `develop`, `master` and `main`: lint, PHPStan, `composer test:coverage`, and unit mutation testing (`composer test:mutation:unit`, 95% floor) against a MySQL 8 service container, with WooCommerce and SCF downloaded from wordpress.org at the versions the site runs. Actions are pinned to commit SHAs; no secrets are used. The coverage HTML is uploaded as a build artifact.

## Known pre-existing findings

- **WPCS** (`composer wpcs:scan`): about 22 errors and 4 warnings in `modules/`, from before this tooling existed — mostly missing short doc descriptions, Gravity Forms' camelCase field properties (`inputName`, `conditionalLogic`), slow-query warnings for meta/tax queries that are intended, and `module.php` holding three interfaces. CI reports them without failing. Tests and tooling are clean.
- **PHPStan**: one baselined finding (`phpstan-baseline.neon`): the stubs type `WP_HTML_Tag_Processor::next_tag()` as array-only, but core accepts a tag-name string.
