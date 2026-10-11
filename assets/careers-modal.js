/**
 * KWV Careers — job modal on the jobs board.
 *
 * Progressive enhancement over ordinary links: a job's "Read More" / title link
 * (tagged `data-kwv-career-open="<job id>"` by the plugin) opens the shared
 * <dialog> filled from that job's <template> instead of navigating. Without this
 * script, without <dialog> support, or on a modified click (new tab/window), the
 * link simply goes to the single-job page, which has the same content and form.
 *
 * The dialog holds one copy of each application form in use. Opening a job shows
 * that job's form and sets its hidden Job ID input; the server fills in the rest
 * of the job's details at submission. The form submits over AJAX, so Gravity
 * Forms swaps in its confirmation inside the modal. After that, other jobs' links
 * are left to navigate to their own page (with a fresh form) rather than reuse a
 * form that now shows "thank you".
 */
( function () {
	'use strict';

	var dialog = document.getElementById( 'kwv-career-dialog' );

	if ( ! dialog || 'function' !== typeof dialog.showModal ) {
		return;
	}

	var jobSlot = dialog.querySelector( '[data-kwv-career-slot="job"]' );
	var easyApplySlot = dialog.querySelector( '[data-kwv-career-slot="easy-apply"]' );
	var closedNotice = dialog.querySelector( '[data-kwv-career-closed]' );
	var forms = dialog.querySelectorAll( '[data-kwv-career-form]' );
	var panel = dialog.querySelector( '.kwv-career-modal__inner' );
	var trigger = null;
	var currentJob = null;

	/**
	 * Whether a form in the dialog has been submitted (its confirmation is showing).
	 *
	 * @return {boolean}
	 */
	function hasSubmitted() {
		return !! dialog.querySelector( '.gform_confirmation_wrapper, .gform_confirmation_message' );
	}

	/**
	 * Move a template part's children into a slot, replacing what was there.
	 *
	 * @param {Element}          slot     Destination.
	 * @param {DocumentFragment} fragment Cloned template content.
	 * @param {string}           part     data-kwv-part name.
	 */
	function fill( slot, fragment, part ) {
		var source = fragment.querySelector( '[data-kwv-part="' + part + '"]' );

		slot.replaceChildren.apply( slot, source ? Array.prototype.slice.call( source.childNodes ) : [] );
	}

	/**
	 * Fill the dialog with a job and open it.
	 *
	 * @param {string}  jobId  Job post ID.
	 * @param {Element} source The link that opened it (focus returns here).
	 * @return {boolean} Whether the modal opened.
	 */
	function open( jobId, source ) {
		var template = document.getElementById( 'kwv-career-tpl-' + jobId );

		if ( ! template ) {
			return false;
		}

		var fragment = template.content.cloneNode( true );
		var closed = '1' === template.dataset.kwvClosed;

		fill( jobSlot, fragment, 'job' );
		fill( easyApplySlot, fragment, 'easy-apply' );
		closedNotice.hidden = ! closed;

		Array.prototype.forEach.call( forms, function ( wrapper ) {
			var active = ! closed && wrapper.dataset.kwvCareerForm === template.dataset.kwvForm;
			var input = wrapper.dataset.kwvJobInput
				? wrapper.querySelector( 'input[name="' + wrapper.dataset.kwvJobInput + '"]' )
				: null;

			wrapper.hidden = ! active;

			if ( active && input ) {
				input.value = jobId;
			}
		} );

		trigger = source;
		currentJob = jobId;

		dialog.showModal();
		panel.scrollTop = 0;

		return true;
	}

	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest( '[data-kwv-career-open]' );

		if ( ! link || event.defaultPrevented || 0 !== event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
			return;
		}

		var jobId = link.getAttribute( 'data-kwv-career-open' );

		// Once an application has gone through, a different job gets its own page
		// (and a fresh form); the same job reopens on its confirmation.
		if ( hasSubmitted() && jobId !== currentJob ) {
			return;
		}

		if ( open( jobId, link ) ) {
			event.preventDefault();
		}
	} );

	dialog.addEventListener( 'click', function ( event ) {
		// The dialog has no padding and doesn't scroll (the inner panel does), so
		// a click on the element itself is a click on the backdrop.
		if ( event.target === dialog || event.target.closest( '[data-kwv-career-close]' ) ) {
			dialog.close();
		}
	} );

	dialog.addEventListener( 'close', function () {
		if ( trigger && document.contains( trigger ) ) {
			trigger.focus();
		}
	} );
}() );
