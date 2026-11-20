/**
 * The player a pasted link becomes, beside the link, as it is typed.
 */

import { ask, endpoint } from '../lib/lookups';

const Embed = {
	init( root ) {
		root.querySelectorAll( '.field-kit__embed' ).forEach(
			function ( wrap ) {
				if ( wrap.dataset.fkBound ) {
					return;
				}

				wrap.dataset.fkBound = '1';

				const input = wrap.querySelector( '.field-kit__embed-url' );
				let timer = null;

				if ( ! input ) {
					return;
				}

				wrap.fkShown = input.value.trim();

				input.addEventListener( 'input', function () {
					window.clearTimeout( timer );
					timer = window.setTimeout( function () {
						Embed.load( wrap, input );
					}, 400 );
				} );
			}
		);
	},

	load( wrap, input ) {
		wrap.fkTicket = ( wrap.fkTicket || 0 ) + 1;

		const link = input.value.trim();

		if ( link === wrap.fkShown ) {
			return;
		}

		const preview = wrap.querySelector( '.field-kit__embed-preview' );
		const status = wrap.querySelector( '.field-kit__embed-status' );
		const platforms = ( wrap.dataset.embedPlatforms || '' )
			.split( ',' )
			.filter( Boolean );
		const url = endpoint( 'embed', {
			url: link,
			title: wrap.dataset.embedTitle || '',
			platforms,
		} );

		wrap.fkShown = link;

		const ticket = wrap.fkTicket;

		if ( ! preview || ! url || '' === link ) {
			Embed.show( preview, status, '', '' );

			return;
		}

		ask( url )
			.then( function ( answer ) {
				if ( ticket !== wrap.fkTicket ) {
					return;
				}

				Embed.show(
					preview,
					status,
					answer.ok ? String( answer.json.html || '' ) : '',
					answer.ok ? '' : String( answer.json.message || '' )
				);
			} )
			.catch( function () {
				Embed.show( preview, status, '', '' );
			} );
	},

	/**
	 * Put the player in, or take it out and say why.
	 *
	 * The markup is the embed route's: an iframe the embeds package
	 * built from the platform's id, never from what was typed.
	 *
	 * @param {Element|null} preview Where the player goes.
	 * @param {Element|null} status  Where a refusal is said.
	 * @param {string}       html    The player.
	 * @param {string}       message Why there is none.
	 */
	show( preview, status, html, message ) {
		if ( preview ) {
			preview.innerHTML = html;
			preview.hidden = '' === html;
		}

		if ( status ) {
			status.textContent = message;
		}
	},
};

export default Embed;
