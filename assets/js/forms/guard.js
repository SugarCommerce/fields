/**
 * A warning before leaving a form with changes, and a mark on each
 * field that changed. Only where asked for: `data-fk-guard` on a form or
 * its container, or a selector given to Guard::enable().
 *
 * Changed means the form would now send something other than it would
 * have when it was drawn -- asked of the form, not counted from events,
 * so a letter typed and deleted is no change and a repeater row added by
 * a click is one.
 */

import { config, t } from '../lib/config';
import { fieldOf } from '../lib/lookups';

const Guard = {
	forms: [],
	listening: false,

	init( root ) {
		const selectors = [ '[data-fk-guard]' ].concat( config.guard || [] );

		selectors.forEach( function ( selector ) {
			const found = [];

			try {
				if ( root.matches && root.matches( selector ) ) {
					found.push( root );
				}

				root.querySelectorAll( selector ).forEach( function ( el ) {
					found.push( el );
				} );
			} catch {
				return;
			}

			found.forEach( Guard.watch );
		} );

		if ( ! Guard.listening && Guard.forms.length ) {
			Guard.listening = true;
			window.addEventListener( 'beforeunload', Guard.unload );

			// A flyouts panel says when it has been saved: what it holds
			// now is what is stored.
			document.addEventListener( 'wpflyout:dirty', Guard.saved );
		}
	},

	/**
	 * A flyouts panel saying whether it has changes.
	 *
	 * Fired natively on the panel, bubbling, with `{ dirty }` as its
	 * detail. Not dirty any more means saved: what the form holds now is
	 * what is stored.
	 *
	 * @param {CustomEvent} event The `wpflyout:dirty` event.
	 */
	saved( event ) {
		const dirty = event.detail ? event.detail.dirty : undefined;
		const form =
			event.target && event.target.querySelector
				? event.target.querySelector( 'form' )
				: null;

		if ( false === dirty && form && form.fkGuard ) {
			Guard.reset( form );
		}
	},

	/**
	 * The form a guarded element is or holds.
	 *
	 * @param {Element} el The element.
	 * @return {Element|null} The form.
	 */
	form( el ) {
		if ( ! el ) {
			return null;
		}

		if ( 'FORM' === el.tagName ) {
			return el;
		}

		return (
			( el.closest && el.closest( 'form' ) ) ||
			( el.querySelector && el.querySelector( 'form' ) ) ||
			null
		);
	},

	watch( el ) {
		const form = Guard.form( el );
		let timer = null;

		if ( ! form || form.fkGuard ) {
			return;
		}

		form.fkGuard = {
			initial: Guard.snapshot( form ),
			dirty: false,
			sending: false,
			touched: false,
		};
		Guard.forms.push( form );

		const look = function () {
			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				Guard.refresh( form );
			}, 80 );
		};

		form.addEventListener( 'input', look );
		form.addEventListener( 'change', look );
		form.addEventListener( 'field-kit:change', look );

		form.addEventListener( 'submit', function () {
			form.fkGuard.sending = true;
		} );

		[ 'keydown', 'pointerdown' ].forEach( function ( type ) {
			form.addEventListener(
				type,
				function () {
					form.fkGuard.touched = true;
				},
				{ once: true }
			);
		} );

		// A control that finishes setting itself up a moment after it is
		// drawn has not been changed by anybody.
		window.addEventListener( 'load', function () {
			if ( ! form.fkGuard.touched ) {
				Guard.reset( form );
			}
		} );

		if ( window.MutationObserver ) {
			new window.MutationObserver( look ).observe( form, {
				childList: true,
				subtree: true,
			} );
		}
	},

	/**
	 * What the form would send, by name.
	 *
	 * @param {Element} form The form.
	 * @return {Object} Values by name.
	 */
	snapshot( form ) {
		const data = {};

		if (
			window.tinymce &&
			typeof window.tinymce.triggerSave === 'function'
		) {
			try {
				window.tinymce.triggerSave();
			} catch {
				// An editor that will not save leaves the textarea as it was.
			}
		}

		Array.prototype.forEach.call( form.elements || [], function ( el ) {
			if (
				! el.name ||
				/^(submit|button|reset|file)$/.test( el.type ) ||
				/(^_wpnonce$|^_wp_http_referer$|_nonce$|_sugarcommerce_fields_form$)/.test(
					el.name
				)
			) {
				return;
			}

			const list = data[ el.name ] || ( data[ el.name ] = [] );

			if (
				el.disabled ||
				( ( 'checkbox' === el.type || 'radio' === el.type ) &&
					! el.checked )
			) {
				return;
			}

			if ( 'select-multiple' === el.type ) {
				Array.prototype.forEach.call( el.options, function ( option ) {
					if ( option.selected ) {
						list.push( option.value );
					}
				} );

				return;
			}

			list.push( el.value );
		} );

		return data;
	},

	/**
	 * The names whose values are not what they were.
	 *
	 * @param {Element} form The form.
	 * @return {string[]} Names.
	 */
	changed( form ) {
		const now = Guard.snapshot( form );
		const was = form.fkGuard.initial;
		const names = {};

		Object.keys( now )
			.concat( Object.keys( was ) )
			.forEach( function ( name ) {
				if (
					JSON.stringify( now[ name ] || [] ) !==
					JSON.stringify( was[ name ] || [] )
				) {
					names[ name ] = true;
				}
			} );

		return Object.keys( names );
	},

	refresh( form ) {
		if ( ! form.fkGuard ) {
			return;
		}

		const names = Guard.changed( form );
		const fields = [];

		names.forEach( function ( name ) {
			let el = null;

			Array.prototype.some.call(
				form.elements || [],
				function ( control ) {
					if ( control.name === name ) {
						el = control;

						return true;
					}

					return false;
				}
			);

			const field = Guard.top( form, fieldOf( el ) );

			if ( field && -1 === fields.indexOf( field ) ) {
				fields.push( field );
			}
		} );

		form.querySelectorAll( '.field-kit__field--changed' ).forEach(
			function ( field ) {
				if ( -1 === fields.indexOf( field ) ) {
					Guard.unmark( field );
				}
			}
		);

		fields.forEach( Guard.mark );

		form.fkGuard.dirty = names.length > 0;
		form.dispatchEvent(
			new CustomEvent( 'field-kit:dirty', {
				bubbles: true,
				detail: { dirty: form.fkGuard.dirty, count: fields.length },
			} )
		);
	},

	/**
	 * The outermost field in the form a field is inside: a repeater,
	 * not the row's own text box.
	 *
	 * @param {Element}      form  The form.
	 * @param {Element|null} field A field.
	 * @return {Element|null} The outermost one.
	 */
	top( form, field ) {
		let parent =
			field && field.parentElement
				? fieldOf( field.parentElement )
				: null;

		while ( parent && form.contains( parent ) ) {
			field = parent;
			parent = field.parentElement
				? fieldOf( field.parentElement )
				: null;
		}

		return field;
	},

	mark( field ) {
		if ( field.classList.contains( 'field-kit__field--changed' ) ) {
			return;
		}

		const flag = document.createElement( 'span' );
		const label = field.querySelector(
			':scope > label, :scope > .field-kit__label-row > label, :scope > fieldset > legend > span'
		);

		field.classList.add( 'field-kit__field--changed' );
		flag.className = 'field-kit__changed-flag';
		flag.textContent = t( 'changed', 'Changed' );

		if ( label ) {
			label.appendChild( flag );
		} else {
			field.insertBefore( flag, field.firstChild );
		}
	},

	unmark( field ) {
		field.classList.remove( 'field-kit__field--changed' );
		field
			.querySelectorAll( '.field-kit__changed-flag' )
			.forEach( function ( flag ) {
				if (
					Guard.top(
						field.closest( 'form' ) || field,
						fieldOf( flag )
					) === field ||
					fieldOf( flag ) === field
				) {
					flag.remove();
				}
			} );
	},

	/**
	 * Take what the form holds now as its saved state.
	 *
	 * @param {Element} form The form.
	 */
	reset( form ) {
		if ( ! form || ! form.fkGuard ) {
			return;
		}

		form.fkGuard.initial = Guard.snapshot( form );
		form.fkGuard.sending = false;
		Guard.refresh( form );
	},

	/**
	 * Whether a guarded form on the page, or the one given, has changes.
	 *
	 * @param {Element} [el] A form or an element holding one.
	 * @return {boolean} Whether it has.
	 */
	dirty( el ) {
		const form = el ? Guard.form( el ) : null;

		if ( form ) {
			return !! (
				form.fkGuard &&
				form.fkGuard.dirty &&
				! form.fkGuard.sending
			);
		}

		return Guard.forms.some( function ( each ) {
			return (
				document.contains( each ) &&
				each.fkGuard.dirty &&
				! each.fkGuard.sending
			);
		} );
	},

	unload( event ) {
		if ( ! Guard.dirty() ) {
			return undefined;
		}

		event.preventDefault();
		event.returnValue = '';

		return '';
	},

	/**
	 * Ask before throwing away changes inside the page -- for code about
	 * to remove or replace a guarded form.
	 *
	 * @param {Element} [el] The form, or what holds it.
	 * @return {boolean} True to go ahead.
	 */
	confirm( el ) {
		return (
			! Guard.dirty( el ) ||
			// eslint-disable-next-line no-alert -- the browser asking is the point.
			window.confirm(
				t(
					'leaveChanges',
					'You have changes that have not been saved. Leave anyway?'
				)
			)
		);
	},
};

export default Guard;
