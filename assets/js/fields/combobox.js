/**
 * Searchable relational fields.
 *
 * Implements the ARIA combobox pattern against the real <select> the server
 * rendered, rather than replacing it. The select stays the source of truth,
 * so the field still submits correctly if this never runs, and there is a
 * genuine element to mirror instead of state invented in script.
 */

import { config, icon, t } from '../lib/config';
import { announce, ours } from '../lib/dom';
import { request } from '../lib/request';

const Combobox = {
	/**
	 * The grab handle for a chip in an ordered list.
	 *
	 * One control, the one the Prices repeater, flexible rows and the
	 * gallery use, rather than the up and down chevrons it replaced. The
	 * first chip has no up and the last no down, so the pair was drawn
	 * with one of them missing -- a down on the first row and an up on
	 * the second, in different places, which read as broken. A handle is
	 * the same control in the same place on every row.
	 *
	 * A button, so it is focused and moved with the arrow keys
	 * (Reorder.bindButtons) as well as dragged. Its name says where the
	 * chip is, from a template Reorder.sync() rewrites after a move, so a
	 * keyboard user hears that the move landed.
	 *
	 * @param {string} label    The chip's text, for the accessible name.
	 * @param {number} position Zero-based position.
	 * @param {number} total    How many chips there are.
	 * @return {HTMLElement} The button.
	 */
	handle( label, position, total ) {
		const button = document.createElement( 'button' );
		const template = t( 'reorderItem', 'Reorder %1$s, %2$s of %3$s' )
			.replace( '%1$s', label )
			.replace( '%2$s', '{position}' )
			.replace( '%3$s', '{total}' );

		button.type = 'button';
		button.className =
			'field-kit__drag-handle field-kit__combobox-chip-handle';
		button.setAttribute(
			'aria-roledescription',
			t( 'sortable', 'Sortable' )
		);
		button.setAttribute( 'aria-keyshortcuts', 'ArrowUp ArrowDown' );
		button.dataset.labelTemplate = template;
		button.setAttribute(
			'aria-label',
			template
				.replace( '{position}', String( position + 1 ) )
				.replace( '{total}', String( total ) )
		);
		button.innerHTML = icon( 'drag' );

		return button;
	},

	/**
	 * Whether a search's results leave room to add what was typed as a
	 * new item.
	 *
	 * Not when one of them already is it: "Sample Packs" offered as new
	 * beside a "Sample Packs" that exists is an invitation to make a
	 * duplicate. A user is labelled "Name (address)", so an address
	 * matches the one in brackets. And a user is added by their address,
	 * so nothing else typed is offered at all -- the server would only
	 * refuse it.
	 *
	 * @param {Array}  found The search's results.
	 * @param {string} typed What is in the box.
	 * @param {string} kind  'email' when an address is needed, else ''.
	 * @return {boolean} Whether to offer the Add row.
	 */
	offersAdd( found, typed, kind ) {
		const needle = String( typed || '' )
			.trim()
			.toLowerCase();

		if ( '' === needle ) {
			return false;
		}

		if (
			'email' === kind &&
			! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( needle )
		) {
			return false;
		}

		return ! ( found || [] ).some( function ( result ) {
			const text = String( result.text || '' ).toLowerCase();

			return (
				! result.add &&
				( text === needle ||
					text.slice( -( needle.length + 2 ) ) ===
						'(' + needle + ')' )
			);
		} );
	},

	/**
	 * The Add row's words: Add “Sample Packs” as a new collection.
	 *
	 * Starts with "Add" in English on purpose, so it reads as the action
	 * it is and is never mistaken for a result.
	 *
	 * @param {string} typed What is in the box.
	 * @param {string} noun  What the field calls a new item.
	 * @return {string} The label.
	 */
	addLabel( typed, noun ) {
		return t( 'addAsNew', 'Add “%1$s” as a new %2$s' )
			.replace( '%1$s', String( typed ).trim() )
			.replace( '%2$s', noun || t( 'newItemNoun', 'item' ) );
	},

	/**
	 * Put the select's options in the order its chips are now in.
	 *
	 * The chips are only a picture of what is chosen; the select is what
	 * gets posted, and a multiple select posts in option order rather than
	 * in the order things were picked. Dragging a chip therefore has to
	 * move the option too, or the new order is lost on save.
	 *
	 * Unselected options are left where they are: they are not part of the
	 * value and moving them would churn the DOM for nothing.
	 *
	 * @param {Element} chips The chip list.
	 */
	syncOrder( chips ) {
		const wrap =
			chips.closest( '.field-kit__combobox' ) || chips.parentElement;
		const select = wrap ? wrap.querySelector( 'select' ) : null;

		if ( ! select ) {
			return;
		}

		const order = Array.prototype.slice
			.call( chips.querySelectorAll( '.field-kit__combobox-chip' ) )
			.map( function ( chip ) {
				return chip.dataset.value;
			} );

		order.forEach( function ( value ) {
			const option = Array.prototype.slice
				.call( select.options )
				.filter( function ( candidate ) {
					return candidate.value === value;
				} )[ 0 ];

			if ( option ) {
				select.appendChild( option );
			}
		} );

		select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	},

	/**
	 * Upgrade every enhanced select within a root.
	 *
	 * @param {Element} root Container.
	 */
	init( root ) {
		// Two kinds, one control. A relational field searches a server;
		// an enhanced select filters the options it was already given.
		// The enhanced ones used to be rendered with the class and no
		// behaviour behind it at all, so a `select2` field was a plain
		// dropdown with a promise attached.
		const selector =
			'select.field-kit__relational, select.field-kit__select--enhanced';

		ours( root, selector ).forEach( function ( select ) {
			if ( select.dataset.fkBound ) {
				return;
			}

			select.dataset.fkBound = '1';
			Combobox.build( select );
		} );
	},

	/**
	 * Build the combobox around one select.
	 *
	 * @param {HTMLSelectElement} select The select.
	 */
	build( select ) {
		const wrap = document.createElement( 'div' );
		wrap.className = 'field-kit__combobox';

		const input = document.createElement( 'input' );
		const listId = select.id + '__listbox';

		input.type = 'text';
		input.className = 'regular-text field-kit__combobox-input';
		input.id = select.id + '__combobox';
		input.setAttribute( 'role', 'combobox' );
		input.setAttribute( 'aria-expanded', 'false' );
		input.setAttribute( 'aria-controls', listId );
		input.setAttribute( 'aria-autocomplete', 'list' );
		input.setAttribute( 'autocomplete', 'off' );

		// The visible control takes over the label and the description the
		// server associated with the select, or the combobox would be
		// announced as unlabelled while the labelled element is hidden.
		const label = select.ownerDocument.querySelector(
			'label[for="' + window.CSS.escape( select.id ) + '"]'
		);

		if ( label ) {
			label.setAttribute( 'for', input.id );
		}

		if ( select.hasAttribute( 'aria-describedby' ) ) {
			input.setAttribute(
				'aria-describedby',
				select.getAttribute( 'aria-describedby' )
			);
		}

		if ( select.hasAttribute( 'aria-required' ) ) {
			input.setAttribute(
				'aria-required',
				select.getAttribute( 'aria-required' )
			);
		}

		const list = document.createElement( 'ul' );
		list.className = 'field-kit__combobox-list';
		list.id = listId;
		list.setAttribute( 'role', 'listbox' );
		list.hidden = true;

		const status = document.createElement( 'span' );
		status.className = 'screen-reader-text';
		status.setAttribute( 'aria-live', 'polite' );

		const multiple = select.multiple;

		// A multiple select shows what is chosen as removable chips under
		// the input — the input is left free to keep searching rather
		// than holding one of the values.
		let chips = null;

		if ( multiple ) {
			chips = document.createElement( 'ul' );
			chips.className = 'field-kit__combobox-chips';

			// Set here rather than when the chips are first drawn:
			// Reorder.init() selects on this attribute and may have run
			// already by then.
			if ( 'true' === select.getAttribute( 'data-sortable' ) ) {
				chips.setAttribute( 'data-sortable', 'true' );
			}
		}

		const selected = multiple
			? null
			: select.options[ select.selectedIndex ];
		input.value = selected && selected.value ? selected.text : '';

		// A single control that is allowed to hold nothing gets a way
		// back to nothing. A multiple one does not need it — each chip
		// carries its own remove — and a required one has nothing valid
		// to be cleared to.
		// A closed dropdown with nothing chosen must still say what it is
		// for. The select's own empty option carries that text — it is
		// what a native select shows — so the placeholder comes from
		// there rather than from a second piece of configuration.
		const empty = Array.prototype.slice
			.call( select.options )
			.filter( function ( option ) {
				return '' === option.value;
			} )[ 0 ];

		// A cross clears back to the empty option. A select with none --
		// a required one, or one whose every option is a real choice --
		// has nothing to clear to, and the cross was a button that
		// undid a choice into a state the field cannot hold.
		const clearable =
			! multiple &&
			'true' !== select.getAttribute( 'aria-required' ) &&
			! select.required &&
			!! empty;
		let clear = null;

		if ( clearable ) {
			// The wrapper says so, so the input can hold room for the
			// button whether or not it is showing — otherwise the text
			// jumps sideways the moment something is chosen.
			wrap.classList.add( 'field-kit__combobox--clearable' );

			clear = document.createElement( 'button' );
			clear.type = 'button';
			clear.className = 'field-kit__combobox-clear';
			clear.innerHTML = icon( 'close' );
		}

		input.placeholder =
			select.dataset.placeholder || ( empty ? empty.text : '' );

		// The select carries the value and stays in the form; it is
		// removed from the tab order rather than hidden, so it is not
		// reachable twice.
		select.setAttribute( 'tabindex', '-1' );
		select.setAttribute( 'aria-hidden', 'true' );
		select.classList.add( 'screen-reader-text' );

		select.parentNode.insertBefore( wrap, select );

		wrap.appendChild( input );

		if ( clear ) {
			wrap.appendChild( clear );
		}

		wrap.appendChild( select );
		wrap.appendChild( list );
		wrap.appendChild( status );

		// What is chosen goes under the box, not over it. Above, every
		// choice pushed the box down the page while it was being typed
		// into; below, the box stays where it is and the list of what
		// has been picked grows away from it -- search first, then what
		// the search has found so far.
		if ( chips ) {
			wrap.appendChild( chips );
		}

		Combobox.bind( select, input, list, status, chips, clear );
	},

	/**
	 * Wire the behaviour.
	 *
	 * @param {HTMLSelectElement} select The select.
	 * @param {HTMLInputElement}  input  The visible input.
	 * @param {HTMLElement}       list   The listbox.
	 * @param {HTMLElement}       status The live region.
	 * @param {HTMLElement|null}  chips  Chip list, for a multiple select.
	 * @param {HTMLElement|null}  clear  The clear button, for a single select that may be emptied.
	 */
	bind( select, input, list, status, chips, clear ) {
		let active = -1;
		let results = [];
		let requests = 0;
		let timer = null;
		const remote = !! select.dataset.searchEndpoint;
		const multiple = select.multiple;

		// Whether what was typed can be made into a real item -- a post,
		// a term, a user -- through the create route, and chosen by the
		// id that comes back.
		const createEndpoint = select.dataset.createEndpoint || '';
		const adds = remote && '' !== createEndpoint;
		let adding = false;

		// A refusal from the create route, shown at the top of the list
		// until something else is typed, and the text it refused, so the
		// same row is not offered straight back.
		let notice = '';
		let refused = '';

		// Whether a value the list does not offer can be added anyway.
		// This is what makes a control a tag input rather than a picker:
		// the same combobox, allowed to invent an option. A field that
		// makes real items does not also keep the raw text; the server
		// says so too, and this is the same rule.
		const creatable = ! adds && 'true' === select.dataset.creatable;

		// A local list needs no minimum: there is nothing to spare by
		// waiting, and an empty query means "show me everything".
		const minChars = remote
			? parseInt( select.dataset.minChars || '0', 10 )
			: 0;

		/**
		 * Every option the select carries, as search results.
		 *
		 * Read fresh each time rather than cached at build: a select's
		 * options can be rewritten by conditional logic or by another
		 * field, and a stale copy would offer choices that no longer
		 * exist.
		 *
		 * @return {Array} Results.
		 */
		function options() {
			return Array.prototype.slice
				.call( select.options )
				.filter( function ( option ) {
					return '' !== option.value;
				} )
				.map( function ( option ) {
					// data-icon, where a type has one to offer. The rows
					// are read off the real options, so anything hung on
					// them reaches the list without a second source.
					return {
						id: option.value,
						text: option.text,
						icon: option.dataset.icon || '',
					};
				} );
		}

		/**
		 * Filter the local options by a term.
		 *
		 * @param {string} term Search term.
		 */
		function filter( term ) {
			const needle = term.toLowerCase().trim();

			results = options().filter( function ( result ) {
				return (
					'' === needle ||
					result.text.toLowerCase().indexOf( needle ) > -1
				);
			} );

			render();
		}

		/**
		 * Put the list under the input, at the input's width.
		 *
		 * Measured rather than inherited. The list used to be stretched
		 * across the wrapper with left:0;right:0, so whenever anything
		 * made the wrapper wider than the input — which core does on a
		 * term screen — the list hung out past the control it belongs to.
		 * The input is the only thing that knows how wide the control is.
		 */
		function place() {
			list.style.width = input.offsetWidth + 'px';
			list.style.left = input.offsetLeft + 'px';
			list.style.top = input.offsetTop + input.offsetHeight + 'px';
		}

		function close() {
			list.hidden = true;
			list.innerHTML = '';
			input.setAttribute( 'aria-expanded', 'false' );
			input.removeAttribute( 'aria-activedescendant' );
			active = -1;
		}

		function highlight( index ) {
			const drawn = list.querySelectorAll( '[role="option"]' );

			if ( ! drawn.length ) {
				return;
			}

			active = ( index + drawn.length ) % drawn.length;

			drawn.forEach( function ( option, i ) {
				option.setAttribute(
					'aria-selected',
					i === active ? 'true' : 'false'
				);
			} );

			// Focus stays in the input; the active option is pointed at
			// rather than focused, which is what aria-activedescendant is
			// for and why the highlight cannot be a :focus style.
			input.setAttribute( 'aria-activedescendant', drawn[ active ].id );
			drawn[ active ].scrollIntoView( { block: 'nearest' } );
		}

		/**
		 * The option carrying a value, creating it if it is allowed to.
		 *
		 * @param {string} value The value.
		 * @param {string} text  Its label.
		 * @return {HTMLOptionElement|null} The option.
		 */
		function optionFor( value, text ) {
			const existing = Array.prototype.slice
				.call( select.options )
				.filter( function ( option ) {
					return option.value === value;
				} )[ 0 ];

			if ( existing ) {
				return existing;
			}

			const option = document.createElement( 'option' );
			option.value = value;
			option.text = text;
			select.appendChild( option );

			return option;
		}

		/**
		 * Draw the chosen values as removable chips.
		 */
		function renderChips() {
			if ( ! chips ) {
				return;
			}

			chips.innerHTML = '';

			const sortable = 'true' === select.getAttribute( 'data-sortable' );
			const chosen = Array.prototype.slice.call( select.selectedOptions );

			chosen.forEach( function ( option, position ) {
				const chip = document.createElement( 'li' );
				chip.className = 'field-kit__combobox-chip';

				// syncOrder() matches chips back to options by value.
				chip.dataset.value = option.value;

				if ( sortable ) {
					chip.appendChild(
						Combobox.handle( option.text, position, chosen.length )
					);
				}

				const label = document.createElement( 'span' );
				label.textContent = option.text;

				const remove = document.createElement( 'button' );
				remove.type = 'button';
				remove.className = 'field-kit__combobox-chip-remove';

				// Named, not just an ×: a row of identical "Remove"
				// buttons is a row nobody can tell apart by ear.
				remove.setAttribute(
					'aria-label',
					t( 'removeItem', 'Remove' ) + ': ' + option.text
				);
				remove.innerHTML = icon( 'close' );
				remove.addEventListener( 'click', function () {
					option.selected = false;

					// An invented option has nothing to go back to, so it
					// is removed rather than left in the list unselected.
					if ( 'true' === option.dataset.fkCreated ) {
						option.remove();
					}

					renderChips();
					select.dispatchEvent(
						new Event( 'change', { bubbles: true } )
					);
					input.focus();
				} );

				chip.appendChild( label );
				chip.appendChild( remove );
				chips.appendChild( chip );
			} );
		}

		/**
		 * Take a value, however it was arrived at.
		 *
		 * @param {string}  value   The value.
		 * @param {string}  text    Its label.
		 * @param {boolean} created Whether it was invented rather than chosen.
		 */
		function take( value, text, created ) {
			if ( multiple ) {
				// A limit that is only enforced on save is a limit the
				// editor discovers after losing their work.
				const max = parseInt(
					select.getAttribute( 'data-max' ) || '0',
					10
				);

				if ( max > 0 && select.selectedOptions.length >= max ) {
					announce(
						status,
						t( 'maxReached', 'You can choose at most %d.' ).replace(
							'%d',
							String( max )
						)
					);

					return;
				}

				const option = optionFor( value, text );

				if ( created ) {
					option.dataset.fkCreated = 'true';
				}

				option.selected = true;
				input.value = '';
				renderChips();
			} else if ( remote && ! created ) {
				// A searched result is not in the select yet, and the
				// previous one is no longer a valid choice.
				select.innerHTML = '';

				const single = document.createElement( 'option' );
				single.value = value;
				single.text = text;
				single.selected = true;
				select.appendChild( single );
				input.value = text;
			} else {
				optionFor( value, text ).selected = true;
				select.value = value;
				input.value = text;
			}

			select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			syncClear();
			close();
		}

		/**
		 * Show the clear button only when there is something to clear.
		 */
		function syncClear() {
			if ( clear ) {
				clear.hidden = '' === select.value;
			}
		}

		/**
		 * Put the control back to holding nothing.
		 *
		 * Emptying the input is not enough: the input is a search box and
		 * the select is what posts, so the select has to be emptied too —
		 * and for a remote control the option has to go, since it was
		 * only ever there to carry the current choice.
		 */
		function clearValue() {
			if ( remote ) {
				select.innerHTML = '';
			} else {
				select.value = '';
			}

			input.value = '';
			syncClear();
			select.dispatchEvent( new Event( 'change', { bubbles: true } ) );

			// Focus goes back to the control rather than being dropped on
			// the body, since the button that had it has just gone.
			input.focus();
		}

		// The select can be given a new list, and a new choice, by
		// something other than this control -- a region's options are
		// rewritten when its country changes. Whatever does it says so
		// with this event, and the box is put back in step with the
		// select: what is chosen now, or nothing.
		select.addEventListener( 'field-kit:refresh', function () {
			const chosen = multiple
				? null
				: select.options[ select.selectedIndex ];

			input.value = chosen && chosen.value ? chosen.text : '';
			renderChips();
			syncClear();
			close();
		} );

		if ( clear ) {
			syncClear();

			clear.setAttribute(
				'aria-label',
				t( 'clearSelection', 'Clear the selection' )
			);

			clear.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				clearValue();
			} );
		}

		function choose( index ) {
			const result = results[ index ];

			if ( ! result ) {
				return;
			}

			if ( result.add ) {
				add( String( result.text ) );

				return;
			}

			take( String( result.id ), result.text, false );
		}

		/**
		 * Make what was typed into a real item, then choose it.
		 *
		 * The route answers with an id and a label the way the search
		 * does, so the new item goes through take() as if it had been
		 * found: a chip, or the single choice, and nothing kept as text.
		 * One that already existed is chosen all the same and said to be.
		 *
		 * @param {string} text What was typed.
		 */
		function add( text ) {
			if ( adding ) {
				return;
			}

			// Checked before anything is made: past the limit take() would
			// refuse the new item, which would then exist and be chosen
			// by nobody.
			const max = parseInt(
				select.getAttribute( 'data-max' ) || '0',
				10
			);

			if ( multiple && max > 0 && select.selectedOptions.length >= max ) {
				announce(
					status,
					t( 'maxReached', 'You can choose at most %d.' ).replace(
						'%d',
						String( max )
					)
				);

				return;
			}

			const args = {};

			[ select.dataset.searchArgs, select.dataset.createArgs ].forEach(
				function ( json ) {
					const parsed = json ? JSON.parse( json ) : {};

					Object.keys( parsed ).forEach( function ( key ) {
						args[ key ] = parsed[ key ];
					} );
				}
			);

			adding = true;
			input.setAttribute( 'aria-busy', 'true' );
			announce(
				status,
				t( 'adding', 'Adding “%s”…' ).replace( '%s', text )
			);

			request( new URL( createEndpoint, window.location.origin ), {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce':
						select.dataset.searchNonce || config.restNonce || '',
				},
				body: JSON.stringify( {
					source: select.dataset.searchSource,
					text,
					args,
				} ),
			} )
				.then( function ( response ) {
					return response
						.json()
						.catch( function () {
							return {};
						} )
						.then( function ( data ) {
							return { ok: response.ok, data: data || {} };
						} );
				} )
				.then( function ( answer ) {
					adding = false;
					input.removeAttribute( 'aria-busy' );

					if ( answer.ok && answer.data.id ) {
						const label = String( answer.data.text || text );

						refused = '';
						notice = '';
						take( String( answer.data.id ), label, false );
						announce(
							status,
							( answer.data.existing
								? t(
										'addExisting',
										'“%s” already exists, so it has been chosen.'
									)
								: t( 'added', '“%s” added.' )
							).replace( '%s', label )
						);

						return;
					}

					refuse( text, answer.data.message );
				} )
				.catch( function () {
					adding = false;
					input.removeAttribute( 'aria-busy' );
					refuse( text, '' );
				} );
		}

		/**
		 * Say why something could not be added, without losing the list.
		 *
		 * @param {string} text    What was typed.
		 * @param {string} message The server's reason, if it gave one.
		 */
		function refuse( text, message ) {
			refused = text.trim().toLowerCase();
			notice =
				message ||
				t( 'addFailed', '“%s” could not be added.' ).replace(
					'%s',
					text
				);

			if ( input.ownerDocument.activeElement === input ) {
				render();

				return;
			}

			announce( status, notice );
		}

		/**
		 * Add whatever is typed, for a control allowed to invent values.
		 */
		function create() {
			const value = input.value.trim();

			if ( '' === value ) {
				return;
			}

			take( value, value, true );
		}

		function render() {
			list.innerHTML = '';

			// A creatable control offers what was typed as the first row,
			// so the pointer has the same way in as the keyboard.
			const typed = input.value.trim();

			// One that makes real items offers it as the last row instead:
			// after everything that already exists, which is usually what
			// was wanted, and reached with End from anywhere in the list.
			results = results.filter( function ( result ) {
				return ! result.add;
			} );

			if (
				adds &&
				! adding &&
				refused !== typed.toLowerCase() &&
				Combobox.offersAdd(
					results,
					typed,
					select.dataset.createInput || ''
				)
			) {
				results = results.concat( [
					{
						id: '',
						text: typed,
						add: true,
					},
				] );
			}

			// The create route's refusal heads the list. Not an option:
			// there is nothing to choose in it, and arrowing onto it would
			// be a stop that does nothing.
			if ( notice ) {
				const said = document.createElement( 'li' );
				said.className =
					'field-kit__combobox-option field-kit__combobox-notice';
				said.textContent = notice;
				list.appendChild( said );
			}

			if (
				creatable &&
				'' !== typed &&
				! results.some( function ( result ) {
					return (
						String( result.text ).toLowerCase() ===
						typed.toLowerCase()
					);
				} )
			) {
				results = [
					{
						id: typed,
						text: typed,
						create: true,
					},
				].concat( results );
			}

			if ( ! results.length ) {
				const empty = document.createElement( 'li' );
				empty.className = 'field-kit__combobox-option';
				empty.textContent = t( 'noResults', 'No results found.' );
				list.appendChild( empty );
				place();
				list.hidden = false;
				input.setAttribute( 'aria-expanded', 'true' );
				announce(
					status,
					notice || t( 'noResults', 'No results found.' )
				);

				return;
			}

			results.forEach( function ( result, i ) {
				const option = document.createElement( 'li' );
				option.className = 'field-kit__combobox-option';
				option.id = list.id + '__' + i;
				option.setAttribute( 'role', 'option' );
				option.setAttribute( 'aria-selected', 'false' );

				let text = result.create
					? t( 'addItem', 'Add' ) + ' “' + result.text + '”'
					: result.text;

				if ( result.add ) {
					text = Combobox.addLabel(
						result.text,
						select.dataset.createNoun || ''
					);
					option.classList.add( 'field-kit__combobox-option--add' );
				}

				// Built rather than assigned, so the label stays text and
				// the glyph is an element beside it.
				if ( result.icon ) {
					const glyph = document.createElement( 'span' );
					glyph.className = 'dashicons ' + result.icon;
					glyph.setAttribute( 'aria-hidden', 'true' );
					option.appendChild( glyph );
					option.appendChild( document.createTextNode( text ) );
					option.classList.add( 'field-kit__combobox-option--icon' );
				} else {
					option.textContent = text;
				}

				option.addEventListener( 'mousedown', function ( event ) {
					event.preventDefault();

					if ( result.create ) {
						take(
							String( result.id ),
							String( result.text ),
							true
						);

						return;
					}

					choose( i );
				} );
				list.appendChild( option );
			} );

			place();
			list.hidden = false;
			input.setAttribute( 'aria-expanded', 'true' );

			// The refusal is what has just happened, so it is what is
			// said; the count would talk over it. Otherwise an Add row is
			// not a result, and is named after the count so it is heard.
			const found = results.filter( function ( result ) {
				return ! result.add;
			} ).length;
			const offered = results.length > found;

			announce(
				status,
				notice ||
					( found
						? found +
							' ' +
							t( 'resultsAvailable', 'results available.' )
						: t( 'noResults', 'No results found.' ) ) +
						( offered
							? ' ' +
								Combobox.addLabel(
									typed,
									select.dataset.createNoun || ''
								) +
								'.'
							: '' )
			);
		}

		function search( term ) {
			const url = new URL(
				select.dataset.searchEndpoint,
				window.location.origin
			);
			const args = select.dataset.searchArgs
				? JSON.parse( select.dataset.searchArgs )
				: {};

			// Numbered, so a slow answer to "ab" cannot land after the
			// answer to "abc" and replace it.
			const ticket = ++requests;

			url.searchParams.set( 'source', select.dataset.searchSource );
			url.searchParams.set( 'q', term );

			Object.keys( args ).forEach( function ( key ) {
				url.searchParams.set( 'args[' + key + ']', args[ key ] );
			} );

			request( url, {
				credentials: 'same-origin',
				headers: {
					'X-WP-Nonce':
						select.dataset.searchNonce || config.restNonce || '',
				},
			} )
				.then( function ( response ) {
					return response.ok ? response.json() : { results: [] };
				} )
				.then( function ( data ) {
					settle( ticket, data.results || [] );
				} )
				.catch( function () {
					settle( ticket, [] );
				} );
		}

		/**
		 * Take a response, unless it is stale or the field has been left.
		 *
		 * render() opens the list, and a response arriving after the
		 * input lost focus would open it under whatever has focus now.
		 *
		 * @param {number} ticket Which request answered.
		 * @param {Array}  found  Its results.
		 */
		function settle( ticket, found ) {
			if (
				ticket !== requests ||
				input.ownerDocument.activeElement !== input
			) {
				return;
			}

			results = found;
			render();
		}

		/**
		 * Show results for the current input, from wherever they come.
		 *
		 * @param {string} term Search term.
		 */
		function open( term ) {
			if ( remote ) {
				search( term );

				return;
			}

			filter( term );
		}

		input.addEventListener( 'input', function () {
			window.clearTimeout( timer );

			// Something new typed: the last refusal was about something
			// else.
			notice = '';

			if ( input.value.length < minChars ) {
				close();

				return;
			}

			if ( ! remote ) {
				// Nothing to spare by waiting on a list already in the page.
				filter( input.value );

				return;
			}

			// Debounced, or every keystroke is a query.
			timer = window.setTimeout( function () {
				search( input.value );
			}, 250 );
		} );

		// Clicking anywhere in the control opens it, which is what a
		// dropdown does. A local list opens showing everything; a remote
		// one waits for a term it can actually search on.
		function openAll() {
			if ( ! list.hidden ) {
				close();

				return;
			}

			input.focus();

			if ( ! remote ) {
				filter( '' );

				return;
			}

			if ( input.value.length >= minChars ) {
				open( input.value );
			}
		}

		renderChips();

		// Clicking the control opens it, which is what a dropdown does.
		// The arrow is painted on the input rather than being an element
		// of its own, so there is nothing else to click.
		input.addEventListener( 'mousedown', function () {
			if ( ! list.hidden ) {
				return;
			}

			// Deferred, so the click that opened it does not also place a
			// caret and immediately close it again.
			window.setTimeout( openAll, 0 );
		} );

		input.addEventListener( 'keydown', function ( event ) {
			switch ( event.key ) {
				case 'ArrowDown':
					event.preventDefault();

					if ( list.hidden ) {
						open( remote ? input.value : '' );
					} else {
						highlight( active + 1 );
					}

					break;
				case 'ArrowUp':
					event.preventDefault();
					highlight( active - 1 );
					break;
				case 'Home':
					if ( ! list.hidden ) {
						event.preventDefault();
						highlight( 0 );
					}
					break;
				case 'End':
					if ( ! list.hidden ) {
						event.preventDefault();
						highlight( results.length - 1 );
					}
					break;
				case 'Enter':
					if ( ! list.hidden && active > -1 ) {
						event.preventDefault();
						choose( active );
					} else if ( creatable && '' !== input.value.trim() ) {
						// Enter on typed text that matched nothing is how
						// every tag control in existence adds a value, and
						// preventing the default keeps it from submitting
						// the form instead.
						event.preventDefault();
						create();
					}
					break;
				case 'Escape':
					close();
					break;
				default:
					break;
			}
		} );

		input.addEventListener( 'blur', function () {
			// Deferred so a mousedown on an option is not lost to the blur.
			window.setTimeout( close, 150 );
		} );
	},
};

export default Combobox;
