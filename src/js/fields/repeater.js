/**
 * Repeaters and flexible content: rows added from a template, duplicated,
 * folded and removed, with their names and limits kept right.
 */

import { t } from '../lib/config';
import { announce } from '../lib/dom';
import { destroy, init } from '../lib/lifecycle';
import Reorder from './reorder';

const Repeater = {
	/**
	 * Bind every repeater within a root.
	 *
	 * @param {Element} root Container.
	 */
	init( root ) {
		root.querySelectorAll( '.field-kit__repeater' ).forEach(
			function ( wrap ) {
				if ( wrap.dataset.fkBound ) {
					return;
				}

				wrap.dataset.fkBound = '1';

				wrap.addEventListener( 'click', function ( event ) {
					const addButton = event.target.closest(
						'.field-kit__repeater-add'
					);

					// This repeater's own button. A click inside a repeater
					// nested in one of the rows bubbles up to this listener
					// as well, and without the check "Add a file" inside a
					// price also added a row to the list of prices.
					if ( addButton && Repeater.owns( wrap, addButton ) ) {
						event.preventDefault();

						// Flexible content has a template per layout: the
						// button names one, or the list beside it does.
						const picker = Repeater.own(
							wrap,
							'.field-kit__flexible-layout'
						);

						Repeater.add(
							wrap,
							addButton.dataset.layout ||
								( picker ? picker.value : '' )
						);
					}

					const duplicate = event.target.closest(
						'.field-kit__repeater-duplicate'
					);

					if ( duplicate && Repeater.owns( wrap, duplicate ) ) {
						event.preventDefault();
						Repeater.duplicate(
							wrap,
							duplicate.closest( '.field-kit__repeater-row' )
						);
					}

					const remove = event.target.closest(
						'.field-kit__repeater-remove'
					);

					if ( remove && Repeater.owns( wrap, remove ) ) {
						event.preventDefault();
						Repeater.remove(
							wrap,
							remove.closest( '.field-kit__repeater-row' )
						);
					}

					// The whole header, not just the chevron on it. A title
					// that says which row this is, next to a control that
					// opens it, and only the control opening it, is a target
					// the width of an icon on a strip the width of the panel.
					const header = event.target.closest(
						'.field-kit__repeater-header'
					);

					if (
						header &&
						Repeater.owns( wrap, header ) &&
						! event.target.closest( '.field-kit__repeater-actions' )
					) {
						event.preventDefault();
						Repeater.toggle(
							header.closest( '.field-kit__repeater-row' )
						);
					}
				} );

				// A collapsed row is known by its title, so the title has to
				// follow what is typed into the field it comes from --
				// otherwise renaming a tier and folding it away shows the
				// name it used to have until the page is reloaded.
				// A flexible field's layouts are each titled by a field of
				// their own, so its rows carry the key.
				const key = wrap.dataset.rowTitle;

				if ( key || wrap.classList.contains( 'field-kit__flexible' ) ) {
					wrap.addEventListener( 'input', function ( event ) {
						const row = event.target.closest(
							'.field-kit__repeater-row'
						);
						const own = row ? row.dataset.rowTitle || key : '';

						if (
							! row ||
							! own ||
							! Repeater.owns( wrap, row ) ||
							! Repeater.namesField( event.target, own )
						) {
							return;
						}

						Repeater.retitle( row, event.target.value );
					} );
				}

				// A layout's picker offers only what there is room for.
				const picker = Repeater.own(
					wrap,
					'.field-kit__flexible-layout'
				);

				if ( picker ) {
					picker.addEventListener( 'change', function () {
						Repeater.limits( wrap );
					} );
				}

				Repeater.limits( wrap );
			}
		);
	},

	/**
	 * Turn a flexible field's buttons off where a limit is reached.
	 *
	 * A layout's Add at its maximum, every Add at the field's, and a
	 * row's Remove where taking it out would leave its layout -- or the
	 * field -- short of a minimum. Off rather than silently ignored, so
	 * nobody presses a button that does nothing.
	 *
	 * @param {Element} wrap The repeater.
	 */
	limits( wrap ) {
		if ( ! wrap.classList.contains( 'field-kit__flexible' ) ) {
			return;
		}

		const list = Repeater.own( wrap, '.field-kit__repeater-rows' );

		if ( ! list ) {
			return;
		}

		const rows = Array.prototype.slice.call( list.children );
		const counts = {};

		rows.forEach( function ( row ) {
			counts[ row.dataset.layout ] =
				( counts[ row.dataset.layout ] || 0 ) + 1;
		} );

		const max = parseInt( wrap.dataset.maxRows || '0', 10 );
		const min = parseInt( wrap.dataset.minRows || '0', 10 );
		const full = max > 0 && rows.length >= max;
		const mins = {};

		function spent( control ) {
			const cap = parseInt( control.dataset.max || '0', 10 );

			return (
				cap > 0 &&
				( counts[ control.dataset.layout || control.value ] || 0 ) >=
					cap
			);
		}

		wrap.querySelectorAll(
			'.field-kit__repeater-add[data-layout], .field-kit__flexible-layout option'
		).forEach( function ( control ) {
			if ( ! Repeater.owns( wrap, control ) ) {
				return;
			}

			mins[ control.dataset.layout || control.value ] = parseInt(
				control.dataset.min || '0',
				10
			);
			control.disabled = full || spent( control );
		} );

		const picker = Repeater.own( wrap, '.field-kit__flexible-layout' );
		const single = Repeater.own(
			wrap,
			'.field-kit__repeater-add:not([data-layout])'
		);

		if ( picker && single ) {
			let chosen = picker.options[ picker.selectedIndex ];

			// The list moves off a layout that has just filled up.
			if ( chosen && chosen.disabled ) {
				const open = Array.prototype.find.call(
					picker.options,
					function ( option ) {
						return ! option.disabled;
					}
				);

				if ( open ) {
					picker.value = open.value;
					chosen = open;
				}
			}

			single.disabled = full || ! chosen || chosen.disabled;
		}

		rows.forEach( function ( row ) {
			const remove = row.querySelector(
				'.field-kit__repeater-actions .field-kit__repeater-remove'
			);
			const duplicate = row.querySelector(
				'.field-kit__repeater-actions .field-kit__repeater-duplicate'
			);
			const layoutMin = mins[ row.dataset.layout ] || 0;

			if ( remove ) {
				remove.disabled =
					( min > 0 && rows.length <= min ) ||
					( layoutMin > 0 &&
						( counts[ row.dataset.layout ] || 0 ) <= layoutMin );
			}

			if ( duplicate && undefined !== mins[ row.dataset.layout ] ) {
				const control = wrap.querySelector(
					'.field-kit__repeater-add[data-layout="' +
						window.CSS.escape( row.dataset.layout ) +
						'"], .field-kit__flexible-layout option[value="' +
						window.CSS.escape( row.dataset.layout ) +
						'"]'
				);

				duplicate.disabled = full || ( !! control && spent( control ) );
			}
		} );
	},

	/**
	 * Whether an element belongs to this repeater rather than to one
	 * nested inside it.
	 *
	 * A row can hold a repeater of its own -- a price and the files it
	 * delivers. Everything in the inner one is also inside the outer
	 * one, so "inside this repeater" is true of both and says nothing
	 * about whose it is. The nearest repeater does.
	 *
	 * @param {Element} wrap    The repeater.
	 * @param {Element} element Something inside it.
	 * @return {boolean} Whether it is this repeater's own.
	 */
	owns( wrap, element ) {
		return element.closest( '.field-kit__repeater' ) === wrap;
	},

	/**
	 * This repeater's own part: its template, its list, its button.
	 *
	 * Not the first match inside it. A saved row that holds a repeater
	 * comes before this one's own template in the document, so the
	 * first template found was the nested one -- and "Add a price"
	 * added a file row to the list of prices.
	 *
	 * @param {Element} wrap     The repeater.
	 * @param {string}  selector What to look for.
	 * @return {Element|null} The first match that is this repeater's.
	 */
	own( wrap, selector ) {
		const found = wrap.querySelectorAll( selector );

		for ( let i = 0; i < found.length; i++ ) {
			if ( Repeater.owns( wrap, found[ i ] ) ) {
				return found[ i ];
			}
		}

		return null;
	},

	/**
	 * Whether a control is the one a row is titled by.
	 *
	 * Matched on the last bracketed segment of the name rather than on
	 * the id: a row's ids are scoped by index, and a nested repeater
	 * inside the row has fields of its own whose keys can collide.
	 *
	 * @param {Element} input The control.
	 * @param {string}  key   The sub-field key.
	 * @return {boolean} Whether it matches.
	 */
	namesField( input, key ) {
		const name = input.getAttribute( 'name' );

		return !! name && name.slice( -( key.length + 2 ) ) === '[' + key + ']';
	},

	/**
	 * Write a row's header text.
	 *
	 * @param {Element} row   The row.
	 * @param {string}  value What the title field holds.
	 */
	retitle( row, value ) {
		const title = row.querySelector( '.field-kit__repeater-title' );

		if ( ! title ) {
			return;
		}

		const fallback = title.dataset.fallback || '';

		title.textContent = String( value ).trim() || fallback;
	},

	/**
	 * Fold a row away, or open it.
	 *
	 * @param {Element} row The row.
	 */
	toggle( row ) {
		if ( ! row ) {
			return;
		}

		const closed = row.classList.toggle( 'is-closed' );
		const button = row.querySelector( '.field-kit__repeater-toggle' );

		if ( button ) {
			button.setAttribute( 'aria-expanded', closed ? 'false' : 'true' );
		}
	},

	/**
	 * Add a row from the server-rendered template.
	 *
	 * The template came through the same renderer as a saved row, so a new
	 * row is labelled and associated exactly like an existing one — which
	 * is not true of a row assembled from a string in script.
	 *
	 * @param {Element}      wrap   The repeater.
	 * @param {string}       layout A flexible field's layout; a plain
	 *                              repeater has one template and ignores it.
	 * @param {Element|null} after  The row to put it after; the end when
	 *                              there is none.
	 * @param {Element|null} source A row whose values it starts with.
	 * @param {boolean}      quiet  Neither focus nor announce: a row added
	 *                              as part of another's copy.
	 * @return {Element|null} The row, or null when there is no room.
	 */
	add( wrap, layout, after, source, quiet ) {
		const template = layout
			? Repeater.own(
					wrap,
					'.field-kit__repeater-template[data-layout="' +
						window.CSS.escape( layout ) +
						'"]'
				)
			: Repeater.own( wrap, '.field-kit__repeater-template' );

		const list = Repeater.own( wrap, '.field-kit__repeater-rows' );

		if ( ! template || ! list ) {
			return null;
		}

		const max = parseInt( wrap.dataset.maxRows || '0', 10 );

		if ( max > 0 && list.children.length >= max ) {
			return null;
		}

		const row = template.content.firstElementChild.cloneNode( true );

		if ( after && after.nextElementSibling ) {
			list.insertBefore( row, after.nextElementSibling );
		} else {
			list.appendChild( row );
		}

		// Through Reorder rather than reindex(): the template is rendered
		// for a row that has no position yet, so it arrives with both its
		// move buttons disabled — and the row that used to be last still
		// has its "move down" switched off. sync() renumbers and settles
		// all of that; reindex() on its own only renumbers, which left
		// every added row unable to move in either direction.
		Reorder.sync( list );

		// A copy takes its values before the modules see it, so a
		// combobox, a colour or an editor starts on them.
		if ( source ) {
			Repeater.copy( wrap, source, row );
		}

		// The new row's markup has never been past the modules, so a
		// combobox, colour picker or media button inside it is inert
		// until it has. init() is re-entrant — every module marks what it
		// has bound — so this reaches only what was just inserted.
		init( row );

		const empty = Repeater.own( wrap, '.field-kit__repeater-empty' );

		if ( empty ) {
			empty.hidden = true;
		}

		// Open, whatever the saved rows do. The template is rendered for
		// a row with nothing in it, and folding away the fields somebody
		// just asked for is the one thing adding a row must not do.
		if ( row.classList.contains( 'is-closed' ) ) {
			Repeater.toggle( row );
		}

		Repeater.limits( wrap );

		if ( quiet ) {
			return row;
		}

		// Focus into the new row, or adding one is silent and leaves a
		// keyboard user still on the button.
		const first = row.querySelector(
			'input:not([type="hidden"]), select, textarea'
		);

		if ( first ) {
			first.focus();
		}

		announce(
			Repeater.status( wrap ),
			source
				? t( 'rowDuplicated', 'Row duplicated.' )
				: t( 'rowAdded', 'Row added.' )
		);

		return row;
	},

	/**
	 * Add a copy of a row beneath it.
	 *
	 * Built from the layout's template, as an added row is, and given the
	 * row's values -- not a clone of the row, whose combobox, colour and
	 * editor are already built and would be built a second time on top.
	 *
	 * @param {Element} wrap The repeater.
	 * @param {Element} row  The row to copy.
	 * @return {Element|null} The copy.
	 */
	duplicate( wrap, row ) {
		if ( ! row ) {
			return null;
		}

		// Editors keep what is typed to themselves until asked.
		if (
			window.tinymce &&
			typeof window.tinymce.triggerSave === 'function'
		) {
			window.tinymce.triggerSave();
		}

		row.querySelectorAll( '.CodeMirror' ).forEach( function ( element ) {
			if ( element.CodeMirror ) {
				element.CodeMirror.save();
			}
		} );

		return Repeater.add( wrap, row.dataset.layout || '', row, row );
	},

	/**
	 * Give a row another row's values.
	 *
	 * Lists inside the row are given as many rows as the source's first,
	 * outer before inner, so every control of the source has a twin to
	 * take its value. Controls are paired by name below the row's own
	 * part of it, and in order where a name repeats. A preview -- a
	 * picture, a gallery -- comes across as drawn.
	 *
	 * @param {Element} wrap   The repeater.
	 * @param {Element} source The row copied.
	 * @param {Element} target The new row.
	 */
	copy( wrap, source, target ) {
		const base = wrap.dataset.fieldName || '';
		const from = base + '[' + source.dataset.index + ']';
		const to = base + '[' + target.dataset.index + ']';

		function below( name ) {
			return 0 === name.indexOf( from )
				? name.slice( from.length )
				: null;
		}

		source
			.querySelectorAll( '.field-kit__repeater[data-field-name]' )
			.forEach( function ( nested ) {
				const rest = below( nested.dataset.fieldName );
				const twin =
					null === rest
						? null
						: target.querySelector(
								'.field-kit__repeater[data-field-name="' +
									window.CSS.escape( to + rest ) +
									'"]'
							);
				const rows = Repeater.own(
					nested,
					'.field-kit__repeater-rows'
				);

				if ( ! twin || ! rows ) {
					return;
				}

				Array.prototype.forEach.call(
					rows.children,
					function ( child ) {
						Repeater.add(
							twin,
							child.dataset.layout || '',
							null,
							null,
							true
						);
					}
				);
			} );

		const seen = {};

		source.querySelectorAll( '[name]' ).forEach( function ( control ) {
			const rest = below( control.getAttribute( 'name' ) );

			if ( null === rest ) {
				return;
			}

			const nth = ( seen[ rest ] = ( seen[ rest ] || 0 ) + 1 );
			const twin = target.querySelectorAll(
				'[name="' + window.CSS.escape( to + rest ) + '"]'
			)[ nth - 1 ];

			if ( ! twin || twin.tagName !== control.tagName ) {
				return;
			}

			if ( 'SELECT' === control.tagName ) {
				// A search's options are only what is chosen, so the
				// copy may not have them yet.
				Array.prototype.forEach.call(
					control.options,
					function ( option ) {
						let match = Array.prototype.find.call(
							twin.options,
							function ( candidate ) {
								return candidate.value === option.value;
							}
						);

						if ( ! match ) {
							match = option.cloneNode( true );
							twin.appendChild( match );
						}

						match.selected = option.selected;
					}
				);

				return;
			}

			if ( 'checkbox' === control.type || 'radio' === control.type ) {
				twin.checked = control.checked;

				return;
			}

			twin.value = control.value;
		} );

		[ '.field-kit__media-preview', '.field-kit__gallery-items' ].forEach(
			function ( selector ) {
				const twins = target.querySelectorAll( selector );

				source
					.querySelectorAll( selector )
					.forEach( function ( preview, index ) {
						if ( twins[ index ] ) {
							twins[ index ].innerHTML = preview.innerHTML;
							twins[ index ].dataset.empty =
								preview.dataset.empty || 'false';
						}
					} );
			}
		);

		const title = source.querySelector( '.field-kit__repeater-title' );
		const titled = target.querySelector( '.field-kit__repeater-title' );

		if ( title && titled ) {
			titled.textContent = title.textContent;
		}
	},

	/**
	 * Remove a row.
	 *
	 * @param {Element} wrap The repeater.
	 * @param {Element} row  The row.
	 */
	remove( wrap, row ) {
		const list = row.parentNode;
		const min = parseInt( wrap.dataset.minRows || '0', 10 );
		const button = row.querySelector( '.field-kit__repeater-remove' );

		if (
			( min > 0 && list.children.length <= min ) ||
			( button && button.disabled )
		) {
			return;
		}

		const next = row.nextElementSibling || row.previousElementSibling;

		// An editor inside the row is let go of with it, or the same
		// row drawn again finds the old one's ghost.
		destroy( row );

		row.remove();

		// sync() rather than reindex(), for the same reason as add(): the
		// new first and last rows have moves that are no longer possible.
		Reorder.sync( list );
		Repeater.limits( wrap );

		const empty = Repeater.own( wrap, '.field-kit__repeater-empty' );

		if ( empty ) {
			empty.hidden = list.children.length > 0;
		}

		const target = next
			? next.querySelector(
					'input:not([type="hidden"]), select, textarea, button'
				)
			: Repeater.own( wrap, '.field-kit__repeater-add:not([disabled])' );

		if ( target ) {
			target.focus();
		}

		announce( Repeater.status( wrap ), t( 'rowRemoved', 'Row removed.' ) );
	},

	/**
	 * The repeater's own live region.
	 *
	 * Not the first `[aria-live]` inside it: on a row holding a range or
	 * a tags field that is the field's readout, and before the region
	 * existed it was the empty message -- hidden the moment a row was
	 * added, and a hidden region announces nothing.
	 *
	 * @param {Element} wrap The repeater.
	 * @return {Element|null} The region.
	 */
	status( wrap ) {
		return (
			wrap.querySelector( ':scope > .field-kit__repeater-status' ) ||
			wrap.querySelector( '[aria-live]' )
		);
	},

	/**
	 * Point everything that referred to an old id at the new one.
	 *
	 * Labels and descriptions were moved; nothing else was. A combobox
	 * names its listbox in aria-controls, a toggle its panel in
	 * data-target, a merge-tag button its modal and its editor -- and all
	 * of them were left pointing at an id that stopped existing the
	 * moment a row above them was removed.
	 *
	 * @param {Element} row  The row.
	 * @param {string}  from The old id.
	 * @param {string}  to   The new id.
	 */
	retarget( row, from, to ) {
		const escaped = window.CSS.escape( from );

		[
			'for',
			'aria-controls',
			'data-target',
			'data-modal',
			'data-editor',
			'list',
		].forEach( function ( attribute ) {
			row.querySelectorAll(
				'[' + attribute + '="' + escaped + '"]'
			).forEach( function ( element ) {
				element.setAttribute( attribute, to );
			} );
		} );

		// Space-separated lists: the one token is replaced, not the value.
		[ 'aria-describedby', 'aria-labelledby', 'aria-owns' ].forEach(
			function ( attribute ) {
				row.querySelectorAll(
					'[' + attribute + '~="' + escaped + '"]'
				).forEach( function ( element ) {
					const tokens = element
						.getAttribute( attribute )
						.split( /\s+/ )
						.map( function ( token ) {
							return token === from ? to : token;
						} );

					element.setAttribute( attribute, tokens.join( ' ' ) );
				} );
			}
		);
	},

	/**
	 * Carry a row's new name into the lists nested in it.
	 *
	 * @param {Element|DocumentFragment} root    The row, or a template's content.
	 * @param {RegExp}                   pattern The row's old name, as a prefix.
	 * @param {string}                   prefix  Its new one.
	 */
	rename( root, pattern, prefix ) {
		root.querySelectorAll(
			'.field-kit__repeater[data-field-name]'
		).forEach( function ( nested ) {
			nested.dataset.fieldName = nested.dataset.fieldName.replace(
				pattern,
				prefix
			);
		} );

		root.querySelectorAll( 'template' ).forEach( function ( template ) {
			template.content
				.querySelectorAll( '[name]' )
				.forEach( function ( input ) {
					input.setAttribute(
						'name',
						input.getAttribute( 'name' ).replace( pattern, prefix )
					);
				} );

			// And whatever is nested in the template in turn.
			Repeater.rename( template.content, pattern, prefix );
		} );
	},

	/**
	 * Renumber every row's input names and ids.
	 *
	 * Removing a middle row leaves a gap in the indexes, and duplicate ids
	 * silently break every label association in the rows below it.
	 *
	 * @param {Element} list The row list.
	 */
	reindex( list ) {
		const wrap = list.closest( '.field-kit__repeater' );

		if ( ! wrap ) {
			return;
		}

		const base = wrap.dataset.fieldName || '';

		Array.from( list.children ).forEach( function ( row, index ) {
			row.dataset.index = String( index );

			// The template is rendered at index -1, one no saved row can
			// hold, so the pattern has to admit the sign. Digits alone
			// left every added row named [-1], and two of them posted as
			// one -- the last one typed.
			const pattern = new RegExp(
				'^' +
					base.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) +
					'\\[-?\\d*\\]'
			);
			const prefix = base + '[' + index + ']';

			row.querySelectorAll( '[name]' ).forEach( function ( input ) {
				input.name = input.name.replace( pattern, prefix );
			} );

			// A list inside the row is named after the row as well, in
			// two places the loop above cannot reach: the name it keeps
			// for numbering its own rows, and the template it clones
			// them from, whose content is not part of the document.
			// Left alone, a file added to a price that had just been
			// added or moved was posted under the index the price used
			// to have -- [-1], for a new one.
			Repeater.rename( row, pattern, prefix );

			row.querySelectorAll( '[id]' ).forEach( function ( element ) {
				const id = element.id.replace( /__row-?\d+/, '' );
				const next = id + '__row' + index;

				// Move everything pointing at the old id, or the
				// association is lost the moment a row is renumbered.
				Repeater.retarget( row, element.id, next );

				element.id = next;
			} );
		} );
	},
};

export default Repeater;
