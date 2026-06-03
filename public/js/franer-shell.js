/**
 * Franer parent shell.
 *
 * Runs in the PARENT page (never inside the sandboxed iframe). It listens for
 * "franer_submit" postMessage events sent by an activity iframe, performs the
 * nonced REST call to store the submission, and posts a "franer_submit_result"
 * message back to the originating iframe.
 *
 * Security model:
 * - The iframe has no same-origin access, so the trusted REST call must be
 *   made here, by the parent, with credentials and the WP REST nonce.
 * - Inbound messages are validated defensively; anything that does not match
 *   the expected shape is ignored.
 *
 * Contract:
 *   iframe -> parent: { type:"franer_submit",
 *                       payload:{ schema_version, activity_id, data:{...} } }
 *   parent -> iframe success: { type:"franer_submit_result", ok:true,
 *                               result:{ submission_id, status } }
 *   parent -> iframe error:   { type:"franer_submit_result", ok:false,
 *                               result:{ code, message } }
 *
 * Framework-free, IIFE, ES5-safe.
 */
(function () {
	'use strict';

	var config = window.FranerShell || {};
	var messages = config.messages || {};

	/**
	 * Build the result error message for a given code, falling back to the
	 * generic localized error string.
	 *
	 * @param {string} code    Machine-readable error code.
	 * @param {string} message Optional server-provided message.
	 * @return {string} A human-readable message.
	 */
	function resolveMessage( code, message ) {
		if ( 'network' === code && messages.network ) {
			return messages.network;
		}
		if ( message && typeof message === 'string' ) {
			return message;
		}
		return messages.error || 'Error';
	}

	/**
	 * Send a result back to the iframe that originated the request.
	 *
	 * The target is always one of our own validated activity iframes (see
	 * onMessage), so it is safe to reply. The sandbox has no allow-same-origin,
	 * giving the frame an opaque ("null") origin that cannot be named as a
	 * targetOrigin, so "*" is used to reach our own already-trusted frame.
	 *
	 * @param {Window}  target Source contentWindow of the submitting iframe.
	 * @param {boolean} ok     Whether the submission succeeded.
	 * @param {Object}  result Result payload (submission_id/status or code/message).
	 */
	function postResult( target, ok, result ) {
		if ( ! target || typeof target.postMessage !== 'function' ) {
			return;
		}
		target.postMessage(
			{
				type: 'franer_submit_result',
				ok: !! ok,
				result: result
			},
			'*'
		);
	}

	/**
	 * Whether a message source is one of this page's Franer activity iframes.
	 *
	 * The activity iframe is sandboxed without allow-same-origin, so its origin is
	 * opaque ("null") and event.origin cannot be used to authenticate it. Instead
	 * we pin on window identity: the source must be the contentWindow of an
	 * <iframe class="franer-shell__frame"> currently in the document. This stops
	 * any other window/frame from spoofing a franer_submit message and having the
	 * parent perform a credentialed, nonced REST write on the victim's behalf.
	 *
	 * @param {Window} source The event.source to verify.
	 * @return {boolean} True when source is a known Franer activity iframe.
	 */
	function isFranerFrame( source ) {
		if ( ! source || ! document.querySelectorAll ) {
			return false;
		}
		var frames = document.querySelectorAll( 'iframe.franer-shell__frame' );
		for ( var i = 0; i < frames.length; i++ ) {
			if ( frames[ i ].contentWindow === source ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Validate that an incoming message matches the franer_submit contract.
	 *
	 * @param {*} data The event.data value.
	 * @return {boolean} True if the message is a well-formed submit request.
	 */
	function isSubmitMessage( data ) {
		return (
			typeof data === 'object' &&
			data &&
			data.type === 'franer_submit' &&
			typeof data.payload === 'object' &&
			data.payload
		);
	}

	/**
	 * Handle a validated submit request: POST to the REST endpoint and relay
	 * the outcome back to the source iframe.
	 *
	 * @param {Window} source  The originating iframe contentWindow.
	 * @param {Object} payload The submission payload object.
	 */
	function handleSubmit( source, payload ) {
		if ( ! config.restUrl ) {
			postResult( source, false, {
				code: 'franer_no_config',
				message: resolveMessage( 'error' )
			} );
			return;
		}

		var body;
		try {
			body = JSON.stringify( payload );
		} catch ( e ) {
			postResult( source, false, {
				code: 'franer_invalid_payload',
				message: resolveMessage( 'error' )
			} );
			return;
		}

		fetch( config.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce || ''
			},
			body: body
		} ).then( function ( response ) {
			return response.json().then(
				function ( json ) {
					return { ok: response.ok, status: response.status, json: json };
				},
				function () {
					// Non-JSON or empty body.
					return { ok: response.ok, status: response.status, json: null };
				}
			);
		} ).then( function ( res ) {
			if ( res.ok && res.json && typeof res.json === 'object' ) {
				postResult( source, true, {
					submission_id: res.json.submission_id,
					status: res.json.status
				} );
				return;
			}

			var code = ( res.json && res.json.code ) ? res.json.code : ( 'franer_http_' + res.status );
			var message = ( res.json && res.json.message ) ? res.json.message : resolveMessage( code );
			postResult( source, false, {
				code: code,
				message: message
			} );
		} ).catch( function () {
			postResult( source, false, {
				code: 'network',
				message: resolveMessage( 'network' )
			} );
		} );
	}

	/**
	 * Top-level message listener. Ignores anything that is not a valid
	 * franer_submit message.
	 *
	 * @param {MessageEvent} event The inbound message event.
	 */
	function onMessage( event ) {
		if ( ! isSubmitMessage( event.data ) ) {
			return;
		}
		// Only accept submissions from our own sandboxed activity iframe. Any other
		// window or frame spoofing a franer_submit message is ignored, so it cannot
		// trigger a credentialed REST write on the current user's behalf.
		if ( ! isFranerFrame( event.source ) ) {
			return;
		}
		handleSubmit( event.source, event.data.payload );
	}

	if ( window.addEventListener ) {
		window.addEventListener( 'message', onMessage, false );
	}
})();
