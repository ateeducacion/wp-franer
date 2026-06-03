/**
 * Franer fullscreen toggle.
 *
 * Runs in the PARENT page (never inside the sandboxed iframe). Toggles true OS
 * fullscreen on the iframe wrapper using the Fullscreen API, and keeps the
 * toggle button label / pressed state in sync with the actual fullscreen state.
 *
 * Progressive enhancement: the button ships hidden and is only revealed when
 * the Fullscreen API is available, so without JS (or on unsupported browsers)
 * no dead control is shown.
 *
 * Framework-free, IIFE, ES5-safe.
 */
(function () {
	'use strict';

	var config = window.FranerShell || {};
	var messages = config.messages || {};
	var labelEnter = messages.fullscreen || 'Fullscreen';
	var labelExit = messages.exitFullscreen || 'Exit fullscreen';

	/**
	 * The element currently in fullscreen, with a webkit fallback for older Safari.
	 *
	 * @return {Element|null} The fullscreen element, or null when not active.
	 */
	function getFullscreenElement() {
		return document.fullscreenElement || document.webkitFullscreenElement || null;
	}

	/**
	 * Request fullscreen on an element, preferring the standard API.
	 *
	 * @param {Element} el The element to expand.
	 * @return {Promise|undefined} The standard API promise when available.
	 */
	function requestFullscreen( el ) {
		if ( el.requestFullscreen ) {
			return el.requestFullscreen();
		}
		if ( el.webkitRequestFullscreen ) {
			el.webkitRequestFullscreen();
		}
	}

	/**
	 * Exit fullscreen, preferring the standard API.
	 *
	 * @return {Promise|undefined} The standard API promise when available.
	 */
	function exitFullscreen() {
		if ( document.exitFullscreen ) {
			return document.exitFullscreen();
		}
		if ( document.webkitExitFullscreen ) {
			document.webkitExitFullscreen();
		}
	}

	/**
	 * Wire up the toggle button for a single Franer shell.
	 */
	function init() {
		var shell = document.querySelector( '.franer-shell' );
		if ( ! shell ) {
			return;
		}

		var target = shell.querySelector( '.franer-shell__frame-wrap' );
		var btn = shell.querySelector( '.franer-shell__fullscreen' );
		if ( ! target || ! btn ) {
			return;
		}

		// Hide the control where the Fullscreen API is unavailable.
		if ( ! ( target.requestFullscreen || target.webkitRequestFullscreen ) ) {
			return;
		}
		btn.hidden = false;

		var textEl = btn.querySelector( '.franer-shell__fullscreen-text' );

		function sync() {
			var active = getFullscreenElement() === target;
			var label = active ? labelExit : labelEnter;
			btn.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
			btn.setAttribute( 'aria-label', label );
			btn.title = label;
			if ( textEl ) {
				textEl.textContent = label;
			}
		}

		btn.addEventListener( 'click', function () {
			var result = getFullscreenElement() === target ? exitFullscreen() : requestFullscreen( target );
			// Swallow rejections (user gesture denied / not allowed); UI stays put.
			if ( result && typeof result.catch === 'function' ) {
				result.catch( function () {} );
			}
		} );

		document.addEventListener( 'fullscreenchange', sync, false );
		document.addEventListener( 'webkitfullscreenchange', sync, false );
		sync();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init, false );
	} else {
		init();
	}
})();
