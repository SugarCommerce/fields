/**
 * A slug cleaned as it is typed, made from the field it names, and
 * checked against the caller's own idea of what is taken.
 */

import { t } from '../lib/config';
import { sibling } from '../lib/dom';
import { ask, endpoint, fieldOf } from '../lib/lookups';

const Slug = {
	init( root ) {
		root.querySelectorAll( 'input[data-slug]' ).forEach(
			function ( input ) {
				if ( input.dataset.fkBound ) {
					return;
				}

				input.dataset.fkBound = '1';

				const field = fieldOf( input );
				const status = field
					? field.querySelector( '.field-kit__slug-status' )
					: null;
				const source = input.dataset.slugSource
					? sibling( input, input.dataset.slugSource )
					: null;
				let timer = null;

				// Following the source until somebody writes a slug of their own.
				input.fkFollowing = '' === input.value;

				if ( source ) {
					source.addEventListener( 'input', function () {
						if ( input.fkFollowing ) {
							input.value = Slug.make( source.value );
							input.dispatchEvent(
								new Event( 'input', { bubbles: true } )
							);
							input.fkFollowing = true;
						}
					} );
				}

				input.addEventListener( 'input', function ( event ) {
					if ( event.isTrusted ) {
						input.fkFollowing = '' === input.value;
					}

					window.clearTimeout( timer );
					timer = window.setTimeout( function () {
						Slug.check( input, status );
					}, 350 );
				} );

				input.addEventListener( 'blur', function () {
					const clean = Slug.make( input.value );

					if ( clean !== input.value ) {
						input.value = clean;
						input.dispatchEvent(
							new Event( 'change', { bubbles: true } )
						);
					}
				} );
			}
		);
	},

	/**
	 * Text as a slug: lower case, accents off, hyphens between words.
	 *
	 * @param {string} text The text.
	 * @return {string} The slug.
	 */
	make( text ) {
		return String( text || '' )
			.normalize( 'NFD' )
			.replace( /[̀-ͯ]/g, '' )
			.toLowerCase()
			.replace( /[^a-z0-9_]+/g, '-' )
			.replace( /^-+|-+$/g, '' );
	},

	/**
	 * Ask whether the slug is free, and say so.
	 *
	 * @param {Element}      input  The slug.
	 * @param {Element|null} status Where to say it.
	 */
	check( input, status ) {
		const slug = Slug.make( input.value );
		const url = input.dataset.lookup
			? endpoint( 'lookup', { name: input.dataset.lookup, q: slug } )
			: null;
		const ticket = ( input.fkTicket = ( input.fkTicket || 0 ) + 1 );

		input.setCustomValidity( '' );

		if ( ! url || '' === slug || slug === input.dataset.current ) {
			Slug.say( status, '', '' );

			return;
		}

		Slug.say( status, t( 'slugChecking', 'Checking…' ), '' );

		ask( url )
			.then( function ( answer ) {
				if ( ticket !== input.fkTicket || ! answer.ok ) {
					Slug.say( status, '', '' );

					return;
				}

				const free = !! answer.json.available;

				input.setCustomValidity(
					free ? '' : String( answer.json.message || '' )
				);
				Slug.say(
					status,
					free
						? t( 'slugFree', 'Available.' )
						: String( answer.json.message || '' ),
					free ? 'free' : 'taken'
				);
			} )
			.catch( function () {
				Slug.say( status, '', '' );
			} );
	},

	say( status, text, state ) {
		if ( ! status ) {
			return;
		}

		status.textContent = text;
		status.classList.toggle( 'is-free', 'free' === state );
		status.classList.toggle( 'is-taken', 'taken' === state );
	},
};

export default Slug;
