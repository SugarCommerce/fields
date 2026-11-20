/**
 * Buttons that run an action: a licence's activate and deactivate, an
 * email's preview and test send, and whatever a plugin wires up -- each posts
 * a payload, names a handler and shows what came back.
 */

import { config, t } from '../lib/config';
import { announce, bound, swapIcon } from '../lib/dom';
import { request } from '../lib/request';

const ActionButton = {
	/**
	 * Bind every action button within a root.
	 *
	 * One handler for licences, email previews, test sends and whatever
	 * a consumer wires up: they are all the same shape — post a payload,
	 * name a handler, show what came back.
	 *
	 * @param {Element} root Container.
	 */
	init( root ) {
		if ( bound( root, 'ActionButton' ) ) {
			return;
		}

		root.addEventListener( 'click', function ( event ) {
			const button = event.target.closest(
				'[data-endpoint][data-action]'
			);

			if ( ! button || button.disabled ) {
				return;
			}

			event.preventDefault();
			ActionButton.run( button );
		} );
	},

	/**
	 * Run one button's action.
	 *
	 * @param {HTMLButtonElement} button The button.
	 */
	run( button ) {
		if (
			button.dataset.confirm &&
			// eslint-disable-next-line no-alert -- the browser asking is what the button was given.
			! window.confirm( button.dataset.confirm )
		) {
			return;
		}

		// A button outside a field collects from its own container, never
		// from the document: that would send every named input on the
		// page -- other licence keys, password fields -- to this one's
		// endpoint.
		const wrap = button.closest( '.field-kit__field' ) || button.parentNode;
		const status = wrap.querySelector( '[aria-live]' );
		const spinner = wrap.querySelector( '.spinner' );

		ActionButton.syncEditors( wrap );

		const payload = ActionButton.payload( button, wrap );

		// Anything else on the page that belongs in this request can add
		// itself: a listener is handed the payload and writes to it. A
		// screen with one card for how every email looks sends that card
		// with each email's preview this way, and the field stays
		// ignorant of the screen it happens to be on.
		button.dispatchEvent(
			new CustomEvent( 'fieldkit:action-payload', {
				bubbles: true,
				detail: { action: button.dataset.action, payload },
			} )
		);

		// Disabled for the duration, or an impatient double click runs a
		// licence activation twice.
		button.disabled = true;

		if ( spinner ) {
			spinner.classList.add( 'is-active' );
		}

		request( button.dataset.endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': button.dataset.nonce || config.restNonce || '',
			},
			body: JSON.stringify( {
				action: button.dataset.action,
				payload,
			} ),
		} )
			.then( function ( response ) {
				return response.json().catch( function () {
					return {
						success: false,
						message: t( 'actionFailed', 'That did not work.' ),
					};
				} );
			} )
			.then( function ( result ) {
				ActionButton.report( wrap, status, result );
			} )
			.catch( function () {
				ActionButton.report( wrap, status, {
					success: false,
					message: t( 'actionFailed', 'That did not work.' ),
				} );
			} )
			.finally( function () {
				button.disabled = false;

				if ( spinner ) {
					spinner.classList.remove( 'is-active' );
				}
			} );
	},

	/**
	 * Write every visual editor in a field through to its textarea.
	 *
	 * A visual editor keeps what is typed in an iframe and copies it to
	 * the textarea of the same name when the form is submitted. A button
	 * is not a submit, so the payload below read the textarea as it was
	 * when the page loaded: a preview of an email showed none of what
	 * had just been typed into it.
	 *
	 * Only an editor that is showing. On its Code tab the textarea is
	 * what is being typed into, and saving the hidden visual editor over
	 * it would undo that.
	 *
	 * @param {Element} wrap The field wrapper.
	 */
	syncEditors( wrap ) {
		if ( ! window.tinymce ) {
			return;
		}

		wrap.querySelectorAll( 'textarea[id]' ).forEach( function ( textarea ) {
			const editor = window.tinymce.get( textarea.id );

			if ( editor && ! editor.isHidden() ) {
				editor.save();
			}
		} );
	},

	/**
	 * Collect what the field wants to send.
	 *
	 * A licence sends its key, an email its subject and body. Rather than
	 * a rule per type, every named input inside the field travels.
	 *
	 * @param {HTMLButtonElement} button The button.
	 * @param {Element}           wrap   The field wrapper.
	 * @return {Object} Payload.
	 */
	payload( button, wrap ) {
		const payload = {};
		const key = wrap.dataset ? wrap.dataset.fieldKey : '';

		wrap.querySelectorAll( '[name]' ).forEach( function ( input ) {
			if ( input.type === 'checkbox' || input.type === 'radio' ) {
				if ( ! input.checked ) {
					return;
				}
			}

			// Bracketed names are the field's own structure; the last
			// segment is what a handler is going to look for.
			const name = input.name.replace( /^.*\[([^\]]+)\]$/, '$1' );

			payload[ name ] = input.value;

			// And under `value`, for the field's own control.
			//
			// Otherwise a handler has to know the field key to find it,
			// which differs per screen for the same field: a licence is
			// `apfd_license` on a term screen and `apfd_every_license` on
			// a settings page, so a handler written for one silently read
			// nothing on the other and reported the box as empty.
			if ( key && name === key ) {
				payload.value = input.value;
			}
		} );

		return payload;
	},

	/**
	 * Show the outcome.
	 *
	 * Written into the field's own live region, so it is announced where
	 * it happened rather than shouted from the page, and given a notice
	 * class so success and failure do not read identically.
	 *
	 * @param {Element} wrap   The field wrapper.
	 * @param {Element} status The live region.
	 * @param {Object}  result What came back.
	 */
	report( wrap, status, result ) {
		const message =
			result.message ||
			( result.success
				? t( 'actionDone', 'Done.' )
				: t( 'actionFailed', 'That did not work.' ) );

		if ( status ) {
			status.classList.remove(
				'field-kit__status--success',
				'field-kit__status--error'
			);
			status.classList.add(
				result.success
					? 'field-kit__status--success'
					: 'field-kit__status--error'
			);
			announce( status, message );
		}

		// A handler may hand back markup to show — an email preview, for
		// instance — which goes in a region of its own rather than into
		// the live region, where it would be read out in full.
		if ( result.data && result.data.html ) {
			const preview = wrap.querySelector( '.field-kit__action-preview' );

			if ( preview ) {
				preview.innerHTML = result.data.html;
				preview.hidden = false;
			}
		}

		// Or the new state of the field it acted on, which is what a
		// licence activation has to hand back: an action that succeeds
		// and leaves the field showing the state it was in before is an
		// action that looks like it failed.
		if ( result.success && result.data && result.data.state ) {
			ActionButton.applyState( wrap, result.data.state );
		}
	},

	/**
	 * Put a field into the state a handler reported.
	 *
	 * Only the licence field has a state to be in; everything else is a
	 * button that does something and says what happened. Written here
	 * rather than in a module of its own because this is where the
	 * response arrives, and a second listener for the same fetch would be
	 * a second place for the two to drift apart.
	 *
	 * @param {Element} wrap  The field.
	 * @param {Object}  state What it is now.
	 */
	applyState( wrap, state ) {
		const licence = wrap.querySelector( '.field-kit__license' );

		if ( ! licence ) {
			return;
		}

		const active = !! state.active;
		const badge = wrap.querySelector( '.field-kit__license-state' );
		const text = wrap.querySelector( '.field-kit__license-state-text' );
		const glyph = badge && badge.querySelector( '.sugarcommerce-icon' );
		const button = wrap.querySelector( '.field-kit__license-action' );
		const input = wrap.querySelector( '.field-kit__license-key' );
		const meta = wrap.querySelector( '.field-kit__license-meta' );
		const sites = wrap.querySelector( '.field-kit__license-sites' );

		if ( badge ) {
			badge.classList.toggle(
				'field-kit__license-state--active',
				active
			);
			badge.classList.toggle(
				'field-kit__license-state--inactive',
				! active
			);
		}

		if ( text ) {
			text.textContent = active
				? licence.dataset.labelActive
				: licence.dataset.labelInactive;
		}

		swapIcon( glyph, active ? 'active' : 'inactive' );

		if ( button ) {
			button.textContent = active
				? button.dataset.labelDeactivate
				: button.dataset.labelActivate;

			// The registered name of the *other* action, so the next
			// press reaches the handler that undoes this one.
			button.dataset.action = active
				? button.dataset.actionDeactivate || ''
				: button.dataset.actionActivate || '';

			button.classList.toggle( 'field-kit__button--delete', active );
		}

		// The key is masked while a licence is active, and the box is
		// read-only — there is nothing to type until it is released.
		if ( input ) {
			input.readOnly = active;

			if ( typeof state.key === 'string' ) {
				input.value = state.key;
			}
		}

		if (
			sites &&
			meta &&
			Array.isArray( state.sites ) &&
			2 === state.sites.length
		) {
			sites.hidden = false;
			sites.textContent = (
				meta.dataset.sitesTemplate || '%1$s of %2$s sites'
			)
				.replace( '%1$s', String( state.sites[ 0 ] ) )
				.replace( '%2$s', String( state.sites[ 1 ] ) );
		}
	},
};

export default ActionButton;
