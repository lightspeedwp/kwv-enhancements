/**
 * Shared helpers for loading a shipped script into jsdom.
 *
 * The theme's scripts are side-effect IIFEs: they run once, on load, against
 * whatever DOM and globals exist at that moment. So a test:
 *
 *   1. renders a fixture (real markup captured from the site) into the body,
 *   2. stubs the globals the script expects (vi.stubGlobal),
 *   3. calls loadScript(), which clears the module registry and imports the
 *      file again, so it runs fresh and coverage maps to the real file.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { vi } from 'vitest';

// npm scripts and the CI workflow run Vitest from the repo root.
const ROOT = resolve( process.cwd() ) + '/';

/**
 * Read a fixture from tests/js/fixtures, without its provenance comment.
 *
 * @param {string} name File name, e.g. 'aws-search.html'.
 * @return {string} The HTML.
 */
export function fixture( name ) {
	return readFileSync(
		`${ ROOT }tests/js/fixtures/${ name }`,
		'utf8'
	).replace( /^<!--[\s\S]*?-->\s*/, '' );
}

/**
 * Put markup into the document body.
 *
 * @param {string} html Markup.
 * @return {HTMLElement} document.body.
 */
export function render( html ) {
	document.body.innerHTML = html;
	return document.body;
}

/**
 * Make document.readyState report 'loading' until DOMContentLoaded is fired,
 * so a script takes its deferred-init branch.
 *
 * @return {() => void} Call to fire DOMContentLoaded (and restore readyState).
 */
export function deferReady() {
	Object.defineProperty( document, 'readyState', {
		configurable: true,
		get: () => 'loading',
	} );
	return () => {
		delete document.readyState;
		document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
	};
}

/**
 * Run a shipped script from a fresh module registry.
 *
 * @param {string} path Path from the repo root, e.g. 'assets/js/aws-search.js'.
 * @return {Promise<Object>} The module namespace (empty for IIFEs).
 */
export async function loadScript( path ) {
	vi.resetModules();
	return import( /* @vite-ignore */ `${ ROOT }${ path }` );
}

/**
 * A controllable matchMedia stub.
 *
 * @param {boolean} matches Initial state.
 * @param {Object}  options { legacy: true } to expose only addListener.
 * @return {Object} { matchMedia, set( matches ), list }.
 */
export function mediaQuery( matches = false, options = {} ) {
	const listeners = [];
	const list = {
		matches,
		media: '',
	};
	if ( options.legacy ) {
		list.addListener = ( fn ) => listeners.push( fn );
	} else {
		list.addEventListener = ( type, fn ) => listeners.push( fn );
	}
	return {
		list,
		matchMedia: vi.fn( () => list ),
		set( value ) {
			list.matches = value;
			listeners.forEach( ( fn ) => fn( { matches: value } ) );
		},
	};
}

/**
 * Wait for queued MutationObserver callbacks.
 *
 * @return {Promise<void>}
 */
export function flushObservers() {
	return new Promise( ( done ) => setTimeout( done, 0 ) );
}
