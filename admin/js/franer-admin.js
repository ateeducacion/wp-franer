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

	// CodeMirror instances keyed by textarea id, so editors inside an initially
	// hidden tab panel can be refreshed when their panel becomes visible.
	var editors = {};

	/**
	 * Initialize the CodeMirror-backed code editor on every HTML textarea.
	 *
	 * Targets all `textarea.franer-code-editor` elements that carry an id, so the
	 * activity HTML and the submission-view HTML editors are both initialized.
	 */
	function initCodeEditor() {
		if ( ! settings.editorSettings ) {
			return;
		}
		if ( ! window.wp || ! window.wp.codeEditor ) {
			return;
		}
		var areas = document.querySelectorAll( 'textarea.franer-code-editor' );
		Array.prototype.forEach.call( areas, function ( area ) {
			if ( ! area.id ) {
				return;
			}
			var instance = window.wp.codeEditor.initialize( area.id, settings.editorSettings );
			if ( instance && instance.codemirror ) {
				editors[ area.id ] = instance.codemirror;
			}
		} );
	}

	/**
	 * Refresh any CodeMirror editor contained in the given element.
	 *
	 * CodeMirror mis-measures its gutters when initialized inside a hidden
	 * container, so it must be refreshed once the container becomes visible.
	 *
	 * @param {Element} container The newly shown element.
	 */
	function refreshEditorsIn( container ) {
		Object.keys( editors ).forEach( function ( id ) {
			var area = document.getElementById( id );
			if ( area && container.contains( area ) ) {
				editors[ id ].refresh();
			}
		} );
	}

	/**
	 * Wire accessible tabbed editors (`[data-franer-tabs]`).
	 *
	 * Activating a tab shows its panel, hides the others, and updates the ARIA
	 * state. Left/Right arrow keys move between tabs (WAI-ARIA tabs pattern).
	 */
	function initTabs() {
		var groups = document.querySelectorAll( '[data-franer-tabs]' );
		Array.prototype.forEach.call( groups, function ( group ) {
			var tabs = Array.prototype.slice.call(
				group.querySelectorAll( '[role="tab"]' )
			);

			function activate( tab, focus ) {
				tabs.forEach( function ( other ) {
					var selected = other === tab;
					other.setAttribute( 'aria-selected', selected ? 'true' : 'false' );
					other.tabIndex = selected ? 0 : -1;
					var panel = document.getElementById(
						other.getAttribute( 'aria-controls' )
					);
					if ( panel ) {
						panel.hidden = ! selected;
						if ( selected ) {
							refreshEditorsIn( panel );
						}
					}
				} );
				if ( focus ) {
					tab.focus();
				}
			}

			tabs.forEach( function ( tab, index ) {
				tab.addEventListener( 'click', function () {
					activate( tab, false );
				} );
				tab.addEventListener( 'keydown', function ( event ) {
					var next = null;
					if ( 'ArrowRight' === event.key ) {
						next = tabs[ ( index + 1 ) % tabs.length ];
					} else if ( 'ArrowLeft' === event.key ) {
						next = tabs[ ( index - 1 + tabs.length ) % tabs.length ];
					}
					if ( next ) {
						event.preventDefault();
						activate( next, true );
					}
				} );
			} );
		} );
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
		var statusId = button.getAttribute( 'data-franer-copy-status' ) || 'franer-copy-prompt-status';
		var status = document.getElementById( statusId );
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
		initTabs();
		initCodeEditor();
		initCopyButtons();
		initJsonModal();
	} );
} )();
