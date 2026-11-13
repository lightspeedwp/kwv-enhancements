/**
 * Global test setup.
 *
 * The scripts under test attach listeners to `document` and `window` every time
 * they load. Each test loads its script afresh, so those listeners are tracked
 * here and removed after every test; otherwise a click in test B would also run
 * the handlers registered in test A.
 */
import { afterEach, beforeEach } from 'vitest';

const tracked = [];
const originalAdd = EventTarget.prototype.addEventListener;

beforeEach( () => {
	EventTarget.prototype.addEventListener = function (
		type,
		listener,
		options
	) {
		if ( this === document || this === window ) {
			tracked.push( [ this, type, listener, options ] );
		}
		return originalAdd.call( this, type, listener, options );
	};
} );

afterEach( () => {
	EventTarget.prototype.addEventListener = originalAdd;
	while ( tracked.length ) {
		const [ target, type, listener, options ] = tracked.pop();
		target.removeEventListener( type, listener, options );
	}
	document.head.innerHTML = '';
	document.body.innerHTML = '';
	document.documentElement.className = '';
	delete document.readyState;
} );
