/**
 * Front-end behaviour for the Order Tracking Lookup block.
 *
 * No framework, no jQuery: this ships to every visitor of the page, so it stays
 * a few hundred bytes. The result is built with createElement/textContent rather
 * than innerHTML — the values come back from our own REST route, but building
 * DOM instead of concatenating HTML means a future change to the response can
 * never turn into an injection.
 *
 * @package AiloOrderTracking
 */

( function () {
	'use strict';

	var settings = window.ailoTrackSettings || {};
	var root = settings.root || '';
	var i18n = settings.i18n || {};

	function text( tag, className, value ) {
		var el = document.createElement( tag );
		if ( className ) {
			el.className = className;
		}
		if ( value ) {
			el.textContent = value;
		}
		return el;
	}

	function showMessage( box, message ) {
		box.replaceChildren( text( 'p', 'ailo-track__message', message ) );
	}

	function showResult( box, data, withLink ) {
		var wrap = document.createElement( 'div' );
		wrap.className = 'ailo-track__found';

		if ( data.carrier ) {
			wrap.appendChild( text( 'p', 'ailo-track__carrier', data.carrier ) );
		}
		wrap.appendChild( text( 'p', 'ailo-track__number', data.tracking ) );

		if ( withLink && data.url ) {
			var a = document.createElement( 'a' );
			a.className = 'ailo-track__link';
			a.href = data.url;
			a.target = '_blank';
			a.rel = 'noopener noreferrer';
			a.textContent = i18n.open || 'Open carrier page';
			wrap.appendChild( a );
		}

		box.replaceChildren( wrap );
	}

	function wire( block ) {
		var form = block.querySelector( '.ailo-track__form' );
		var box = block.querySelector( '.ailo-track__result' );
		if ( ! form || ! box ) {
			return;
		}

		var withLink = block.getAttribute( 'data-carrier-link' ) === '1';
		var button = form.querySelector( '.ailo-track__submit' );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			var trackingField = form.querySelector( '[name="tracking"]' );
			var orderField = form.querySelector( '[name="order_id"]' );
			var contactField = form.querySelector( '[name="contact"]' );

			var tracking = trackingField ? trackingField.value.trim() : '';
			var body;

			if ( tracking ) {
				body = { mode: 'tracking', tracking: tracking };
			} else if ( orderField && contactField && orderField.value.trim() && contactField.value.trim() ) {
				body = {
					mode: 'order',
					order_id: parseInt( orderField.value, 10 ) || 0,
					contact: contactField.value.trim(),
				};
			} else {
				showMessage( box, i18n.missing || 'Please fill in the form.' );
				return;
			}

			if ( button ) {
				button.disabled = true;
			}
			showMessage( box, i18n.searching || 'Searching…' );

			fetch( root + 'ailo-track/v1/lookup', {
				method: 'POST',
				// No X-WP-Nonce: the route is public by design, and a nonce baked into
				// cached HTML goes stale and turns every lookup into a hard 403.
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( body ),
			} )
				.then( function ( response ) {
					return response.json().then( function ( data ) {
						return { ok: response.ok, data: data };
					} );
				} )
				.then( function ( result ) {
					if ( ! result.ok ) {
						showMessage(
							box,
							( result.data && result.data.message ) || i18n.notFound || 'Not found.'
						);
						return;
					}
					showResult( box, result.data, withLink );
				} )
				.catch( function () {
					showMessage( box, i18n.error || 'Something went wrong. Please try again.' );
				} )
				.finally( function () {
					if ( button ) {
						button.disabled = false;
					}
				} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.ailo-track' ).forEach( wire );
	} );
} )();
