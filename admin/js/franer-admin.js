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
	 * Whether a dropped file looks like HTML (by MIME type or extension).
	 *
	 * @param {File} file The dropped file.
	 * @return {boolean} True when the file is treated as HTML.
	 */
	function isHtmlFile( file ) {
		if ( ! file ) {
			return false;
		}
		if ( 'text/html' === file.type ) {
			return true;
		}
		var name = ( file.name || '' ).toLowerCase();
		return /\.html?$/.test( name );
	}

	/**
	 * Load the given text into a code editor (textarea + CodeMirror, if present).
	 *
	 * @param {HTMLTextAreaElement} area The target textarea.
	 * @param {string}              text The HTML text to load.
	 */
	function setEditorValue( area, text ) {
		if ( area.id && editors[ area.id ] ) {
			editors[ area.id ].setValue( text );
		} else {
			area.value = text;
			// Notify any listeners (and WordPress) that the value changed.
			var event;
			try {
				event = new Event( 'input', { bubbles: true } );
			} catch ( err ) {
				event = document.createEvent( 'Event' );
				event.initEvent( 'input', true, false );
			}
			area.dispatchEvent( event );
		}
	}

	/**
	 * Current value of a code editor (CodeMirror takes precedence over textarea).
	 *
	 * @param {HTMLTextAreaElement} area The target textarea.
	 * @return {string} The current editor value.
	 */
	function getEditorValue( area ) {
		if ( area.id && editors[ area.id ] ) {
			return editors[ area.id ].getValue();
		}
		return area.value;
	}

	/**
	 * Read a dropped HTML file into the zone's textarea.
	 *
	 * Replaces the current content; when the editor is not empty the user is asked
	 * to confirm first, so pasted work is not lost by accident.
	 *
	 * @param {HTMLTextAreaElement} area The target textarea.
	 * @param {File}                file The dropped file.
	 */
	function loadDroppedFile( area, file ) {
		if ( ! isHtmlFile( file ) ) {
			window.alert( messages.dropInvalidType || 'Please drop an .html file.' );
			return;
		}
		if ( '' !== getEditorValue( area ).trim() ) {
			var confirmMsg = messages.dropConfirm || 'Replace the current content with the dropped file?';
			if ( ! window.confirm( confirmMsg ) ) {
				return;
			}
		}
		var reader = new FileReader();
		reader.onload = function () {
			setEditorValue( area, String( reader.result ) );
		};
		reader.onerror = function () {
			window.alert( messages.dropReadError || 'The file could not be read.' );
		};
		reader.readAsText( file );
	}

	/**
	 * Wire drag-and-drop HTML loading on `[data-franer-drop]` zones.
	 *
	 * Dragging an .html file onto the activity or submission-view editor loads its
	 * contents into the corresponding textarea/CodeMirror instance.
	 */
	function initHtmlDropZones() {
		var zones = document.querySelectorAll( '[data-franer-drop]' );
		Array.prototype.forEach.call( zones, function ( zone ) {
			var area = zone.querySelector( 'textarea' );
			if ( ! area ) {
				return;
			}

			function stop( event ) {
				event.preventDefault();
				event.stopPropagation();
			}

			zone.addEventListener( 'dragenter', function ( event ) {
				stop( event );
				zone.classList.add( 'is-dragover' );
			} );
			zone.addEventListener( 'dragover', function ( event ) {
				stop( event );
				zone.classList.add( 'is-dragover' );
			} );
			zone.addEventListener( 'dragleave', function ( event ) {
				stop( event );
				zone.classList.remove( 'is-dragover' );
			} );
			zone.addEventListener( 'drop', function ( event ) {
				stop( event );
				zone.classList.remove( 'is-dragover' );
				var files = event.dataTransfer && event.dataTransfer.files;
				if ( files && files.length ) {
					loadDroppedFile( area, files[ 0 ] );
				}
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
		initHtmlDropZones();
		initCopyButtons();
		initJsonModal();
	} );
} )();
