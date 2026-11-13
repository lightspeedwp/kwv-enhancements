/**
 * ESLint flat config: the WordPress recommended set (with Prettier, via the
 * WordPress Prettier config) for the plugin's browser scripts and their tests.
 *
 * assets/ and blocks/ ship untranspiled, so they are linted as classic scripts
 * against browser globals plus the `wp` global the editor provides.
 */
import wordpress from '@wordpress/eslint-plugin';
import globals from 'globals';

export default [
	{
		ignores: [ 'node_modules/**', 'coverage/**', 'vendor/**' ],
	},
	...wordpress.configs.recommended,
	{
		files: [ 'assets/**/*.js', 'blocks/**/*.js' ],
		languageOptions: {
			ecmaVersion: 2020,
			sourceType: 'script',
			globals: { ...globals.browser, wp: 'readonly' },
		},
		rules: {
			// Deliberate: the scripts are dependency-free ES5-style browser code.
			'no-var': 'off',
		},
	},
	{
		// "Plain ES5 + window.wp globals" (see each file's header): hold them to it.
		files: [ 'blocks/**/*.js' ],
		languageOptions: { ecmaVersion: 5 },
		rules: {
			'object-shorthand': 'off',
			// Baseline: the IIFE's injected globals are documented in prose.
			'jsdoc/require-param': 'warn',
		},
	},
	{
		// Reviewed baseline: formatting predates the linter (see tests/js/README.md).
		files: [ 'assets/**/*.js', 'blocks/**/*.js' ],
		rules: { 'prettier/prettier': 'warn' },
	},
	{
		files: [ 'tests/js/**/*.js', '*.config.mjs' ],
		languageOptions: {
			globals: { ...globals.node, ...globals.browser },
		},
		rules: {
			'import/no-extraneous-dependencies': 'off',
		},
	},
];
