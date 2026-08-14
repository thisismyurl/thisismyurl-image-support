/**
 * Inline alt text editing on the Media list screen.
 *
 * Saves on blur and on Enter. There is no explicit Save button in the normal flow
 * because the thing people are doing here is working down a list, and reaching for a
 * button after every row is what makes that tedious. The button exists in the markup as
 * a keyboard-reachable fallback and is revealed once a field has been changed.
 */
( function () {
	'use strict';

	var cfg = window.timuImageSupport || {};

	function announce( cell, message, isError ) {
		var state = cell.querySelector( '.timu-alt-state' );
		if ( ! state ) {
			return;
		}
		state.textContent = message;
		state.className = 'timu-alt-state' + ( isError ? ' is-error' : ' is-ok' );

		if ( ! isError ) {
			window.setTimeout( function () {
				if ( state.textContent === message ) {
					state.textContent = '';
					state.className = 'timu-alt-state';
				}
			}, 4000 );
		}
	}

	function save( cell ) {
		var input = cell.querySelector( '.timu-alt-input' );
		var id = cell.getAttribute( 'data-id' );

		if ( ! input || ! id ) {
			return;
		}

		var value = input.value;

		if ( value === input.getAttribute( 'data-saved' ) ) {
			return;
		}

		cell.classList.add( 'is-saving' );
		announce( cell, cfg.strings.saving, false );

		var body = new window.FormData();
		body.append( 'action', cfg.action );
		body.append( 'nonce', cfg.nonce );
		body.append( 'id', id );
		body.append( 'alt', value );

		window.fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( response ) {
			return response.json().catch( function () {
				throw new Error( cfg.strings.failed );
			} );
		} ).then( function ( result ) {
			cell.classList.remove( 'is-saving' );

			if ( ! result || ! result.success ) {
				var message = result && result.data && result.data.message
					? result.data.message
					: cfg.strings.failed;
				announce( cell, message, true );
				return;
			}

			input.setAttribute( 'data-saved', result.data.alt );
			input.value = result.data.alt;
			cell.classList.toggle( 'is-empty', '' === result.data.alt );
			announce( cell, result.data.message, false );
			hideButton( cell );
		} ).catch( function () {
			cell.classList.remove( 'is-saving' );
			announce( cell, cfg.strings.failed, true );
		} );
	}

	function showButton( cell ) {
		var button = cell.querySelector( '.timu-alt-save' );
		if ( button ) {
			button.hidden = false;
		}
	}

	function hideButton( cell ) {
		var button = cell.querySelector( '.timu-alt-save' );
		if ( button ) {
			button.hidden = true;
		}
	}

	function init() {
		var cells = document.querySelectorAll( '.timu-alt-cell' );

		Array.prototype.forEach.call( cells, function ( cell ) {
			var input = cell.querySelector( '.timu-alt-input' );

			if ( ! input ) {
				return;
			}

			input.setAttribute( 'data-saved', input.value );
			cell.classList.toggle( 'is-empty', '' === input.value );

			input.addEventListener( 'input', function () {
				if ( input.value !== input.getAttribute( 'data-saved' ) ) {
					showButton( cell );
				} else {
					hideButton( cell );
				}
			} );

			input.addEventListener( 'blur', function () {
				save( cell );
			} );

			input.addEventListener( 'keydown', function ( event ) {
				if ( 'Enter' === event.key ) {
					event.preventDefault();
					save( cell );
				}

				if ( 'Escape' === event.key ) {
					input.value = input.getAttribute( 'data-saved' );
					hideButton( cell );
				}
			} );

			var button = cell.querySelector( '.timu-alt-save' );

			if ( button ) {
				button.addEventListener( 'click', function () {
					save( cell );
				} );
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
