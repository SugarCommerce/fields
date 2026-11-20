/**
 * A form on the front of the site: the refusals at the top lead to
 * their fields, and a form registered for REST is sent without leaving
 * the page, its answer drawn where the server would have drawn it.
 */

import Address from '../fields/address';
import { config, t } from '../lib/config';
import { ask, fieldOf } from '../lib/lookups';
import Guard from './guard';
import { ours } from '../lib/dom';

const FrontForm = {
	init( root ) {
		const forms = [];

		if ( root.matches && root.matches( 'form[data-sugarcommerce-form]' ) ) {
			forms.push( root );
		}

		ours( root, 'form[data-sugarcommerce-form]' ).forEach(
			function ( form ) {
				forms.push( form );
			}
		);

		forms.forEach( function ( form ) {
			if ( form.dataset.fkBound ) {
				return;
			}

			form.dataset.fkBound = '1';

			form.addEventListener( 'click', function ( event ) {
				const link =
					event.target && event.target.closest
						? event.target.closest(
								'.sugarcommerce-form__notice a[data-field]'
							)
						: null;
				const target = link
					? FrontForm.target(
							form,
							link.dataset.field,
							( link.getAttribute( 'href' ) || '' ).slice( 1 )
						)
					: null;

				if ( target ) {
					event.preventDefault();
					target.focus();
				}
			} );

			const notice = form.querySelector( '.sugarcommerce-form__notice' );

			if ( notice && ! notice.hidden ) {
				notice.focus();
			}

			if ( form.dataset.rest ) {
				form.addEventListener( 'submit', function ( event ) {
					FrontForm.submit( event, form );
				} );
			}
		} );
	},

	/**
	 * The control a refusal is about.
	 *
	 * @param {Element} form The form.
	 * @param {string}  key  The field's key.
	 * @param {string}  id   The control's id.
	 * @return {Element|null} Something to focus.
	 */
	target( form, key, id ) {
		const byId = id ? document.getElementById( id ) : null;

		if ( byId && ! byId.disabled && 'hidden' !== byId.type ) {
			return byId;
		}

		const field = FrontForm.field( form, key );

		return field
			? field.querySelector(
					'input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])'
				)
			: null;
	},

	/**
	 * One of the form's own fields, not a field of the same key inside
	 * a group.
	 *
	 * @param {Element} form The form.
	 * @param {string}  key  The key.
	 * @return {Element|null} The field.
	 */
	field( form, key ) {
		let found = null;

		form.querySelectorAll( '[data-field-key]' ).forEach(
			function ( field ) {
				if (
					! found &&
					field.dataset.fieldKey === key &&
					! ( field.parentElement && fieldOf( field.parentElement ) )
				) {
					found = field;
				}
			}
		);

		return found;
	},

	submit( event, form ) {
		if ( event.defaultPrevented ) {
			return;
		}

		event.preventDefault();

		if ( form.fkSending ) {
			return;
		}

		const button = form.querySelector( '.sugarcommerce-form__submit' );
		const label = button ? button.textContent : '';
		const body = new URLSearchParams();

		if (
			window.tinymce &&
			typeof window.tinymce.triggerSave === 'function'
		) {
			window.tinymce.triggerSave();
		}

		new FormData( form ).forEach( function ( value, name ) {
			if ( typeof value === 'string' ) {
				body.append( name, value );
			}
		} );

		form.fkSending = true;
		form.setAttribute( 'aria-busy', 'true' );

		if ( button ) {
			button.disabled = true;
			button.textContent = t( 'sending', 'Sending…' );
		}

		ask( new URL( form.dataset.rest, window.location.origin ), {
			method: 'POST',
			headers: {
				'Content-Type':
					'application/x-www-form-urlencoded; charset=UTF-8',
				'X-WP-Nonce': config.restNonce || '',
			},
			body: body.toString(),
		} )
			.then( function ( answer ) {
				FrontForm.show(
					form,
					answer.json && 'string' === typeof answer.json.message
						? answer.json
						: {
								ok: false,
								message: t(
									'sendFailed',
									'That could not be sent. Please try again.'
								),
							}
				);
			} )
			.catch( function () {
				FrontForm.show( form, {
					ok: false,
					message: t(
						'sendFailed',
						'That could not be sent. Please try again.'
					),
				} );
			} )
			.then( function () {
				form.fkSending = false;
				form.removeAttribute( 'aria-busy' );

				if ( button ) {
					button.disabled = false;
					button.textContent = label;
				}
			} );
	},

	/**
	 * Draw an answer: the message at the top, a refusal under each field
	 * it is about, or a clean form once it has gone.
	 *
	 * @param {Element} form   The form.
	 * @param {Object}  answer The route's answer.
	 */
	show( form, answer ) {
		const notice = form.querySelector( '.sugarcommerce-form__notice' );
		const errors = answer.errors || {};
		const ids = answer.ids || {};
		const keys = Object.keys( errors );

		form.querySelectorAll( '.field-kit__error' ).forEach(
			function ( error ) {
				const control = error.id
					? form.querySelector(
							'[aria-describedby~="' + error.id + '"]'
						)
					: null;

				if ( control ) {
					FrontForm.describe( control, error.id, false );
				}

				error.remove();
			}
		);

		form.querySelectorAll( '[aria-invalid="true"]' ).forEach(
			function ( control ) {
				control.removeAttribute( 'aria-invalid' );
			}
		);

		keys.forEach( function ( key ) {
			const field = FrontForm.field( form, key );

			if ( ! field ) {
				return;
			}

			const id = String( ids[ key ] || '' );
			const error = document.createElement( 'p' );

			error.className = 'field-kit__error';
			error.id = ( id || form.id + '_' + key ) + '__error';
			error.textContent = String( errors[ key ] );

			( field.querySelector( ':scope > fieldset' ) || field ).appendChild(
				error
			);

			const control = id ? document.getElementById( id ) : null;
			const group = field.querySelector( ':scope > fieldset' );

			( group ? [ group ] : [] )
				.concat( control ? [ control ] : [] )
				.forEach( function ( el ) {
					FrontForm.describe( el, error.id, true );
				} );

			if ( control ) {
				control.setAttribute( 'aria-invalid', 'true' );
			}
		} );

		if ( notice ) {
			let list = '';

			keys.forEach( function ( key ) {
				list +=
					'<li><a href="#' +
					Address.escape( String( ids[ key ] || '' ) ) +
					'" data-field="' +
					Address.escape( key ) +
					'">' +
					Address.escape( String( errors[ key ] ) ) +
					'</a></li>';
			} );

			notice.innerHTML =
				'<p>' +
				Address.escape( String( answer.message || '' ) ) +
				'</p>' +
				( list ? '<ul>' + list + '</ul>' : '' );
			notice.className =
				'sugarcommerce-form__notice sugarcommerce-form__notice--' +
				( answer.ok ? 'success' : 'error' );
			notice.setAttribute( 'role', answer.ok ? 'status' : 'alert' );
			notice.hidden = false;
			notice.focus();
		}

		if ( answer.ok ) {
			if ( ! form.hasAttribute( 'data-keep' ) ) {
				form.reset();
				form.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			}

			Guard.reset( form );
		}

		form.dispatchEvent(
			new CustomEvent( 'sugarcommerce-form:answer', {
				bubbles: true,
				detail: answer,
			} )
		);
	},

	describe( el, id, add ) {
		const ids = ( el.getAttribute( 'aria-describedby' ) || '' )
			.split( ' ' )
			.filter( function ( each ) {
				return each && each !== id;
			} );

		if ( add ) {
			ids.push( id );
		}

		if ( ids.length ) {
			el.setAttribute( 'aria-describedby', ids.join( ' ' ) );
		} else {
			el.removeAttribute( 'aria-describedby' );
		}
	},
};

export default FrontForm;
