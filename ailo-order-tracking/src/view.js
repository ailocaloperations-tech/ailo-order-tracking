/**
 * Front-end behaviour for the Order Tracking Lookup block.
 *
 * No framework, no jQuery: this ships to every visitor of the page, so it stays
 * a few hundred bytes. The result is built with createElement/textContent rather
 * than innerHTML — the values come back from our own REST route, but building
 * DOM instead of concatenating HTML means a future change to the response can
 * never turn into an injection.
 *
 * @package
 */

( function () {
	'use strict';

	const settings = window.ailoTrackSettings || {};
	const root = settings.root || '';
	const i18n = settings.i18n || {};

	function text( tag, className, value ) {
		const el = document.createElement( tag );
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
		const wrap = document.createElement( 'div' );
		wrap.className = 'ailo-track__found';

		if ( data.carrier ) {
			wrap.appendChild(
				text( 'p', 'ailo-track__carrier', data.carrier )
			);
		}
		wrap.appendChild( text( 'p', 'ailo-track__number', data.tracking ) );

		if ( withLink && data.url ) {
			const a = document.createElement( 'a' );
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
		const form = block.querySelector( '.ailo-track__form' );
		const box = block.querySelector( '.ailo-track__result' );
		if ( ! form || ! box ) {
			return;
		}

		const withLink = block.getAttribute( 'data-carrier-link' ) === '1';
		const button = form.querySelector( '.ailo-track__submit' );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			const trackingField = form.querySelector( '[name="tracking"]' );
			const orderField = form.querySelector( '[name="order_id"]' );
			const contactField = form.querySelector( '[name="contact"]' );

			const tracking = trackingField ? trackingField.value.trim() : '';
			let body;

			if ( tracking ) {
				body = { mode: 'tracking', tracking };
			} else if (
				orderField &&
				contactField &&
				orderField.value.trim() &&
				contactField.value.trim()
			) {
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
						return { ok: response.ok, data };
					} );
				} )
				.then( function ( result ) {
					if ( ! result.ok ) {
						showMessage(
							box,
							( result.data && result.data.message ) ||
								i18n.notFound ||
								'Not found.'
						);
						return;
					}
					showResult( box, result.data, withLink );
				} )
				.catch( function () {
					showMessage(
						box,
						i18n.error || 'Something went wrong. Please try again.'
					);
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
