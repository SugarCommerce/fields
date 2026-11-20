/**
 * The icon field: a button that opens a dialog of icons.
 *
 * One dialog serves every icon field on the page. It is a native
 * <dialog> opened with showModal(), which puts it in the browser's top
 * layer: above a flyout panel whatever that panel's z-index, with the
 * page behind it inert, and Escape closing it rather than the panel
 * under it. It is appended to <body>, outside any panel, so a panel's
 * own focus trap -- which acts only on focus inside the panel -- leaves
 * it alone.
 *
 * Icons come a page at a time from the kit's /icons endpoint and are
 * kept by URL, so a set is fetched once a page however often the dialog
 * opens, and the browser's own cache keeps it across page loads. The
 * first page is fetched when the pointer reaches the button or focus
 * does, so by the time it is pressed the grid is usually there to draw.
 */

import { config, icon, t } from '../lib/config';
import { request } from '../lib/request';

const IconPicker = {
	/**
	 * Icons per request.
	 */
	perPage: 120,

	/**
	 * Answers by URL, as promises.
	 */
	cache: {},

	/**
	 * The dialog, built on first use.
	 */
	dialog: null,

	/**
	 * The open picker: the field, its sets, and where the grid is.
	 */
	state: null,

	/**
	 * Bind every icon field within a root.
	 *
	 * @param {Element} root Container.
	 */
	init( root ) {
		root.querySelectorAll( '.field-kit__icon-picker' ).forEach(
			function ( wrap ) {
				if ( wrap.dataset.fkBound ) {
					return;
				}

				wrap.dataset.fkBound = '1';

				const button = wrap.querySelector( '.field-kit__icon-button' );

				if ( ! button ) {
					return;
				}

				const clear = wrap.querySelector( '.field-kit__icon-clear' );

				function warm() {
					IconPicker.prefetch( wrap );
				}

				button.addEventListener( 'pointerenter', warm );
				button.addEventListener( 'focus', warm );
				button.addEventListener( 'click', function () {
					IconPicker.open( wrap );
				} );

				if ( clear ) {
					clear.addEventListener( 'click', function () {
						IconPicker.write( wrap, null );
						button.focus();
					} );
				}
			}
		);
	},

	/**
	 * The sets a field offers, as the server described them.
	 *
	 * @param {Element} wrap The field.
	 * @return {Array<Object>} Sets.
	 */
	sets( wrap ) {
		try {
			return JSON.parse( wrap.dataset.sets || '[]' );
		} catch {
			return [];
		}
	},

	/**
	 * The set a field's picker opens on: its icon's, or the first.
	 *
	 * @param {Element}       wrap The field.
	 * @param {Array<Object>} sets Its sets.
	 * @return {Object|null} A set.
	 */
	initial( wrap, sets ) {
		const input = wrap.querySelector( '.field-kit__icon-value' );
		const current =
			input && input.value ? input.value.split( ':' )[ 0 ] : '';

		return (
			sets.filter( function ( set ) {
				return set.id === current;
			} )[ 0 ] ||
			sets[ 0 ] ||
			null
		);
	},

	/**
	 * Fetch a page of a set, once.
	 *
	 * The set's version is in the URL, so a set that changes is a new
	 * address and the browser's cached copy of the old one is never
	 * served for it.
	 *
	 * @param {Element} wrap   The field, for its endpoint.
	 * @param {Object}  set    The set.
	 * @param {string}  search What to look for.
	 * @param {number}  page   One-based.
	 * @return {Promise<Object>} The page.
	 */
	fetch( wrap, set, search, page ) {
		const endpoint =
			wrap.dataset.endpoint ||
			( config.restUrl ? config.restUrl + 'icons' : '' );
		let url;

		try {
			url = new URL( endpoint, window.location.origin );
		} catch ( error ) {
			return Promise.reject( error );
		}

		url.searchParams.set( 'set', set.id );
		url.searchParams.set( 'search', search );
		url.searchParams.set( 'page', String( page ) );
		url.searchParams.set( 'per_page', String( IconPicker.perPage ) );
		url.searchParams.set( 'v', set.version || '' );

		const key = url.toString();

		if ( ! IconPicker.cache[ key ] ) {
			IconPicker.cache[ key ] = request( key, {
				credentials: 'same-origin',
				headers: {
					'X-WP-Nonce': wrap.dataset.nonce || config.restNonce || '',
				},
			} ).then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( String( response.status ) );
				}

				return response.json();
			} );

			// A failure is not kept: the next try asks again.
			IconPicker.cache[ key ].catch( function () {
				delete IconPicker.cache[ key ];
			} );
		}

		return IconPicker.cache[ key ];
	},

	/**
	 * Fetch the first page a field would open on, and draw nothing.
	 *
	 * @param {Element} wrap The field.
	 */
	prefetch( wrap ) {
		const set = IconPicker.initial( wrap, IconPicker.sets( wrap ) );

		if ( set && typeof fetch === 'function' ) {
			IconPicker.fetch( wrap, set, '', 1 ).catch( function () {} );
		}
	},

	/**
	 * Build the dialog, once per page.
	 *
	 * @return {Element} The dialog.
	 */
	build() {
		if ( IconPicker.dialog ) {
			return IconPicker.dialog;
		}

		const dialog = document.createElement( 'dialog' );

		dialog.className = 'field-kit__icon-dialog';
		dialog.setAttribute( 'role', 'dialog' );
		dialog.setAttribute( 'aria-modal', 'true' );
		dialog.setAttribute( 'aria-labelledby', 'field-kit-icon-title' );
		dialog.innerHTML =
			'<div class="field-kit__icon-dialog-header">' +
			'<h2 class="field-kit__icon-dialog-title" id="field-kit-icon-title"></h2>' +
			'<button type="button" class="field-kit__icon-dialog-close">' +
			icon( 'close' ) +
			'</button>' +
			'</div>' +
			'<div class="field-kit__icon-dialog-tools">' +
			'<label class="screen-reader-text" for="field-kit-icon-search"></label>' +
			'<input type="search" class="field-kit__icon-search" id="field-kit-icon-search" autocomplete="off" spellcheck="false" aria-controls="field-kit-icon-grid" />' +
			'<div class="field-kit__icon-tabs" role="tablist"></div>' +
			'</div>' +
			'<div class="field-kit__icon-results" role="tabpanel">' +
			'<div class="field-kit__icon-grid" id="field-kit-icon-grid" role="listbox"></div>' +
			'<p class="field-kit__icon-empty" hidden></p>' +
			'<div class="field-kit__icon-sentinel" aria-hidden="true"></div>' +
			'</div>' +
			'<div class="field-kit__icon-dialog-footer">' +
			'<p class="field-kit__icon-status" role="status"></p>' +
			'<p class="field-kit__icon-license"></p>' +
			'<button type="button" class="button field-kit__icon-none"></button>' +
			'</div>';

		dialog.querySelector( '.field-kit__icon-dialog-title' ).textContent = t(
			'iconTitle',
			'Choose an icon'
		);
		dialog
			.querySelector( '.field-kit__icon-dialog-close' )
			.setAttribute( 'aria-label', t( 'iconClose', 'Close' ) );
		dialog.querySelector( 'label' ).textContent = t(
			'iconSearch',
			'Search icons'
		);
		dialog
			.querySelector( '.field-kit__icon-search' )
			.setAttribute( 'placeholder', t( 'iconSearch', 'Search icons' ) );
		dialog
			.querySelector( '.field-kit__icon-tabs' )
			.setAttribute( 'aria-label', t( 'iconSets', 'Icon sets' ) );
		dialog
			.querySelector( '.field-kit__icon-grid' )
			.setAttribute( 'aria-label', t( 'iconGrid', 'Icons' ) );
		dialog.querySelector( '.field-kit__icon-none' ).textContent = t(
			'iconNone',
			'No icon'
		);

		document.body.appendChild( dialog );
		IconPicker.dialog = dialog;
		IconPicker.bindDialog( dialog );

		return dialog;
	},

	/**
	 * Wire the dialog's own controls. Once, when it is built.
	 *
	 * @param {Element} dialog The dialog.
	 */
	bindDialog( dialog ) {
		const search = dialog.querySelector( '.field-kit__icon-search' );
		const grid = dialog.querySelector( '.field-kit__icon-grid' );
		const tabs = dialog.querySelector( '.field-kit__icon-tabs' );
		const results = dialog.querySelector( '.field-kit__icon-results' );
		let timer = null;

		dialog
			.querySelector( '.field-kit__icon-dialog-close' )
			.addEventListener( 'click', function () {
				IconPicker.close();
			} );

		dialog
			.querySelector( '.field-kit__icon-none' )
			.addEventListener( 'click', function () {
				if ( IconPicker.state ) {
					IconPicker.write( IconPicker.state.wrap, null );
				}

				IconPicker.close();
			} );

		// A press on the backdrop is a press on the dialog itself.
		dialog.addEventListener( 'mousedown', function ( event ) {
			if ( event.target === dialog ) {
				IconPicker.close();
			}
		} );

		// Escape is the dialog's alone: a flyout under it must not
		// close in the same keypress.
		dialog.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				event.preventDefault();
				event.stopPropagation();
				IconPicker.close();
			}
		} );

		// Closed some other way -- the browser's own Escape, say -- is
		// still closed: focus goes back to the field's button.
		dialog.addEventListener( 'close', function () {
			IconPicker.closed();
		} );

		search.addEventListener( 'input', function () {
			window.clearTimeout( timer );

			// Debounced: a word typed is one request, not one a letter.
			timer = window.setTimeout( function () {
				if ( IconPicker.state ) {
					IconPicker.state.seeking = false;
					IconPicker.state.search = search.value.trim();
					IconPicker.load( 1 );
				}
			}, 150 );
		} );

		search.addEventListener( 'keydown', function ( event ) {
			let first;

			if ( 'ArrowDown' === event.key ) {
				first =
					grid.querySelector( '[aria-selected="true"]' ) ||
					grid.querySelector( '[role="option"]' );

				if ( first ) {
					event.preventDefault();
					IconPicker.focus( first );
				}
			}

			if ( 'Enter' === event.key ) {
				// Enter in the box chooses the best match, which is
				// what somebody who typed "cart" and pressed it meant.
				event.preventDefault();
				first = grid.querySelector( '[role="option"]' );

				if ( first ) {
					IconPicker.choose( first );
				}
			}
		} );

		tabs.addEventListener( 'click', function ( event ) {
			const tab = event.target.closest( '[role="tab"]' );

			if ( tab ) {
				IconPicker.select( tab.dataset.set );
			}
		} );

		// The tab list is one stop, and the arrows move along it.
		tabs.addEventListener( 'keydown', function ( event ) {
			const all = Array.prototype.slice.call(
				tabs.querySelectorAll( '[role="tab"]' )
			);
			const at = all.indexOf( tabs.ownerDocument.activeElement );
			let next = null;

			if ( 'ArrowRight' === event.key || 'ArrowLeft' === event.key ) {
				next =
					all[
						( at +
							( 'ArrowRight' === event.key
								? 1
								: all.length - 1 ) ) %
							all.length
					];
			} else if ( 'Home' === event.key ) {
				next = all[ 0 ];
			} else if ( 'End' === event.key ) {
				next = all[ all.length - 1 ];
			}

			if ( next ) {
				event.preventDefault();
				next.focus();
				IconPicker.select( next.dataset.set );
			}
		} );

		grid.addEventListener( 'click', function ( event ) {
			const option = event.target.closest( '[role="option"]' );

			if ( option ) {
				IconPicker.choose( option );
			}
		} );

		grid.addEventListener( 'keydown', function ( event ) {
			IconPicker.navigate( event );
		} );

		// The next page, when the end of this one scrolls into view.
		if ( window.IntersectionObserver ) {
			new window.IntersectionObserver(
				function ( entries ) {
					const state = IconPicker.state;

					if (
						entries[ 0 ].isIntersecting &&
						state &&
						! state.loading &&
						state.page < state.pages
					) {
						IconPicker.load( state.page + 1 );
					}
				},
				{ root: results, rootMargin: '200px' }
			).observe( dialog.querySelector( '.field-kit__icon-sentinel' ) );
		}
	},

	/**
	 * Open the dialog for a field.
	 *
	 * @param {Element} wrap The field.
	 */
	open( wrap ) {
		const sets = IconPicker.sets( wrap );
		const set = IconPicker.initial( wrap, sets );

		if ( ! set ) {
			return;
		}

		if ( window.performance && window.performance.mark ) {
			window.performance.mark( 'field-kit:icons-open' );
		}

		const dialog = IconPicker.build();
		const search = dialog.querySelector( '.field-kit__icon-search' );
		const input = wrap.querySelector( '.field-kit__icon-value' );

		IconPicker.state = {
			wrap,
			sets,
			set,
			search: '',
			page: 0,
			pages: 0,
			loading: false,
			ticket: 0,
			value: input ? input.value : '',

			// Opened on a chosen icon, the grid goes on loading until
			// it is drawn, and scrolls to it.
			seeking: !! ( input && input.value ),
		};

		search.value = '';
		IconPicker.tabs();
		IconPicker.expanded( wrap, true );

		if ( typeof dialog.showModal === 'function' ) {
			dialog.showModal();
		} else {
			dialog.setAttribute( 'open', '' );
		}

		IconPicker.revealTab();

		search.focus();
		IconPicker.load( 1 );
	},

	/**
	 * Close the dialog.
	 */
	close() {
		const dialog = IconPicker.dialog;

		if ( ! dialog ) {
			return;
		}

		if ( typeof dialog.close === 'function' && dialog.open ) {
			// Fires `close`, which is where focus is put back.
			dialog.close();
		} else {
			dialog.removeAttribute( 'open' );
			IconPicker.closed();
		}
	},

	/**
	 * After the dialog has closed, however it was closed.
	 */
	closed() {
		const state = IconPicker.state;

		IconPicker.state = null;

		if ( state ) {
			IconPicker.expanded( state.wrap, false );

			const button = state.wrap.querySelector(
				'.field-kit__icon-button'
			);

			if ( button ) {
				button.focus();
			}
		}
	},

	/**
	 * Say on the field's control whether its dialog is open.
	 *
	 * @param {Element} wrap The field.
	 * @param {boolean} open Whether it is.
	 */
	expanded( wrap, open ) {
		const button = wrap.querySelector( '.field-kit__icon-button' );

		if ( button ) {
			button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		}
	},

	/**
	 * Draw the set tabs for the open field.
	 */
	tabs() {
		const state = IconPicker.state;
		const list = IconPicker.dialog.querySelector( '.field-kit__icon-tabs' );
		let html = '';

		state.sets.forEach( function ( set ) {
			const selected = set.id === state.set.id;
			let style = '';

			if ( 'outline' === set.style ) {
				style = t( 'iconOutline', 'Outline' );
			} else if ( 'solid' === set.style ) {
				style = t( 'iconSolid', 'Solid' );
			}

			html +=
				'<button type="button" role="tab" class="field-kit__icon-tab" id="field-kit-icon-tab-' +
				IconPicker.escape( set.id ) +
				'"' +
				' data-set="' +
				IconPicker.escape( set.id ) +
				'"' +
				' aria-selected="' +
				( selected ? 'true' : 'false' ) +
				'"' +
				' aria-controls="field-kit-icon-grid" tabindex="' +
				( selected ? '0' : '-1' ) +
				'">' +
				IconPicker.escape( set.label ) +
				( style
					? ' <span class="field-kit__icon-tab-style">' +
						IconPicker.escape( style ) +
						'</span>'
					: '' ) +
				'</button>';
		} );

		list.innerHTML = html;
		list.hidden = state.sets.length < 2;

		IconPicker.revealTab();

		IconPicker.dialog
			.querySelector( '.field-kit__icon-results' )
			.setAttribute(
				'aria-labelledby',
				'field-kit-icon-tab-' + state.set.id
			);
		IconPicker.dialog.querySelector(
			'.field-kit__icon-license'
		).textContent = state.set.license || '';
	},

	/**
	 * Bring the chosen tab into view.
	 *
	 * On a phone the tabs are one row that scrolls sideways, and the
	 * chosen one may be off the end of it. Asked again once the dialog
	 * is open, because a closed dialog has no layout to scroll.
	 */
	revealTab() {
		const list = IconPicker.dialog.querySelector( '.field-kit__icon-tabs' );
		const current = list.querySelector( '[aria-selected="true"]' );

		if ( current && list.scrollWidth > list.clientWidth ) {
			list.scrollLeft =
				current.offsetLeft -
				list.offsetLeft -
				( list.clientWidth - current.offsetWidth ) / 2;
		}
	},

	/**
	 * Switch to another set, keeping the search.
	 *
	 * @param {string} id The set's id.
	 */
	select( id ) {
		const state = IconPicker.state;
		const set = state
			? state.sets.filter( function ( one ) {
					return one.id === id;
				} )[ 0 ]
			: null;

		if ( ! set || set.id === state.set.id ) {
			return;
		}

		state.set = set;
		state.seeking = false;
		IconPicker.tabs();

		const tab = IconPicker.dialog.querySelector(
			'[data-set="' + set.id + '"]'
		);

		if ( tab ) {
			tab.focus();
		}

		IconPicker.load( 1 );
	},

	/**
	 * Load a page into the grid: the first replaces it, the rest add.
	 *
	 * @param {number} page One-based.
	 */
	load( page ) {
		const state = IconPicker.state;
		const dialog = IconPicker.dialog;
		const grid = dialog.querySelector( '.field-kit__icon-grid' );
		const status = dialog.querySelector( '.field-kit__icon-status' );
		const empty = dialog.querySelector( '.field-kit__icon-empty' );

		// Numbered, so the answer to "car" cannot land after the answer
		// to "cart" and replace it.
		const ticket = ++state.ticket;
		const set = state.set;

		state.loading = true;

		if ( 1 === page ) {
			grid.setAttribute( 'aria-busy', 'true' );
		}

		IconPicker.fetch( state.wrap, set, state.search, page )
			.then( function ( data ) {
				if ( IconPicker.state !== state || ticket !== state.ticket ) {
					return;
				}

				state.loading = false;
				state.page = data.page || page;
				state.pages = data.pages || 0;

				const html = IconPicker.options(
					data.icons || [],
					state.value
				);

				if ( 1 === page ) {
					grid.innerHTML = html;
					grid.removeAttribute( 'aria-busy' );
					dialog.querySelector(
						'.field-kit__icon-results'
					).scrollTop = 0;

					if ( window.performance && window.performance.mark ) {
						window.performance.mark( 'field-kit:icons-painted' );
					}
				} else {
					grid.insertAdjacentHTML( 'beforeend', html );
				}

				empty.hidden = data.total > 0;
				empty.textContent =
					data.total > 0 ? '' : t( 'iconNoMatch', 'No icons match.' );

				status.textContent = IconPicker.count(
					data.total,
					!! state.search
				);

				if ( state.seeking && IconPicker.seek( state, grid ) ) {
					return;
				}

				// Short of the end and nothing to scroll: the sentinel is
				// already in view and will not cross into it again.
				if (
					state.page < state.pages &&
					grid.scrollHeight <= grid.parentNode.clientHeight + 200
				) {
					IconPicker.load( state.page + 1 );
				}
			} )
			.catch( function () {
				if ( IconPicker.state === state && ticket === state.ticket ) {
					state.loading = false;
					grid.removeAttribute( 'aria-busy' );
					status.textContent = t(
						'iconFailed',
						'The icons could not be loaded.'
					);
				}
			} );
	},

	/**
	 * Bring the chosen icon into view, loading on until it is drawn.
	 *
	 * Ten pages at most -- twelve hundred icons -- after which the grid
	 * stays at the top and a search is the faster way there.
	 *
	 * @param {Object}  state The open picker.
	 * @param {Element} grid  The grid.
	 * @return {boolean} True when another page was asked for.
	 */
	seek( state, grid ) {
		const chosen = grid.querySelector( '[aria-selected="true"]' );

		if ( chosen ) {
			state.seeking = false;
			grid.querySelectorAll( '[tabindex="0"]' ).forEach(
				function ( other ) {
					other.setAttribute( 'tabindex', '-1' );
				}
			);
			chosen.setAttribute( 'tabindex', '0' );
			chosen.scrollIntoView( { block: 'center' } );

			return false;
		}

		if (
			state.search ||
			0 !== state.value.indexOf( state.set.id + ':' ) ||
			state.page >= Math.min( state.pages, 10 )
		) {
			state.seeking = false;

			return false;
		}

		IconPicker.load( state.page + 1 );

		return true;
	},

	/**
	 * Markup for a page of icons.
	 *
	 * The SVG is the server's, already cleaned; the label is escaped
	 * here because it goes in an attribute.
	 *
	 * @param {Array<Object>} icons  From the endpoint.
	 * @param {string}        chosen The field's current value.
	 * @return {string} Markup.
	 */
	options( icons, chosen ) {
		return icons
			.map( function ( one ) {
				const label = IconPicker.escape( one.label );

				return (
					'<div role="option" class="field-kit__icon-option" tabindex="-1"' +
					' data-value="' +
					IconPicker.escape( one.value ) +
					'"' +
					' aria-selected="' +
					( one.value === chosen ? 'true' : 'false' ) +
					'"' +
					' aria-label="' +
					label +
					'" title="' +
					label +
					'">' +
					one.svg +
					'</div>'
				);
			} )
			.join( '' );
	},

	/**
	 * What the status line says about how many icons there are.
	 *
	 * A count rather than the page, so a search says how many it found
	 * and not how many are drawn so far.
	 *
	 * @param {number}  total    How many the set or the search has.
	 * @param {boolean} searched Whether a search found them.
	 * @return {string} The sentence.
	 */
	count( total, searched ) {
		const one = searched
			? t( 'iconMatch', '1 icon matches.' )
			: t( 'iconOne', '1 icon' );
		const many = searched
			? t( 'iconMatches', '%d icons match.' )
			: t( 'iconCount', '%d icons' );

		return 1 === total ? one : many.replace( '%d', String( total ) );
	},

	/**
	 * Move focus to an option, making it the grid's one tab stop.
	 *
	 * @param {Element} option The option.
	 */
	focus( option ) {
		const grid = option.parentNode;

		grid.querySelectorAll( '[tabindex="0"]' ).forEach( function ( other ) {
			other.setAttribute( 'tabindex', '-1' );
		} );

		option.setAttribute( 'tabindex', '0' );
		option.focus();
	},

	/**
	 * The arrow keys, Home, End, Enter and Space, in the grid.
	 *
	 * Up and down move a row, which is however many icons fit across
	 * at this width -- counted from where they are drawn rather than
	 * assumed. Up from the top row goes back to the search box.
	 *
	 * @param {KeyboardEvent} event The key press.
	 */
	navigate( event ) {
		const option = event.target.closest( '[role="option"]' );

		if ( ! option ) {
			return;
		}

		const grid = option.parentNode;
		const all = Array.prototype.slice.call(
			grid.querySelectorAll( '[role="option"]' )
		);
		const at = all.indexOf( option );
		const columns =
			all.filter( function ( one ) {
				return one.offsetTop === all[ 0 ].offsetTop;
			} ).length || 1;
		let next = null;

		switch ( event.key ) {
			case 'ArrowRight':
				next = all[ at + 1 ];
				break;
			case 'ArrowLeft':
				next = all[ at - 1 ];
				break;
			case 'ArrowDown':
				next = all[ Math.min( at + columns, all.length - 1 ) ];
				break;
			case 'ArrowUp':
				if ( at < columns ) {
					event.preventDefault();
					IconPicker.dialog
						.querySelector( '.field-kit__icon-search' )
						.focus();

					return;
				}

				next = all[ at - columns ];
				break;
			case 'Home':
				next = all[ 0 ];
				break;
			case 'End':
				next = all[ all.length - 1 ];
				break;
			case 'Enter':
			case ' ':
				event.preventDefault();
				IconPicker.choose( option );

				return;
			default:
				return;
		}

		event.preventDefault();

		if ( next ) {
			IconPicker.focus( next );
			next.scrollIntoView( { block: 'nearest' } );
		}
	},

	/**
	 * Choose an icon from the grid, and close.
	 *
	 * @param {Element} option The option.
	 */
	choose( option ) {
		if ( ! IconPicker.state ) {
			return;
		}

		IconPicker.write( IconPicker.state.wrap, {
			value: option.dataset.value,
			label: option.getAttribute( 'aria-label' ) || '',
			svg: option.innerHTML,
		} );

		IconPicker.close();
	},

	/**
	 * Write a choice -- or none -- to a field and its button.
	 *
	 * The hidden input announces an `input` and a `change`, so a
	 * conditional field and a panel's unsaved-changes check hear it.
	 *
	 * @param {Element}     wrap   The field.
	 * @param {Object|null} choice `{ value, label, svg }`, or null to clear.
	 */
	write( wrap, choice ) {
		const input = wrap.querySelector( '.field-kit__icon-value' );

		if ( ! input ) {
			return;
		}

		const glyph = wrap.querySelector( '.field-kit__icon-glyph' );
		const name = wrap.querySelector( '.field-kit__icon-name' );
		const clear = wrap.querySelector( '.field-kit__icon-clear' );

		input.value = choice ? choice.value : '';
		wrap.dataset.empty = choice ? 'false' : 'true';

		if ( glyph ) {
			glyph.innerHTML = choice ? choice.svg : '';
		}

		if ( name ) {
			name.textContent = choice
				? choice.label
				: wrap.dataset.placeholder ||
					t( 'iconTitle', 'Choose an icon' );
		}

		if ( clear ) {
			clear.hidden = ! choice;
		}

		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	},

	/**
	 * Escape text for HTML, attributes included.
	 *
	 * @param {string} text Text.
	 * @return {string} Escaped.
	 */
	escape( text ) {
		return String( null === text || undefined === text ? '' : text )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	},
};

export default IconPicker;
