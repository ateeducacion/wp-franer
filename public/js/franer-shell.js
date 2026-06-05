/**
 * Franer parent shell.
 *
 * Runs in the PARENT page (never inside the sandboxed iframe). It listens for
 * "franer_submit" postMessage events sent by an activity iframe, performs the
 * nonced REST call to store the submission, and posts a "franer_submit_result"
 * message back to the originating iframe.
 *
 * It also owns the host submission states: on a successful submit it reveals a
 * "form submitted" confirmation panel (hiding the activity iframe), and wires the
 * "Edit responses" / "Submit another response" buttons rendered by the public
 * partial. Editing reopens the form and re-posts the prior answers to the iframe
 * as a "franer_prefill" message so activities that implement window.FranerPrefill
 * can repopulate their fields.
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
 *   parent -> iframe prefill: { type:"franer_prefill", payload:{ ...answers } }
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
	 * Find the activity iframe element whose contentWindow is the given source.
	 *
	 * @param {Window} source The event.source to match.
	 * @return {HTMLIFrameElement|null} The matching iframe, or null.
	 */
	function frameElementForSource( source ) {
		if ( ! source || ! document.querySelectorAll ) {
			return null;
		}
		var frames = document.querySelectorAll( 'iframe.franer-shell__frame' );
		for ( var i = 0; i < frames.length; i++ ) {
			if ( frames[ i ].contentWindow === source ) {
				return frames[ i ];
			}
		}
		return null;
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
		return !! frameElementForSource( source );
	}

	/**
	 * Resolve the .franer-shell container for an iframe element.
	 *
	 * @param {HTMLIFrameElement|null} frameEl The activity iframe.
	 * @return {HTMLElement|null} The shell container, or null.
	 */
	function shellForFrame( frameEl ) {
		if ( ! frameEl || typeof frameEl.closest !== 'function' ) {
			return null;
		}
		return frameEl.closest( '.franer-shell' );
	}

	/**
	 * Switch a shell instance between its panels.
	 *
	 * Toggles the activity iframe ("form"), the host confirmation page
	 * ("confirm") and the stale status region. Closed / not-yet / already states
	 * are server-rendered only and never set here. No-ops when shellEl is null
	 * (e.g. an embedding without the host panels).
	 *
	 * @param {HTMLElement|null} shellEl The .franer-shell container.
	 * @param {string}           mode    'form' or 'confirm'.
	 * @return {void}
	 */
	function setMode( shellEl, mode ) {
		if ( ! shellEl ) {
			return;
		}
		var frameWrap = shellEl.querySelector( '.franer-shell__frame-wrap' );
		var confirmPanel = shellEl.querySelector( '.franer-shell__panel--confirm' );
		var status = shellEl.querySelector( '.franer-shell__status' );

		if ( frameWrap ) {
			frameWrap.hidden = ( 'form' !== mode );
		}
		if ( confirmPanel ) {
			confirmPanel.hidden = ( 'confirm' !== mode );
		}
		// Drop any stale per-submission status when leaving the form.
		if ( status && 'form' !== mode ) {
			status.hidden = true;
		}
		shellEl.setAttribute( 'data-franer-mode', mode );
	}

	/**
	 * Post a prefill message with the prior answers to an activity iframe.
	 *
	 * @param {Window} target The iframe contentWindow.
	 * @param {Object} data   The previously submitted answers object.
	 * @return {void}
	 */
	function postPrefill( target, data ) {
		if ( ! target || typeof target.postMessage !== 'function' || ! data ) {
			return;
		}
		target.postMessage( { type: 'franer_prefill', payload: data }, '*' );
	}

	/**
	 * Send prefill answers to an iframe, covering both the already-loaded and the
	 * not-yet-loaded (lazy / previously hidden) cases.
	 *
	 * @param {HTMLIFrameElement} frameEl The activity iframe.
	 * @param {Object}            data    The previously submitted answers object.
	 * @return {void}
	 */
	function sendPrefillWhenReady( frameEl, data ) {
		if ( ! frameEl || ! data ) {
			return;
		}
		// If the frame is already loaded this lands immediately; otherwise it is
		// ignored and the load handler below delivers it once the activity is ready.
		postPrefill( frameEl.contentWindow, data );

		if ( typeof frameEl.addEventListener !== 'function' ) {
			return;
		}
		var onLoad = function () {
			frameEl.removeEventListener( 'load', onLoad );
			postPrefill( frameEl.contentWindow, data );
		};
		frameEl.addEventListener( 'load', onLoad );
	}

	/**
	 * Reload an activity iframe to a fresh state (for "Submit another response").
	 *
	 * Re-assigning the same srcdoc forces the browser to reparse the document.
	 *
	 * @param {HTMLIFrameElement} frameEl The activity iframe.
	 * @return {void}
	 */
	function resetFrame( frameEl ) {
		if ( ! frameEl || typeof frameEl.getAttribute !== 'function' ) {
			return;
		}
		var doc = frameEl.getAttribute( 'srcdoc' );
		if ( null !== doc ) {
			frameEl.setAttribute( 'srcdoc', doc );
		}
	}

	/**
	 * Fetch the current user's stored answers, then prefill the iframe with them.
	 *
	 * Uses the answers cached from the just-completed submit when available;
	 * otherwise reads the my-submission endpoint (re-entry after a page reload).
	 *
	 * @param {HTMLElement}       shellEl The shell container (carries the cache).
	 * @param {HTMLIFrameElement} frameEl The activity iframe.
	 * @return {void}
	 */
	function prefillFromStore( shellEl, frameEl ) {
		if ( ! frameEl ) {
			return;
		}
		var cached = shellEl ? shellEl.franerLastData : null;
		if ( cached ) {
			sendPrefillWhenReady( frameEl, cached );
			return;
		}
		if ( ! config.myUrl || typeof window.fetch !== 'function' ) {
			return;
		}
		window.fetch( config.myUrl, {
			method: 'GET',
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': config.nonce || ''
			}
		} ).then( function ( response ) {
			return response.ok ? response.json() : null;
		} ).then( function ( json ) {
			// The endpoint returns the stored answers object directly as "payload".
			if ( json && json.payload && typeof json.payload === 'object' ) {
				sendPrefillWhenReady( frameEl, json.payload );
			}
		} ).catch( function () {} );
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

				// Reveal the host "form submitted" confirmation page and cache the
				// answers so an immediate "Edit responses" can prefill instantly.
				var frameEl = frameElementForSource( source );
				var shellEl = shellForFrame( frameEl );
				if ( shellEl ) {
					shellEl.franerLastData = ( payload && payload.data ) ? payload.data : null;
					setMode( shellEl, 'confirm' );
				}
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
	 * Handle clicks on the host confirmation-page buttons ("Edit responses" /
	 * "Submit another response"), delegated from the document.
	 *
	 * @param {MouseEvent} event The click event.
	 * @return {void}
	 */
	function onClick( event ) {
		var node = event.target;
		while ( node && node.getAttribute && ! node.getAttribute( 'data-franer-action' ) ) {
			node = node.parentNode;
		}
		if ( ! node || ! node.getAttribute ) {
			return;
		}
		var action = node.getAttribute( 'data-franer-action' );
		if ( 'edit' !== action && 'again' !== action ) {
			return;
		}
		var shellEl = ( typeof node.closest === 'function' ) ? node.closest( '.franer-shell' ) : null;
		if ( ! shellEl ) {
			return;
		}
		var frameEl = shellEl.querySelector( 'iframe.franer-shell__frame' );

		if ( 'again' === action ) {
			shellEl.franerLastData = null;
			resetFrame( frameEl );
			setMode( shellEl, 'form' );
			return;
		}

		// action === 'edit'
		setMode( shellEl, 'form' );
		prefillFromStore( shellEl, frameEl );
	}

	if ( window.addEventListener ) {
		window.addEventListener( 'message', onMessage, false );
		if ( document.addEventListener ) {
			document.addEventListener( 'click', onClick, false );
		}
	}
})();
