/**
 * Admin scripts for the Franer plugin.
 *
 * - Initializes the WordPress code editor (CodeMirror) on the HTML textarea.
 * - Copy-to-clipboard for the public URL, shortcode and AI prompt.
 * - JSON detail modal on the Submissions page.
 *
 * All configuration (editor settings, messages) is provided via the localized
 * `FranerAdmin` object. No URLs are hardcoded.
 */
( function () {
	'use strict';

	var settings = window.FranerAdmin || {};
	var messages = settings.messages || {};

	/**
	 * Initialize the CodeMirror-backed code editor for the HTML textarea.
	 */
	function initCodeEditor() {
		if ( ! settings.editorSettings || ! settings.textareaId ) {
			return;
		}
		if ( ! window.wp || ! window.wp.codeEditor ) {
			return;
		}
		var textarea = document.getElementById( settings.textareaId );
		if ( ! textarea ) {
			return;
		}
		window.wp.codeEditor.initialize( settings.textareaId, settings.editorSettings );
	}

	/**
	 * Copy a value to the clipboard, with a graceful fallback.
	 *
	 * @param {string} text The text to copy.
	 * @return {Promise} Resolves when the copy succeeds.
	 */
	function copyText( text ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			return navigator.clipboard.writeText( text );
		}
		return new Promise( function ( resolve, reject ) {
			try {
				var temp = document.createElement( 'textarea' );
				temp.value = text;
				temp.setAttribute( 'readonly', '' );
				temp.style.position = 'absolute';
				temp.style.left = '-9999px';
				document.body.appendChild( temp );
				temp.select();
				document.execCommand( 'copy' );
				document.body.removeChild( temp );
				resolve();
			} catch ( err ) {
				reject( err );
			}
		} );
	}

	/**
	 * Wire copy buttons that reference a target element by id.
	 */
	function initCopyButtons() {
		var buttons = document.querySelectorAll( '[data-franer-copy-target]' );
		Array.prototype.forEach.call( buttons, function ( button ) {
			button.addEventListener( 'click', function () {
				var targetId = button.getAttribute( 'data-franer-copy-target' );
				var target = document.getElementById( targetId );
				if ( ! target ) {
					return;
				}
				var value = ( 'value' in target ) ? target.value : target.textContent;
				copyText( value ).then( function () {
					showCopyFeedback( button, messages.copied || 'Copied.' );
				} ).catch( function () {
					showCopyFeedback( button, messages.copyError || 'Copy failed.' );
				} );
			} );
		} );
	}

	/**
	 * Show transient feedback after a copy action.
	 *
	 * @param {Element} button  The button that was clicked.
	 * @param {string}  message The feedback message.
	 */
	function showCopyFeedback( button, message ) {
		var status = document.getElementById( 'franer-copy-prompt-status' );
		if ( status ) {
			status.textContent = message;
			window.setTimeout( function () {
				status.textContent = '';
			}, 2500 );
			return;
		}
		var original = button.textContent;
		button.textContent = message;
		window.setTimeout( function () {
			button.textContent = original;
		}, 1500 );
	}

	/**
	 * Wire the JSON detail modal on the Submissions page.
	 */
	function initJsonModal() {
		var modal = document.getElementById( 'franer-json-modal' );
		if ( ! modal ) {
			return;
		}
		var content = document.getElementById( 'franer-modal-content' );
		var editId = document.getElementById( 'franer-edit-id' );
		var editNonce = document.getElementById( 'franer-edit-nonce' );
		var lastFocused = null;

		function openModal( payload, id, nonce ) {
			lastFocused = document.activeElement;
			// content is a <textarea>: set its value, not textContent.
			content.value = payload;
			if ( editId ) {
				editId.value = id || '';
			}
			if ( editNonce ) {
				editNonce.value = nonce || '';
			}
			modal.hidden = false;
			content.focus();
		}

		function closeModal() {
			modal.hidden = true;
			content.value = '';
			if ( lastFocused && typeof lastFocused.focus === 'function' ) {
				lastFocused.focus();
			}
		}

		var viewButtons = document.querySelectorAll( '.franer-view-json' );
		Array.prototype.forEach.call( viewButtons, function ( button ) {
			button.addEventListener( 'click', function () {
				// The payload lives in a data attribute: getAttribute decodes
				// HTML entities back to a real JSON string (script tags do not).
				var payload = button.getAttribute( 'data-franer-payload' ) || '';
				openModal(
					payload.trim(),
					button.getAttribute( 'data-franer-id' ),
					button.getAttribute( 'data-franer-nonce' )
				);
			} );
		} );

		var closers = modal.querySelectorAll( '[data-franer-modal-close]' );
		Array.prototype.forEach.call( closers, function ( el ) {
			el.addEventListener( 'click', closeModal );
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && ! modal.hidden ) {
				closeModal();
			}
		} );
	}

	function ready( fn ) {
		if ( 'loading' !== document.readyState ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	ready( function () {
		initCodeEditor();
		initCopyButtons();
		initJsonModal();
	} );
} )();
