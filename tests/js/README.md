# JavaScript unit tests and linting

Unit tests for the plugin's browser scripts, run with [Vitest](https://vitest.dev/)
in a jsdom environment, and ESLint with the WordPress rules.

| Script | What the tests cover |
|---|---|
| `assets/careers-modal.js` | Opening a job from its link into the shared `<dialog>`, filling it from the job's `<template>`, showing the right Gravity Form and setting its Job ID, closed jobs, modified clicks, backdrop and close-button dismissal, focus return, the post-submission rule, and the no-`<dialog>` fallback. |
| `blocks/*/index.js` | Each dynamic block registers with a `ServerSideRender` preview and a `null` save; `kwv/event-accommodation` passes its post ID. |

The scripts ship as plain browser files with no build step. The tests load each
file exactly as it ships, so coverage maps to the real code.

## Setup and run

Node 20 or later (CI uses 24). From the plugin root:

```sh
npm ci
npm test            # all tests once, with coverage; fails below 80%
npm run test:watch  # re-run on change
npm run lint        # ESLint (WordPress recommended + Prettier)
```

The HTML coverage report is written to `coverage/index.html`. All dependencies
are dev-only.

> **Packaging.** `package.json`, `node_modules/`, `tests/` and the config files are
> development tooling. Leave them out of any zip or deploy of the plugin.

## How a test works

Each script runs once, on load, against the DOM and globals present at that
moment. A test:

1. renders a fixture into `document.body` (`render( fixture( 'careers-modal.html' ) )`),
2. stubs the globals the script expects (`vi.stubGlobal( 'wp', … )`), and
3. calls `loadScript( 'assets/careers-modal.js' )`, which clears the module
   registry and imports the file again so it runs fresh.

`tests/js/setup.js` removes every `document`/`window` listener after each test.
`tests/js/helpers/load.js` also has `deferReady()`, `mediaQuery()` and
`flushObservers()`. The setup and helper files are shared with the theme
(`kwv-theme-2026/tests/js/`): keep the two copies in step.

jsdom has no `HTMLDialogElement.showModal()`. The careers tests add a small fake
(sets `open`, fires `close`) for the supported-browser cases and leave it out to
test the fallback, where links simply navigate.

For a worked example of writing a test for a new script, see the theme's
`tests/js/README.md`. The same pattern applies here.

## Fixtures

`tests/js/fixtures/careers-modal.html` was captured read-only from the dev Careers
page (`https://kwv.lightspeedwp.dev/careers/`) on 2026-11-12: two job links, the
shared `#kwv-career-dialog` with its Gravity Form, and the per-job `<template>`s.
SVG path data and long attributes are shortened; structure, classes and `data-kwv-*`
attributes are exactly as rendered. To refresh it, fetch the page with
`curl -s -b 'age_gate=18' <url>` and copy those three parts.

## Linting

`eslint.config.mjs` uses `@wordpress/eslint-plugin`'s recommended set with the
WordPress Prettier fork (`wp-prettier`, installed as `prettier`). `assets/` is
linted as classic browser scripts. `blocks/` is linted as **ES5**, because those
files promise "plain ES5 + window.wp globals": ES2015 syntax there is an error.

Findings that predate the linter are warnings in a marked baseline block:
Prettier formatting in the shipped files, and the undocumented IIFE parameters in
`blocks/*/index.js`. Everything else is an error.
