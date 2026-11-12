/**
 * assets/careers-modal.js — the Careers job modal.
 *
 * jsdom has no <dialog> showModal()/close(), which is handy: tests without the
 * fake below exercise the "no dialog support" fallback, and tests with it get
 * a minimal implementation (open flag + a 'close' event, as browsers fire).
 */
import { describe, it, expect, vi } from 'vitest';
import { screen } from '@testing-library/dom';
import userEvent from '@testing-library/user-event';
import { fixture, loadScript, render } from '../helpers/load.js';

const SCRIPT = 'assets/careers-modal.js';

function fakeDialogSupport() {
	const proto = window.HTMLDialogElement.prototype;
	proto.showModal = vi.fn( function () {
		this.setAttribute( 'open', '' );
	} );
	proto.close = vi.fn( function () {
		this.removeAttribute( 'open' );
		this.dispatchEvent( new Event( 'close' ) );
	} );
	return () => {
		delete proto.showModal;
		delete proto.close;
	};
}

async function setup( html = fixture( 'careers-modal.html' ) ) {
	const restore = fakeDialogSupport();
	render( html );
	await loadScript( SCRIPT );
	const dialog = document.getElementById( 'kwv-career-dialog' );
	const links = Array.from(
		document.querySelectorAll( '[data-kwv-career-open]' )
	);
	return {
		restore,
		dialog,
		links,
		ambassador: links.find( ( a ) => a.dataset.kwvCareerOpen === '183637' ),
		manager: links.find( ( a ) => a.dataset.kwvCareerOpen === '182320' ),
		form: dialog.querySelector( '[data-kwv-career-form="1"]' ),
		jobInput: dialog.querySelector( 'input[name="input_17"]' ),
		closedNotice: dialog.querySelector( '[data-kwv-career-closed]' ),
	};
}

function click( el, init = {} ) {
	const event = new MouseEvent( 'click', {
		bubbles: true,
		cancelable: true,
		button: 0,
		...init,
	} );
	el.dispatchEvent( event );
	return event;
}

