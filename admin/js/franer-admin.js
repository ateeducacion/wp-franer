/**
 * Admin scripts for the Franer plugin.
 *
 * - Initializes the WordPress code editor (CodeMirror) on the HTML textarea.
 * - Copy-to-clipboard for the public URL, shortcode and AI prompt.
 * - Live public-URL preview as the slug is typed on the editor.
 * - Submission detail drawer (readable view + editable JSON) on the Submissions page.
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
	 * Turn a raw payload key into a readable label (mirrors the PHP helper).
	 *
	 * @param {string} key The raw field key.
	 * @return {string} The humanized label.
	 */
	function humanizeLabel( key ) {
		var raw = String( key );
		var dot = raw.lastIndexOf( '.' );
		if ( dot !== -1 ) {
			raw = raw.slice( dot + 1 );
		}
		var text = raw.replace( /[_-]+/g, ' ' ).replace( /\s+/g, ' ' ).trim();
		if ( ! text ) {
			return '';
		}
		return text.charAt( 0 ).toUpperCase() + text.slice( 1 );
	}

	/**
	 * Flatten nested associative objects to dotted keys (mirrors the PHP helper).
	 *
	 * @param {Object} obj    The object to flatten.
	 * @param {string} prefix The current key prefix (internal).
	 * @param {Object} out    The accumulator (internal).
	 * @return {Object} The flattened map.
	 */
	function flattenPayload( obj, prefix, out ) {
		out = out || {};
		prefix = prefix || '';
		Object.keys( obj ).forEach( function ( key ) {
			var path = prefix ? prefix + '.' + key : key;
			var value = obj[ key ];
			if ( value && 'object' === typeof value && ! Array.isArray( value ) ) {
				flattenPayload( value, path, out );
			} else {
				out[ path ] = value;
			}
		} );
		return out;
	}

	/**
	 * Read the inferred field schema embedded in the page (key => {label,type}).
	 *
	 * @return {Object} The schema map (empty when absent or invalid).
	 */
	function readFieldsSchema() {
		var node = document.getElementById( 'franer-fields-schema' );
		if ( ! node ) {
			return {};
		}
		try {
			return JSON.parse( node.textContent || '{}' ) || {};
		} catch ( err ) {
			return {};
		}
	}

	/**
	 * Build the five-star markup for a 0..5 rating value.
	 *
	 * @param {number} value The rating.
	 * @return {string} The stars HTML.
	 */
	function starsHtml( value ) {
		var out = '<span class="franer-stars">';
		var i;
		for ( i = 1; i <= 5; i++ ) {
			out += '<span class="franer-star' + ( value >= i - 0.25 ? ' is-on' : '' ) + '">★</span>';
		}
		return out + '</span>';
	}

	/**
	 * Escape a string for safe insertion as text inside the drawer.
	 *
	 * @param {string} text The raw text.
	 * @return {string} The escaped text.
	 */
	function escapeHtml( text ) {
		var div = document.createElement( 'div' );
		div.textContent = String( text );
		return div.innerHTML;
	}

	/**
	 * Render the readable (question → answer) view of a submission payload.
	 *
	 * @param {string} payloadJson The pretty JSON string from the row button.
	 * @param {Object} schema      The inferred field schema.
	 * @return {string} The readable HTML.
	 */
	function renderReadable( payloadJson, schema ) {
		var data;
		try {
			data = JSON.parse( payloadJson );
		} catch ( err ) {
			data = null;
		}
		if ( ! data || 'object' !== typeof data ) {
			return '<p class="description">' + escapeHtml( messages.invalidJson || 'Could not read this submission.' ) + '</p>';
		}

		data = flattenPayload( data );
		var rows = '';
		Object.keys( data ).forEach( function ( key ) {
			var field = schema[ key ] || {};
			var label = field.label || humanizeLabel( key );
			var type = field.type || '';
			var value = data[ key ];

			if ( 'text' === type ) {
				var body;
				if ( null === value || '' === String( value ).trim() ) {
					body = '<p class="franer-muted">' + escapeHtml( messages.noComment || '—' ) + '</p>';
				} else {
					body = '<p>“' + escapeHtml( value ) + '”</p>';
				}
				rows += '<div class="franer-qa franer-qa--comment"><span class="franer-qa__q">' + escapeHtml( label ) +
					'</span>' + body + '</div>';
				return;
			}

			var answer;
			if ( 'rating' === type ) {
				answer = starsHtml( Number( value ) ) + ' <i class="franer-muted">' + escapeHtml( value ) + '/5</i>';
			} else if ( null !== value && 'object' === typeof value ) {
				answer = '<code>' + escapeHtml( JSON.stringify( value ) ) + '</code>';
			} else if ( null === value || '' === value ) {
				answer = '—';
			} else {
				answer = escapeHtml( value );
			}
			rows += '<div class="franer-qa"><span class="franer-qa__q">' + escapeHtml( label ) +
				'</span><span class="franer-qa__a">' + answer + '</span></div>';
		} );

		return rows;
	}

	/**
	 * Wire the submission detail drawer on the Submissions page.
	 *
	 * Replaces the old raw-JSON modal: a readable summary tab (question → answer),
	 * an editable JSON tab (reusing the existing update handler) and prev/next
	 * navigation across the listed submissions.
	 */
	function initDetailDrawer() {
		var drawer = document.getElementById( 'franer-json-modal' );
		if ( ! drawer ) {
			return;
		}
		var content = document.getElementById( 'franer-modal-content' );
		var editId = document.getElementById( 'franer-edit-id' );
		var editNonce = document.getElementById( 'franer-edit-nonce' );
		var eyebrow = document.getElementById( 'franer-drawer-eyebrow' );
		var readable = document.getElementById( 'franer-drawer-readable' );
		var schema = readFieldsSchema();
		var lastFocused = null;

		var buttons = Array.prototype.slice.call( document.querySelectorAll( '.franer-view-json' ) );
		var current = -1;

		var panels = drawer.querySelectorAll( '[data-franer-drawer-panel]' );
		var tabs = drawer.querySelectorAll( '[data-franer-drawer-tab]' );

		function showTab( name ) {
			Array.prototype.forEach.call( tabs, function ( tab ) {
				tab.classList.toggle( 'is-on', tab.getAttribute( 'data-franer-drawer-tab' ) === name );
			} );
			Array.prototype.forEach.call( panels, function ( panel ) {
				panel.hidden = panel.getAttribute( 'data-franer-drawer-panel' ) !== name;
			} );
		}

		function openAt( index ) {
			if ( index < 0 || index >= buttons.length ) {
				return;
			}
			current = index;
			var button = buttons[ index ];
			var payload = ( button.getAttribute( 'data-franer-payload' ) || '' ).trim();

			content.value = payload;
			if ( editId ) {
				editId.value = button.getAttribute( 'data-franer-id' ) || '';
			}
			if ( editNonce ) {
				editNonce.value = button.getAttribute( 'data-franer-nonce' ) || '';
			}
			if ( eyebrow ) {
				eyebrow.textContent = '#' + ( button.getAttribute( 'data-franer-id' ) || '' );
			}
			if ( readable ) {
				var outdated = '1' === button.getAttribute( 'data-franer-outdated' )
					? '<div class="franer-drawer__notice">' + escapeHtml( messages.outdated || 'Answered against an earlier version of the form.' ) + '</div>'
					: '';
				readable.innerHTML = outdated + renderReadable( payload, schema );
			}
			showTab( 'summary' );

			if ( drawer.hidden ) {
				lastFocused = document.activeElement;
				drawer.hidden = false;
			}
		}

		function closeDrawer() {
			drawer.hidden = true;
			content.value = '';
			if ( lastFocused && typeof lastFocused.focus === 'function' ) {
				lastFocused.focus();
			}
		}

		buttons.forEach( function ( button, index ) {
			button.addEventListener( 'click', function () {
				openAt( index );
			} );
		} );

		Array.prototype.forEach.call( tabs, function ( tab ) {
			tab.addEventListener( 'click', function () {
				showTab( tab.getAttribute( 'data-franer-drawer-tab' ) );
			} );
		} );

		var prev = drawer.querySelector( '[data-franer-drawer-prev]' );
		var next = drawer.querySelector( '[data-franer-drawer-next]' );
		if ( prev ) {
			prev.addEventListener( 'click', function () {
				openAt( current - 1 );
			} );
		}
		if ( next ) {
			next.addEventListener( 'click', function () {
				openAt( current + 1 );
			} );
		}

		var closers = drawer.querySelectorAll( '[data-franer-modal-close], .franer-modal__close' );
		Array.prototype.forEach.call( closers, function ( el ) {
			el.addEventListener( 'click', closeDrawer );
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( drawer.hidden ) {
				return;
			}
			if ( 'Escape' === event.key ) {
				closeDrawer();
			} else if ( 'ArrowLeft' === event.key ) {
				openAt( current - 1 );
			} else if ( 'ArrowRight' === event.key ) {
				openAt( current + 1 );
			}
		} );
	}

	/**
	 * Wire the per-row delete buttons to the standalone delete form.
	 *
	 * The form lives outside the list's own GET form (nested forms are invalid),
	 * so each trash button fills its hidden fields and submits it after confirming.
	 */
	function initDeleteButtons() {
		var form = document.getElementById( 'franer-delete-form' );
		if ( ! form ) {
			return;
		}
		var idField = document.getElementById( 'franer-delete-id' );
		var nonceField = document.getElementById( 'franer-delete-nonce' );

		var buttons = document.querySelectorAll( '.franer-delete-btn' );
		Array.prototype.forEach.call( buttons, function ( button ) {
			button.addEventListener( 'click', function () {
				var message = messages.deleteConfirm || 'Delete this submission? This cannot be undone.';
				if ( ! window.confirm( message ) ) {
					return;
				}
				if ( idField ) {
					idField.value = button.getAttribute( 'data-franer-id' ) || '';
				}
				if ( nonceField ) {
					nonceField.value = button.getAttribute( 'data-franer-delete-nonce' ) || '';
				}
				form.submit();
			} );
		} );
	}

	/**
	 * Live-update the public URL preview as the slug is typed (edit screen).
	 */
	function initSlugPreview() {
		var input = document.getElementById( 'franer_slug' );
		if ( ! input ) {
			return;
		}
		var preview = document.querySelector( '[data-franer-url-slug]' );
		if ( ! preview ) {
			return;
		}
		input.addEventListener( 'input', function () {
			var slug = input.value.toLowerCase().replace( /[^a-z0-9-]/g, '-' );
			if ( slug !== input.value ) {
				input.value = slug;
			}
			preview.textContent = slug || '…';
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
		initDetailDrawer();
		initDeleteButtons();
		initSlugPreview();
	} );
} )();
