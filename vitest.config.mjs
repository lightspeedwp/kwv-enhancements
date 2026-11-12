/**
 * Vitest config for the plugin's front-end and editor scripts.
 *
 * The scripts in assets/ and blocks/ ship as plain browser files with no build step, so
 * the tests load each file exactly as it ships (see tests/js/helpers/load.js)
 * and V8 coverage maps straight back to it.
 */
import { defineConfig } from 'vitest/config';

export default defineConfig( {
	test: {
		environment: 'jsdom',
		include: [ 'tests/js/**/*.test.js' ],
		setupFiles: [ 'tests/js/setup.js' ],
		restoreMocks: true,
		unstubGlobals: true,
		coverage: {
			provider: 'v8',
			include: [ 'assets/**/*.js', 'blocks/**/*.js' ],
			reporter: [ 'text', 'html', 'lcov', 'json-summary' ],
			reportsDirectory: 'coverage',
			thresholds: {
				statements: 80,
				branches: 80,
				functions: 80,
				lines: 80,
			},
		},
	},
} );