describe( 'careers-modal', () => {
	it( 'opens the job in the dialog instead of navigating', async () => {
		const { dialog, ambassador, form, jobInput, closedNotice, restore } =
			await setup();

		const event = click( ambassador );

		expect( event.defaultPrevented ).toBe( true );
		expect( dialog.open ).toBe( true );
		expect(
			dialog.querySelector( '[data-kwv-career-slot="job"] h2' )
				.textContent
		).toMatch( /Brand Ambassador/i );
		expect( form.hidden ).toBe( false );
		expect( jobInput.value ).toBe( '183637' );
		expect( closedNotice.hidden ).toBe( true );
		expect(
			dialog.querySelector( '.kwv-career-modal__inner' ).scrollTop
		).toBe( 0 );
		restore();
	} );

	it( 'replaces the previous job’s content when another job opens', async () => {
		const { dialog, ambassador, manager, jobInput, restore } =
			await setup();

		click( ambassador );
		dialog.close();
		click( manager );

		const titles = dialog.querySelectorAll(
			'[data-kwv-career-slot="job"] h2'
		);
		expect( titles ).toHaveLength( 1 );
		expect( titles[ 0 ].textContent ).toMatch( /Portfolio Brand Manager/i );
		expect( jobInput.value ).toBe( '182320' );
		restore();
	} );

	it( 'fills the Easy Apply slot from the template', async () => {
		const { dialog, ambassador, restore } = await setup();
		const tpl = document.getElementById( 'kwv-career-tpl-183637' );
		const expected = tpl.content.querySelector(
			'[data-kwv-part="easy-apply"]'
		).childNodes.length;

		click( ambassador );

		expect(
			dialog.querySelector( '[data-kwv-career-slot="easy-apply"]' )
				.childNodes
		).toHaveLength( expected );
		restore();
	} );

	it( 'shows the closed notice and hides every form for a closed job', async () => {
		const { dialog, ambassador, form, closedNotice, restore } =
			await setup();
		document.getElementById( 'kwv-career-tpl-183637' ).dataset.kwvClosed =
			'1';

		click( ambassador );

		expect( dialog.open ).toBe( true );
		expect( closedNotice.hidden ).toBe( false );
		expect( form.hidden ).toBe( true );
		restore();
	} );

	it( 'hides forms that belong to a different job type', async () => {
		const { ambassador, form, restore } = await setup();
		document.getElementById( 'kwv-career-tpl-183637' ).dataset.kwvForm =
			'2';

		click( ambassador );

		expect( form.hidden ).toBe( true );
		restore();
	} );

	it( 'shows a form with no Job ID field without touching inputs', async () => {
		const { ambassador, form, jobInput, restore } = await setup();
		delete form.dataset.kwvJobInput;
		const before = jobInput.value;

		click( ambassador );

		expect( form.hidden ).toBe( false );
		expect( jobInput.value ).toBe( before );
		restore();
	} );

	it( 'empties a slot when the template has no matching part', async () => {
		const { dialog, ambassador, restore } = await setup();
		const tpl = document.getElementById( 'kwv-career-tpl-183637' );
		tpl.content.querySelector( '[data-kwv-part="easy-apply"]' ).remove();
		dialog.querySelector(
			'[data-kwv-career-slot="easy-apply"]'
		).innerHTML = '<p>stale</p>';

		click( ambassador );

		expect(
			dialog.querySelector( '[data-kwv-career-slot="easy-apply"]' )
				.childNodes
		).toHaveLength( 0 );
		restore();
	} );

	it( 'lets the link navigate when its job has no template', async () => {
		const { dialog, ambassador, restore } = await setup();
		document.getElementById( 'kwv-career-tpl-183637' ).remove();

		const event = click( ambassador );

		expect( event.defaultPrevented ).toBe( false );
		expect( dialog.open ).toBe( false );
		restore();
	} );

	it.each( [
		[ 'Cmd', { metaKey: true } ],
		[ 'Ctrl', { ctrlKey: true } ],
		[ 'Shift', { shiftKey: true } ],
		[ 'Alt', { altKey: true } ],
		[ 'middle button', { button: 1 } ],
	] )(
		'leaves a %s-click to the browser (new tab/window)',
		async ( _, init ) => {
			const { dialog, ambassador, restore } = await setup();

			const event = click( ambassador, init );

			expect( event.defaultPrevented ).toBe( false );
			expect( dialog.open ).toBe( false );
			restore();
		}
	);

	it( 'respects a click another handler already cancelled', async () => {
		const { dialog, ambassador, restore } = await setup();
		ambassador.addEventListener( 'click', ( e ) => e.preventDefault() );

		click( ambassador );

		expect( dialog.open ).toBe( false );
		restore();
	} );

	it( 'ignores clicks that are not on a job link', async () => {
		const { dialog, restore } = await setup();

		click( document.body );

		expect( dialog.open ).toBe( false );
		restore();
	} );

	it( 'closes from the close button and returns focus to the link that opened it', async () => {
		const user = userEvent.setup();
		const { dialog, ambassador, restore } = await setup();

		click( ambassador );
		await user.click(
			screen.getByRole( 'button', { name: 'Close', hidden: true } )
		);

		expect( dialog.open ).toBe( false );
		expect( document.activeElement ).toBe( ambassador );
		restore();
	} );

	it( 'closes on a backdrop click (a click on the dialog element itself)', async () => {
		const { dialog, ambassador, restore } = await setup();
		click( ambassador );

		click( dialog );

		expect( dialog.open ).toBe( false );
		restore();
	} );

	it( 'stays open for clicks inside the panel', async () => {
		const { dialog, ambassador, restore } = await setup();
		click( ambassador );

		click( dialog.querySelector( '.kwv-career-modal__inner h2' ) );

		expect( dialog.open ).toBe( true );
		restore();
	} );

	it( 'does not return focus to a link that has left the page', async () => {
		const { dialog, ambassador, restore } = await setup();
		click( ambassador );
		ambassador.remove();

		expect( () => dialog.close() ).not.toThrow();
		expect( document.activeElement ).toBe( document.body );
		restore();
	} );

	it( 'does not move focus on a close it did not open', async () => {
		const { dialog, restore } = await setup();

		dialog.showModal();
		dialog.close();

		expect( document.activeElement ).toBe( document.body );
		restore();
	} );

	it( 'after an application is submitted, other jobs navigate to their own page', async () => {
		const { dialog, ambassador, manager, restore } = await setup();
		click( ambassador );
		dialog.querySelector( '[data-kwv-career-form="1"]' ).innerHTML =
			'<div class="gform_confirmation_wrapper">Thank you</div>';
		dialog.close();

		const other = click( manager );
		expect( other.defaultPrevented ).toBe( false );
		expect( dialog.open ).toBe( false );

		// The same job reopens on its confirmation.
		const same = click( ambassador );
		expect( same.defaultPrevented ).toBe( true );
		expect( dialog.open ).toBe( true );
		restore();
	} );

	it( 'does nothing without <dialog> support (links navigate)', async () => {
		render( fixture( 'careers-modal.html' ) );
		await loadScript( SCRIPT );

		const event = click(
			document.querySelector( '[data-kwv-career-open]' )
		);

		expect( event.defaultPrevented ).toBe( false );
	} );

	it( 'does nothing on pages without the dialog', async () => {
		const restore = fakeDialogSupport();
		render( '<a href="/careers/x/" data-kwv-career-open="1">Job</a>' );
		await loadScript( SCRIPT );

		const event = click( document.querySelector( 'a' ) );

		expect( event.defaultPrevented ).toBe( false );
		restore();
	} );
} );
