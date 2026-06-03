/**
 * Franer submission-view bridge (admin only).
 *
 * Runs on the trusted admin "Rendered submission" page (never inside the
 * sandboxed iframe). After the view iframe loads, it posts the decoded
 * submission context to the iframe via postMessage so the template can render
 * it. The payload is read from the localized `FranerSubmissionView` object.
 *
 * Security model:
 * - The iframe is sandboxed without allow-same-origin, so its origin is opaque
 *   ("null") and cannot be named as a targetOrigin; "*" is used to reach our own
 *   already-trusted frame (its contentWindow is resolved from the DOM).
 * - Only the submission JSON travels to the iframe. No REST nonce, no admin URL
 *   and no credentials are ever sent into the frame.
 *
 * Contract (parent -> view iframe):
 *   { type: "franer_view_payload", payload: { submission_id, site, submission } }
 *
 * Framework-free, IIFE, ES5-safe.
 */
( function () {
	'use strict';

	var config = window.FranerSubmissionView || {};

	/**
	 * Post the submission context to the view iframe.
	 *
	 * @param {HTMLIFrameElement} frame The view iframe element.
	 * @return {void}
	 */
	function deliver( frame ) {
		if ( ! frame || ! frame.contentWindow ) {
			return;
		}
		if ( typeof frame.contentWindow.postMessage !== 'function' ) {
			return;
		}
		frame.contentWindow.postMessage(
			{
				type: 'franer_view_payload',
				payload: config.payload || {}
			},
			'*'
		);
	}

	/**
	 * Locate the view iframe and deliver the payload once it has loaded.
	 *
	 * @return {void}
	 */
	function init() {
		var frameId = config.frameId || 'franer-view-frame';
		var frame = document.getElementById( frameId );
		if ( ! frame ) {
			return;
		}

		// Deliver on load. If the iframe has already finished loading by the time
		// this runs, deliver immediately as well so the message is never missed.
		frame.addEventListener( 'load', function () {
			deliver( frame );
		} );

		// A sandboxed srcdoc iframe usually finishes loading very quickly; cover
		// the case where "load" already fired before this listener was attached.
		deliver( frame );
	}

	if ( 'loading' !== document.readyState ) {
		init();
	} else {
		document.addEventListener( 'DOMContentLoaded', init );
	}
} )();
