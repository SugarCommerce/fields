/**
 * SugarCommerce Fields
 *
 * One script for every field type. Vanilla apart from wp.media, which has no
 * alternative.
 *
 * Two conventions run through it.
 *
 * Configuration is resolved per build, from the id WordPress stamps on the
 * script element, not from a bare global. Two plugins each bundling a
 * Strauss-prefixed copy load two copies of this file, and a shared global
 * would leave whichever localised last owning the REST URL and nonce for
 * both.
 *
 * Every control that can be dragged can also be operated from the keyboard.
 * That is not a fallback for when the script fails — a drag-only control
 * cannot be reordered without a mouse at all, and it is the half these
 * widgets usually ship without.
 */
( function () {
	'use strict';

	/**
	 * This build's configuration.
	 */
	var config = ( function () {
		var el = document.currentScript;
		var handle = el && el.id ? el.id.replace( /-js$/, '' ) : '';
		var registry = window.SugarCommerceFields || {};

		return registry[ handle ] || window.FieldKit || {};
	} )();

	var i18n = config.i18n || {};

	/**
	 * Translate, falling back to the key's own English.
	 *
	 * @param {string} key      Message key.
	 * @param {string} fallback English text.
	 * @return {string} Message.
	 */
	function t( key, fallback ) {
		return i18n[ key ] || fallback;
	}

	/**
	 * A REST request, the way fetch() makes one.
	 *
	 * Through wp.apiFetch when the page has it, because the nonce printed
	 * with the page runs out after a day: a settings screen left open
	 * overnight then gets a 403 from every search, icon list and button.
	 * apiFetch notices the stale nonce, asks for a fresh one and sends the
	 * request again. Its answer comes back shaped like a fetch Response (ok,
	 * status, json()), so the callers read it the same either way; a body
	 * that was not JSON makes json() reject, as it would have.
	 *
	 * @param {string|URL} url  The route's full URL.
	 * @param {Object}     init As fetch's: method, headers, body.
	 * @return {Promise<Object>} A Response, or something shaped like one.
	 */
	function request( url, init ) {
		var api = window.wp && window.wp.apiFetch;

		init = init || {};

		if ( typeof api !== 'function' ) {
			return window.fetch( url, init );
		}

		return api( {
			url: String( url ),
			method: init.method || 'GET',
			headers: init.headers || {},
			body: init.body,
			parse: true
		} ).then( function ( json ) {
			return answer( true, 200, json, false );
		}, function ( error ) {
			if ( ! error || typeof error !== 'object' || error.name === 'AbortError' || error.code === 'fetch_error' || error.code === 'offline_error' ) {
				throw ( error instanceof Error ? error : new Error( ( error && error.message ) || '' ) );
			}

			return answer( false, ( error.data && error.data.status ) || 400, error, error.code === 'invalid_json' );
		} );
	}

	/**
	 * An apiFetch answer, shaped like a fetch Response.
	 *
	 * @param {boolean} ok         Whether it succeeded.
	 * @param {number}  status     The HTTP status.
	 * @param {*}       json       The decoded body.
	 * @param {boolean} unreadable Whether the body was not JSON.
	 * @return {Object} The answer.
	 */
	function answer( ok, status, json, unreadable ) {
		return {
			ok: ok,
			status: status,
			json: function () {
				return unreadable ? Promise.reject( new SyntaxError( ( json && json.message ) || '' ) ) : Promise.resolve( json );
			}
		};
	}

	/**
	 * One of the kit's own icons, as markup.
	 *
	 * Drawn in PHP (Glyphs::markup()) and handed over in the configuration,
	 * so there is no second copy of a path here.
	 *
	 * @param {string} key A key of Glyphs::DEFAULTS, such as 'close'.
	 * @return {string} SVG markup, or '' when there is none.
	 */
	function icon( key ) {
		return ( config.icons && config.icons[ key ] ) || '';
	}

	/**
	 * Put one of the kit's icons in place of another element.
	 *
	 * For a control whose icon says its state -- shown or hidden, active or
	 * not -- and changes with it.
	 *
	 * @param {Element|null} el  The icon now there.
	 * @param {string}       key A key of Glyphs::DEFAULTS.
	 * @return {Element|null} The icon now there.
	 */
	function swapIcon( el, key ) {
		var markup = icon( key );

		if ( ! el || ! markup || ! el.parentNode ) {
			return el;
		}

		var holder = document.createElement( 'template' );

		holder.innerHTML = markup;

		var next = holder.content.firstElementChild;

		el.parentNode.replaceChild( next, el );

		return next;
	}

	/**
	 * Whether a module has already bound a root, marking it if not.
	 *
	 * init() runs twice on a normal page load -- once at DOMContentLoaded and
	 * once at load, to pick up a control whose library arrived late -- and
	 * again for every repeater row added. A module that listens on the root
	 * itself, rather than marking each control it enhances, would otherwise
	 * bind a second handler each time: two copies to the clipboard, every
	 * conditional field evaluated twice per keystroke.
	 *
	 * @param {Element} root Container.
	 * @param {string}  name Module name.
	 * @return {boolean} True when the root was already bound.
	 */
	function bound( root, name ) {
		var marks = root.fieldKitBound || ( root.fieldKitBound = {} );

		if ( marks[ name ] ) {
			return true;
		}

		marks[ name ] = true;

		return false;
	}

	/**
	 * Announce a message to assistive technology.
	 *
	 * Writes into a field's own live region where it has one, so the message
	 * is associated with the control it describes rather than shouted from a
	 * page-level region.
	 *
	 * @param {Element} region Live region element.
	 * @param {string}  text   Message.
	 */
	function announce( region, text ) {
		if ( ! region ) {
			return;
		}

		// Clearing first forces a re-announcement when the text is unchanged,
		// which is what happens when the same action is repeated.
		region.textContent = '';
		window.setTimeout( function () {
			region.textContent = text;
		}, 50 );
	}

	/**
	 * A sibling control, found by its field key.
	 *
	 * Scoped to the repeater row the caller sits in, then the form, then the
	 * document: a repeater has one of each key per row, and the first match
	 * in the form would give every row the first row's value. The key is
	 * matched as a bare name or as the last segment of a bracketed one,
	 * which is how a row's inputs are named.
	 *
	 * Money reads its currency this way and region its country -- one lookup
	 * rather than two copies of the same query, free to drift apart.
	 *
	 * The sibling named like the asking control is looked for first:
	 * `address[region]` sits beside `address[country]`, a bare `region`
	 * beside a bare `country`. Matching any name that ends in `[country]`
	 * found whichever came first in the form -- in a panel with a table of
	 * rates above an address group, the region followed a rate's country.
	 *
	 * @param {Element} wrap The control asking.
	 * @param {string}  key  Field key of the sibling.
	 * @return {Element|null} The control, if there is one.
	 */
	function sibling( wrap, key ) {
		if ( ! key ) {
			return null;
		}

		var scope = wrap.closest( '.field-kit__repeater-row' ) || wrap.closest( 'form' ) || document;
		var own = typeof wrap.getAttribute === 'function' && wrap.getAttribute( 'name' ) ? wrap : wrap.querySelector( '[name]' );
		var name = own && typeof own.getAttribute === 'function' ? String( own.getAttribute( 'name' ) || '' ).replace( /\[\]$/, '' ) : '';

		if ( name ) {
			var cut = name.lastIndexOf( '[' );
			var exact = scope.querySelector( '[name="' + ( -1 === cut ? key : name.slice( 0, cut ) + '[' + key + ']' ) + '"]' );

			if ( exact ) {
				return exact;
			}
		}

		return scope.querySelector( '[name$="[' + key + ']"], [name="' + key + '"]' );
	}

	/* ====================================================================
	 * Conditional logic
	 * ================================================================= */

	var Conditions = {

		/**
		 * Bind every conditional field within a root.
		 *
		 * @param {Element} root Container.
		 */
		init: function ( root ) {
			root.querySelectorAll( '[data-conditions]' ).forEach( function ( field ) {
				Conditions.evaluate( field );
			} );

			if ( bound( root, 'Conditions' ) ) {
				return;
			}

			// One delegated listener rather than one per watched input: the
			// watched field may not exist yet inside a repeater row. And the
			// fields are looked up on each event rather than held from init:
			// a list taken then would miss every row added since and keep
			// every row removed since alive.
			//
			// field-kit:change is the kit's own signal, used where firing a
			// native change would be read by the control that wrote the value
			// as a fresh edit. See ColorPicker.
			[ 'change', 'input', 'field-kit:change' ].forEach( function ( type ) {
				root.addEventListener( type, function () {
					root.querySelectorAll( '[data-conditions]' ).forEach( function ( field ) {
						Conditions.evaluate( field );
					} );
				}, true );
			} );
		},

		/**
		 * Show or hide one field.
		 *
		 * @param {Element} field Field wrapper.
		 */
		evaluate: function ( field ) {
			var conditions;

			try {
				conditions = JSON.parse( field.getAttribute( 'data-conditions' ) );
			} catch ( e ) {
				return;
			}

			// Always a list. A map has no forEach, which is exactly how the
			// predecessor died when config was passed through unnormalised.
			if ( ! Array.isArray( conditions ) ) {
				return;
			}

			var met = conditions.every( function ( condition ) {
				return Conditions.matches( condition, Conditions.valueOf( field, condition.field ) );
			} );

			// On a settings, term or user screen a field is one cell of a
			// table row, and hiding the cell's contents leaves the row's
			// header behind — the label of a field that is not there. The row
			// is only taken when this wrapper is the row's own field: a
			// conditional field nested inside a repeater must not take the
			// whole repeater's row with it.
			var row = field.closest( 'tr' );
			var target = row && row.querySelector( '.field-kit__field' ) === field ? row : field;

			target.hidden = ! met;

			// Hidden inputs are still submitted, and a disabled one is not —
			// the server deletes a field whose conditions fail, but not
			// submitting it in the first place keeps the two agreeing.
			//
			// A region field is two controls of one name, and the one
			// standing down is hidden and disabled so that only the other
			// posts. Showing the field must not switch it back on.
			field.querySelectorAll( 'input, select, textarea' ).forEach( function ( input ) {
				input.disabled = ! met || ( input.hidden && !! input.closest( '.field-kit__region' ) );
			} );
		},

		/**
		 * Read the current value of a watched field.
		 *
		 * @param {Element} field Field wrapper doing the watching.
		 * @param {string}  key   Watched field key.
		 * @return {*} Current value.
		 */
		valueOf: function ( field, key ) {
			var scope = field.closest( '.field-kit__repeater-row' ) || field.ownerDocument;
			var target = scope.querySelector( '[data-field-key="' + CSS.escape( key ) + '"]' );

			if ( ! target ) {
				return null;
			}

			var checked = target.querySelectorAll( 'input[type="checkbox"]:checked, input[type="radio"]:checked' );

			if ( checked.length ) {
				return checked.length === 1 ? checked[ 0 ].value : Array.from( checked ).map( function ( i ) {
					return i.value;
				} );
			}

			if ( target.querySelector( 'input[type="checkbox"], input[type="radio"]' ) ) {
				return '';
			}

			// Not the box of a combobox. An enhanced select is drawn as a
			// text box to search in with the real select behind it, and
			// the box comes first: it holds "Germany" where the select
			// holds DE, and a condition is written against the value.
			var input = target.querySelector( 'input:not(.field-kit__combobox-input), select, textarea' );

			return input ? input.value : null;
		},

		/**
		 * Evaluate one condition.
		 *
		 * Comparisons are loose for the same reason they are on the server:
		 * a value read off a form is a string and config is written in PHP,
		 * so "1" has to equal 1.
		 *
		 * @param {Object} condition Condition.
		 * @param {*}      current   Current value.
		 * @return {boolean} Whether it is met.
		 */
		matches: function ( condition, current ) {
			var expected = condition.value;

			switch ( condition.operator ) {
				case '!=':
					return current != expected; // eslint-disable-line eqeqeq
				case '>':
					return parseFloat( current ) > parseFloat( expected );
				case '>=':
					return parseFloat( current ) >= parseFloat( expected );
				case '<':
					return parseFloat( current ) < parseFloat( expected );
				case '<=':
					return parseFloat( current ) <= parseFloat( expected );
				case 'in':
					return [].concat( expected ).some( function ( one ) {
						return one == current; // eslint-disable-line eqeqeq
					} );
				case 'not_in':
					return ! [].concat( expected ).some( function ( one ) {
						return one == current; // eslint-disable-line eqeqeq
					} );
				case 'contains':
					return String( current ).indexOf( String( expected ) ) !== -1;
				case 'not_contains':
					return String( current ).indexOf( String( expected ) ) === -1;
				case 'empty':
					return ! current || ! String( current ).length;
				case 'not_empty':
					return !! current && !! String( current ).length;
				default:
					return current == expected; // eslint-disable-line eqeqeq
			}
		}
	};

	/* ====================================================================
	 * Range readout
	 * ================================================================= */

	var Range = {

		/**
		 * Keep each slider's readout in step with its value.
		 *
		 * @param {Element} root Container.
		 */
		init: function ( root ) {
			root.querySelectorAll( '.field-kit__range' ).forEach( function ( wrap ) {
				var input = wrap.querySelector( 'input[type="range"]' );
				var output = wrap.querySelector( '.field-kit__range-output' );

				if ( ! input || ! output ) {
					return;
				}

				var unit = wrap.getAttribute( 'data-unit' ) || '';

				input.addEventListener( 'input', function () {
					output.textContent = input.value + unit;
				} );
			} );
		}
	};

	/* ====================================================================
	 * Toggle
	 * ================================================================= */

	var Toggle = {

		/**
		 * Keep aria-checked in step with the underlying checkbox.
		 *
		 * role="switch" makes the state aria-checked rather than the native
		 * checked state, so it has to be maintained or the control announces
		 * the wrong thing after every interaction.
		 *
		 * @param {Element} root Container.
		 */
		init: function ( root ) {
			if ( bound( root, 'Toggle' ) ) {
				return;
			}

			root.addEventListener( 'change', function ( event ) {
				var toggle = event.target.closest( '.field-kit__toggle' );

				if ( toggle ) {
					toggle.setAttribute( 'aria-checked', toggle.checked ? 'true' : 'false' );
				}
			} );
		}
	};

	/* ====================================================================
	 * Clipboard
	 * ================================================================= */

	var Clipboard = {

		/**
		 * Copy a value and say whether it worked.
		 *
		 * @param {Element} root Container.
		 */
		init: function ( root ) {
			if ( bound( root, 'Clipboard' ) ) {
				return;
			}

			root.addEventListener( 'click', function ( event ) {
				var button = event.target.closest( '.field-kit__clipboard-copy' );

				if ( ! button ) {
					return;
				}

				var wrap = button.closest( '.field-kit__clipboard' );
				var input = wrap.querySelector( '.field-kit__clipboard-value' );
				var status = wrap.querySelector( '.field-kit__clipboard-status' );
				var success = wrap.querySelector( '.field-kit__clipboard-success' );

				Clipboard.copy( input.value ).then( function ( ok ) {
					announce( status, ok ? t( 'copied', 'Copied to the clipboard.' ) : t( 'copyFailed', 'Could not copy.' ) );

					if ( ! success || ! ok ) {
						return;
					}

					// Shown, then hidden again after a moment — core does the
					// same in the media modal. aria-hidden stays on it: the
					// live region above is what announces this, and having
					// both would say it twice.
					success.classList.remove( 'hidden' );

					window.clearTimeout( success.fkTimer );

					success.fkTimer = window.setTimeout( function () {
						success.classList.add( 'hidden' );
					}, 3000 );
				} );
			} );
		},

		/**
		 * Copy text, falling back where the async API is unavailable.
		 *
		 * navigator.clipboard needs a secure context, which a local admin
		 * over plain http is not.
		 *
		 * @param {string} text Text to copy.
		 * @return {Promise<boolean>} Whether it worked.
		 */
		copy: function ( text ) {
			if ( navigator.clipboard && window.isSecureContext ) {
				return navigator.clipboard.writeText( text ).then( function () {
					return true;
				} ).catch( function () {
					return false;
				} );
			}

			var area = document.createElement( 'textarea' );
			area.value = text;
			area.setAttribute( 'readonly', '' );
			area.style.position = 'fixed';
			area.style.opacity = '0';
			document.body.appendChild( area );
			area.select();

			var ok = false;

			try {
				ok = document.execCommand( 'copy' );
			} catch ( e ) {
				ok = false;
			}

			document.body.removeChild( area );

			return Promise.resolve( ok );
		}
	};

	/**
	 * A text field with a button that fills it in.
	 *
	 * The alphabets are here rather than sent from the server, and the button
	 * names one rather than carrying its characters: a caller cannot put
	 * arbitrary text into a page and have this draw from it.
	 *
	 * Ambiguity is why these are not simply "letters and numbers" — a code
	 * gets read off a screen and typed into a box, and O/0 and I/1/l are
	 * where that goes wrong.
	 */
	var CodeGenerator = {

		alphabets: {
			alphanumeric_upper: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
			alphanumeric: 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789',
			alpha_upper: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
			hex: '0123456789ABCDEF',
			numeric: '0123456789'
		},

		/**
		 * Wire the generate buttons.
		 *
		 * @param {Element} root Container.
		 */
		init: function ( root ) {
			root.querySelectorAll( '.field-kit__code-generate' ).forEach( function ( button ) {
				// Marked, because init() is called again for every repeater
				// row and every flyout that opens, and a twice-bound button
				// generates two codes and keeps the second.
				if ( button.dataset.fkBound ) {
					return;
				}

				button.dataset.fkBound = '1';

				button.addEventListener( 'click', function () {
					var wrap = button.closest( '.field-kit__code-generator' );
					var input = wrap && wrap.querySelector( '.field-kit__code-value' );

					if ( ! input ) {
						return;
					}

					input.value = CodeGenerator.build( button.dataset );

					// So a conditional field watching this one, or anything
					// else listening, hears about it. Setting .value fires
					// nothing.
					input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
					input.dispatchEvent( new Event( 'change', { bubbles: true } ) );

					input.focus();
				} );
			} );
		},

		/**
		 * Build one code from a button's settings.
		 *
		 * @param {DOMStringMap} settings The button's data attributes.
		 * @return {string} The code.
		 */
		build: function ( settings ) {
			var alphabet = CodeGenerator.alphabets[ settings.format ] || CodeGenerator.alphabets.alphanumeric_upper;
			var length = parseInt( settings.length, 10 ) || 8;
			var segment = parseInt( settings.segmentLength, 10 ) || 0;
			var separator = settings.separator || '';
			var code = '';

			// crypto rather than Math.random: not because this has to be
			// unpredictable — it is a discount code, and anything that must
			// be should not be generated in a browser — but because
			// Math.random collides sooner than people expect at these
			// lengths, and a duplicate code is a support ticket.
			var bytes = new Uint32Array( length );
			window.crypto.getRandomValues( bytes );

			for ( var i = 0; i < length; i++ ) {
				code += alphabet.charAt( bytes[ i ] % alphabet.length );
			}

			if ( separator && segment > 0 ) {
				code = code.match( new RegExp( '.{1,' + segment + '}', 'g' ) ).join( separator );
			}

			return ( settings.prefix || '' ) + code;
		}
	};

	window.SugarCommerceFieldsModules = { Conditions: Conditions, Range: Range, Toggle: Toggle, Clipboard: Clipboard, CodeGenerator: CodeGenerator, announce: announce, bound: bound, sibling: sibling, t: t, icon: icon, swapIcon: swapIcon, config: config, request: request };
} )();

/**
 * SugarCommerce Fields — searchable relational fields.
 *
 * Implements the ARIA combobox pattern against the real <select> the server
 * rendered, rather than replacing it. The select stays the source of truth,
 * so the field still submits correctly if this never runs, and there is a
 * genuine element to mirror instead of state invented in script.
 */
( function () {
	'use strict';

	var kit = window.SugarCommerceFieldsModules;
	var t = kit.t;
	var icon = kit.icon;
	var config = kit.config;

	var Combobox = {

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
		/**
		 * A move control for a chip.
		 *
		 * Reorder.bindButtons() finds these by data-direction, so a chip gets
		 * keyboard reordering for free and the drag path is the same code.
		 *
		 * @param {string} direction 'up' or 'down'.
		 * @param {string} label     The chip's text, for the accessible name.
		 * @return {HTMLElement} The button.
		 */
		moveButton: function ( direction, label ) {
			var button = document.createElement( 'button' );

			button.type = 'button';
			button.className = 'field-kit__combobox-chip-move';
			button.dataset.direction = direction;
			button.setAttribute(
				'aria-label',
				t( 'up' === direction ? 'moveUp' : 'moveDown', 'up' === direction ? 'Move up' : 'Move down' ) + ': ' + label
			);
			button.innerHTML = icon( 'up' === direction ? 'up' : 'down' );

			return button;
		},

		syncOrder: function ( chips ) {
			var wrap = chips.closest( '.field-kit__combobox' ) || chips.parentElement;
			var select = wrap ? wrap.querySelector( 'select' ) : null;

			if ( ! select ) {
				return;
			}

			var order = Array.prototype.slice.call(
				chips.querySelectorAll( '.field-kit__combobox-chip' )
			).map( function ( chip ) {
				return chip.dataset.value;
			} );

			order.forEach( function ( value ) {
				var option = Array.prototype.slice.call( select.options ).filter( function ( candidate ) {
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
		init: function ( root ) {
			// Two kinds, one control. A relational field searches a server;
			// an enhanced select filters the options it was already given.
			// The enhanced ones used to be rendered with the class and no
			// behaviour behind it at all, so a `select2` field was a plain
			// dropdown with a promise attached.
			var selector = 'select.field-kit__relational, select.field-kit__select--enhanced';

			root.querySelectorAll( selector ).forEach( function ( select ) {
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
		build: function ( select ) {
			var wrap = document.createElement( 'div' );
			wrap.className = 'field-kit__combobox';

			var input = document.createElement( 'input' );
			var listId = select.id + '__listbox';

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
			var label = select.ownerDocument.querySelector( 'label[for="' + CSS.escape( select.id ) + '"]' );

			if ( label ) {
				label.setAttribute( 'for', input.id );
			}

			if ( select.hasAttribute( 'aria-describedby' ) ) {
				input.setAttribute( 'aria-describedby', select.getAttribute( 'aria-describedby' ) );
			}

			if ( select.hasAttribute( 'aria-required' ) ) {
				input.setAttribute( 'aria-required', select.getAttribute( 'aria-required' ) );
			}

			var list = document.createElement( 'ul' );
			list.className = 'field-kit__combobox-list';
			list.id = listId;
			list.setAttribute( 'role', 'listbox' );
			list.hidden = true;

			var status = document.createElement( 'span' );
			status.className = 'screen-reader-text';
			status.setAttribute( 'aria-live', 'polite' );

			var multiple = select.multiple;

			// A multiple select shows what is chosen as removable chips under
			// the input — the input is left free to keep searching rather
			// than holding one of the values.
			var chips = null;

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

			var selected = multiple ? null : select.options[ select.selectedIndex ];
			input.value = selected && selected.value ? selected.text : '';

			// A single control that is allowed to hold nothing gets a way
			// back to nothing. A multiple one does not need it — each chip
			// carries its own remove — and a required one has nothing valid
			// to be cleared to.
			// A closed dropdown with nothing chosen must still say what it is
			// for. The select's own empty option carries that text — it is
			// what a native select shows — so the placeholder comes from
			// there rather than from a second piece of configuration.
			var empty = Array.prototype.slice.call( select.options ).filter( function ( option ) {
				return '' === option.value;
			} )[ 0 ];

			// A cross clears back to the empty option. A select with none --
			// a required one, or one whose every option is a real choice --
			// has nothing to clear to, and the cross was a button that
			// undid a choice into a state the field cannot hold.
			var clearable = ! multiple && 'true' !== select.getAttribute( 'aria-required' ) && ! select.required && !! empty;
			var clear = null;

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

			input.placeholder = select.dataset.placeholder || ( empty ? empty.text : '' );

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
		 */
		bind: function ( select, input, list, status, chips, clear ) {
			var active = -1;
			var results = [];
			var requests = 0;
			var timer = null;
			var remote = !! select.dataset.searchEndpoint;
			var multiple = select.multiple;

			// Whether a value the list does not offer can be added anyway.
			// This is what makes a control a tag input rather than a picker:
			// the same combobox, allowed to invent an option.
			var creatable = 'true' === select.dataset.creatable;

			// A local list needs no minimum: there is nothing to spare by
			// waiting, and an empty query means "show me everything".
			var minChars = remote ? parseInt( select.dataset.minChars || '0', 10 ) : 0;

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
				return Array.prototype.slice.call( select.options )
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
							icon: option.dataset.icon || ''
						};
					} );
			}

			/**
			 * Filter the local options by a term.
			 *
			 * @param {string} term Search term.
			 */
			function filter( term ) {
				var needle = term.toLowerCase().trim();

				results = options().filter( function ( result ) {
					return '' === needle || result.text.toLowerCase().indexOf( needle ) > -1;
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
				list.style.top = ( input.offsetTop + input.offsetHeight ) + 'px';
			}

			function close() {
				list.hidden = true;
				list.innerHTML = '';
				input.setAttribute( 'aria-expanded', 'false' );
				input.removeAttribute( 'aria-activedescendant' );
				active = -1;
			}

			function highlight( index ) {
				var options = list.querySelectorAll( '[role="option"]' );

				if ( ! options.length ) {
					return;
				}

				active = ( index + options.length ) % options.length;

				options.forEach( function ( option, i ) {
					option.setAttribute( 'aria-selected', i === active ? 'true' : 'false' );
				} );

				// Focus stays in the input; the active option is pointed at
				// rather than focused, which is what aria-activedescendant is
				// for and why the highlight cannot be a :focus style.
				input.setAttribute( 'aria-activedescendant', options[ active ].id );
				options[ active ].scrollIntoView( { block: 'nearest' } );
			}

			/**
			 * The option carrying a value, creating it if it is allowed to.
			 *
			 * @param {string} value The value.
			 * @param {string} text  Its label.
			 * @return {HTMLOptionElement|null} The option.
			 */
			function optionFor( value, text ) {
				var existing = Array.prototype.slice.call( select.options ).filter(
					function ( option ) {
						return option.value === value;
					}
				)[ 0 ];

				if ( existing ) {
					return existing;
				}

				var option = document.createElement( 'option' );
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

				var sortable = 'true' === select.getAttribute( 'data-sortable' );

				Array.prototype.slice.call( select.selectedOptions ).forEach( function ( option ) {
					var chip = document.createElement( 'li' );
					chip.className = 'field-kit__combobox-chip';

					// syncOrder() matches chips back to options by value.
					chip.dataset.value = option.value;

					if ( sortable ) {
						chip.appendChild( Combobox.moveButton( 'up', option.text ) );
						chip.appendChild( Combobox.moveButton( 'down', option.text ) );
					}

					var label = document.createElement( 'span' );
					label.textContent = option.text;

					var remove = document.createElement( 'button' );
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
						select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
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
					var max = parseInt( select.getAttribute( 'data-max' ) || '0', 10 );

					if ( max > 0 && select.selectedOptions.length >= max ) {
						kit.announce(
							status,
							t( 'maxReached', 'You can choose at most %d.' ).replace( '%d', String( max ) )
						);

						return;
					}

					var option = optionFor( value, text );

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

					var single = document.createElement( 'option' );
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
				var chosen = multiple ? null : select.options[ select.selectedIndex ];

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
				var result = results[ index ];

				if ( ! result ) {
					return;
				}

				take( String( result.id ), result.text, false );
			}

			/**
			 * Add whatever is typed, for a control allowed to invent values.
			 */
			function create() {
				var value = input.value.trim();

				if ( '' === value ) {
					return;
				}

				take( value, value, true );
			}

			function render() {
				list.innerHTML = '';

				// A creatable control offers what was typed as the first row,
				// so the pointer has the same way in as the keyboard.
				var typed = input.value.trim();

				if ( creatable && '' !== typed && ! results.some( function ( result ) {
					return String( result.text ).toLowerCase() === typed.toLowerCase();
				} ) ) {
					results = [ {
						id: typed,
						text: typed,
						create: true
					} ].concat( results );
				}

				if ( ! results.length ) {
					var empty = document.createElement( 'li' );
					empty.className = 'field-kit__combobox-option';
					empty.textContent = t( 'noResults', 'No results found.' );
					list.appendChild( empty );
					place();
					list.hidden = false;
					input.setAttribute( 'aria-expanded', 'true' );
					kit.announce( status, t( 'noResults', 'No results found.' ) );

					return;
				}

				results.forEach( function ( result, i ) {
					var option = document.createElement( 'li' );
					option.className = 'field-kit__combobox-option';
					option.id = list.id + '__' + i;
					option.setAttribute( 'role', 'option' );
					option.setAttribute( 'aria-selected', 'false' );

					var text = result.create
						? t( 'addItem', 'Add' ) + ' “' + result.text + '”'
						: result.text;

					// Built rather than assigned, so the label stays text and
					// the glyph is an element beside it.
					if ( result.icon ) {
						var glyph = document.createElement( 'span' );
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
							take( String( result.id ), String( result.text ), true );

							return;
						}

						choose( i );
					} );
					list.appendChild( option );
				} );

				place();
				list.hidden = false;
				input.setAttribute( 'aria-expanded', 'true' );

				kit.announce(
					status,
					results.length + ' ' + t( 'resultsAvailable', 'results available.' )
				);
			}

			function search( term ) {
				var url = new URL( select.dataset.searchEndpoint, window.location.origin );
				var args = select.dataset.searchArgs ? JSON.parse( select.dataset.searchArgs ) : {};

				// Numbered, so a slow answer to "ab" cannot land after the
				// answer to "abc" and replace it.
				var ticket = ++requests;

				url.searchParams.set( 'source', select.dataset.searchSource );
				url.searchParams.set( 'q', term );

				Object.keys( args ).forEach( function ( key ) {
					url.searchParams.set( 'args[' + key + ']', args[ key ] );
				} );

				kit.request( url, {
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': select.dataset.searchNonce || config.restNonce || '' }
				} ).then( function ( response ) {
					return response.ok ? response.json() : { results: [] };
				} ).then( function ( data ) {
					settle( ticket, data.results || [] );
				} ).catch( function () {
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
				if ( ticket !== requests || document.activeElement !== input ) {
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
		}
	};

	window.SugarCommerceFieldsModules.Combobox = Combobox;
} )();

/**
 * SugarCommerce Fields — reorderable lists and repeaters.
 *
 * Dragging is added on top of move buttons that already work, never instead
 * of them. Every reorder goes through the same move() call, so the keyboard
 * path and the drag path cannot drift apart — and the keyboard path is the
 * one that gets exercised by everyone, because the buttons are always there.
 */
( function () {
	'use strict';

	var kit = window.SugarCommerceFieldsModules;
	var t = kit.t;

	// Defined in the block before this one. Referred to bare, it was a
	// ReferenceError the first time a sortable chip was moved, and the new
	// order never reached the select.
	var Combobox = kit.Combobox;

	var Reorder = {

		/**
		 * Bind every reorderable list within a root.
		 *
		 * @param {Element} root Container.
		 */
		init: function ( root ) {
			root.querySelectorAll( '.field-kit__sortable, .field-kit__repeater-rows, .field-kit__combobox-chips[data-sortable]' ).forEach( function ( list ) {
				if ( list.dataset.fkBound ) {
					return;
				}

				list.dataset.fkBound = '1';
				Reorder.bindButtons( list );
				Reorder.bindDrag( list );
			} );
		},

		/**
		 * The item selector for a given list.
		 *
		 * @param {Element} list The list.
		 * @return {string} Selector.
		 */
		itemSelector: function ( list ) {
			if ( list.classList.contains( 'field-kit__sortable' ) ) {
				return '.field-kit__sortable-item';
			}

			if ( list.classList.contains( 'field-kit__combobox-chips' ) ) {
				return '.field-kit__combobox-chip';
			}

			return '.field-kit__repeater-row';
		},

		/**
		 * Whether the item's neighbours sit beside it rather than under it.
		 *
		 * Measured rather than configured, so a grid that wraps to a single
		 * column on a narrow screen answers vertically without being told —
		 * and a repeater whose `direction` was changed does too.
		 *
		 * @param {Element} item An item in the list.
		 * @return {boolean} Whether the list runs across at this point.
		 */
		isHorizontal: function ( item ) {
			var neighbour = item.nextElementSibling || item.previousElementSibling;

			if ( ! neighbour ) {
				return false;
			}

			// Four pixels of slack: two elements in a row rarely share a top
			// to the pixel once borders and margins are involved.
			return Math.abs(
				neighbour.getBoundingClientRect().top - item.getBoundingClientRect().top
			) < 4;
		},

		/**
		 * Wire the move buttons.
		 *
		 * @param {Element} list The list.
		 */
		bindButtons: function ( list ) {
			list.addEventListener( 'click', function ( event ) {
				var button = event.target.closest( '[data-direction], [data-action="move-up"], [data-action="move-down"]' );

				if ( ! button ) {
					return;
				}

				event.preventDefault();

				var direction = button.dataset.direction || ( button.dataset.action === 'move-up' ? 'up' : 'down' );
				var item = button.closest( Reorder.itemSelector( list ) );

				if ( ! Reorder.owns( list, item ) ) {
					return;
				}

				Reorder.move( list, item, direction );

				// Focus would otherwise be lost when the button it sits on is
				// re-rendered or becomes disabled at the end of the list.
				Reorder.restoreFocus( list, item, direction );
			} );

			// A handle that is only draggable cannot be operated from a
			// keyboard at all, so a list whose only reorder control is a
			// handle would be unorderable without a pointer. Only the button
			// form takes part: the older lists draw the handle as a
			// decorative span and keep their own move buttons.
			list.addEventListener( 'keydown', function ( event ) {
				if ( 'ArrowUp' !== event.key && 'ArrowDown' !== event.key ) {
					return;
				}

				var handle = event.target.closest( 'button.field-kit__drag-handle' );

				if ( ! handle ) {
					return;
				}

				var item = handle.closest( Reorder.itemSelector( list ) );

				if ( ! Reorder.owns( list, item ) ) {
					return;
				}

				// Or the page scrolls under the list while the row moves.
				event.preventDefault();

				Reorder.move( list, item, 'ArrowUp' === event.key ? 'up' : 'down' );

				// Focus stays on the handle, which has moved with its row --
				// so the arrow keys keep working and the new position is
				// announced by the name sync() just rewrote.
				handle.focus();
			} );
		},

		/**
		 * Whether an item is one of this list's own.
		 *
		 * A row can hold a list of its own -- a price and its files -- and
		 * everything that happens in the inner one bubbles through the outer
		 * one too. The nearest item to whatever was pressed is then the
		 * inner list's, and the outer list moving it is an insertBefore()
		 * on a node that is not its child: an exception on every arrow key
		 * and every drag in a nested list.
		 *
		 * @param {Element}      list The list.
		 * @param {Element|null} item What was found.
		 * @return {boolean} Whether the list holds it directly.
		 */
		owns: function ( list, item ) {
			return !! item && item.parentNode === list;
		},

		/**
		 * Move one item.
		 *
		 * @param {Element} list      The list.
		 * @param {Element} item      The item.
		 * @param {string}  direction Either "up" or "down".
		 */
		move: function ( list, item, direction ) {
			var sibling = direction === 'up' ? item.previousElementSibling : item.nextElementSibling;

			if ( ! sibling ) {
				return;
			}

			if ( direction === 'up' ) {
				list.insertBefore( item, sibling );
			} else {
				list.insertBefore( sibling, item );
			}

			Reorder.sync( list );
		},

		/**
		 * Put focus back on the equivalent control after a move.
		 *
		 * @param {Element} list      The list.
		 * @param {Element} item      The moved item.
		 * @param {string}  direction The direction moved.
		 */
		restoreFocus: function ( list, item, direction ) {
			var selector = '[data-direction="' + direction + '"], [data-action="move-' + direction + '"]';
			var button = item.querySelector( selector );

			if ( button && ! button.disabled ) {
				button.focus();

				return;
			}

			// At the end of the list that direction is gone, so focus moves to
			// the opposite control rather than being dropped onto the body.
			var opposite = direction === 'up' ? 'down' : 'up';
			var fallback = item.querySelector( '[data-direction="' + opposite + '"], [data-action="move-' + opposite + '"]' );

			if ( fallback ) {
				fallback.focus();
			}
		},

		/**
		 * Update everything that depends on order.
		 *
		 * @param {Element} list The list.
		 */
		sync: function ( list ) {
			var items = list.querySelectorAll( Reorder.itemSelector( list ) );
			var total = items.length;

			items.forEach( function ( item, index ) {
				// Disable the moves that are no longer possible, so a keyboard
				// user is not sent to a control that does nothing.
				var up = item.querySelector( '[data-direction="up"], [data-action="move-up"]' );
				var down = item.querySelector( '[data-direction="down"], [data-action="move-down"]' );

				if ( up ) {
					up.disabled = index === 0;
				}

				if ( down ) {
					down.disabled = index === total - 1;
				}

				var position = item.querySelector( '.field-kit__repeater-position' );

				if ( position ) {
					position.textContent = t( 'position', 'Item' ) + ' ' + ( index + 1 ) + ' / ' + total;
				}

				// A handle that is the only reorder control has to say where
				// the row landed, or a keyboard user gets no feedback that
				// anything happened. The wording is PHP's, kept as a template
				// so there is one translated string rather than two.
				var handle = item.querySelector( 'button.field-kit__drag-handle[data-label-template]' );

				if ( handle ) {
					handle.setAttribute(
						'aria-label',
						handle.dataset.labelTemplate
							.replace( '{position}', String( index + 1 ) )
							.replace( '{total}', String( total ) )
					);
				}

				item.dataset.index = String( index );
			} );

			if ( list.classList.contains( 'field-kit__combobox-chips' ) ) {
				Combobox.syncOrder( list );
			}

			if ( list.classList.contains( 'field-kit__repeater-rows' ) ) {
				// Looked up rather than closed over: Repeater is defined in a
				// later block, so a direct reference would be a ReferenceError
				// the first time a sortable is reordered.
				var repeater = window.SugarCommerceFieldsModules.Repeater;

				if ( repeater ) {
					repeater.reindex( list );
				}
			}

			list.dataset.empty = total ? 'false' : 'true';
		},

		/**
		 * Add dragging on top of the buttons.
		 *
		 * @param {Element} list The list.
		 */
		bindDrag: function ( list ) {
			var selector = Reorder.itemSelector( list );
			var dragging = null;

			list.addEventListener( 'pointerdown', function ( event ) {
				// Never start a drag from a control: it would swallow the
				// click that operates it. The drag handle is the exception --
				// it is a button so that it can be focused and reordered from
				// the keyboard, and excluding it along with the rest meant the
				// one control drawn as a grab handle was the one control that
				// could not be grabbed.
				if (
					event.target.closest( 'button, input, select, textarea, a' ) &&
					! event.target.closest( 'button.field-kit__drag-handle' )
				) {
					return;
				}

				var item = event.target.closest( selector );

				if ( Reorder.owns( list, item ) ) {
					item.draggable = true;
				}
			} );

			// A drag that ends anywhere but on the list still has to put the
			// item down, or it stays marked draggable and the next pointerdown
			// on a control starts a drag instead of clicking it.
			list.addEventListener( 'pointerup', function () {
				list.querySelectorAll( '[draggable="true"]' ).forEach( function ( item ) {
					item.draggable = false;
				} );
			} );

			list.addEventListener( 'dragstart', function ( event ) {
				dragging = event.target.closest( selector );

				// A row of a list nested in one of this list's rows is that
				// list's to move.
				if ( ! Reorder.owns( list, dragging ) ) {
					dragging = null;
				}

				if ( dragging ) {
					dragging.classList.add( 'is-dragging' );
					event.dataTransfer.effectAllowed = 'move';
				}
			} );

			list.addEventListener( 'dragover', function ( event ) {
				if ( ! dragging ) {
					return;
				}

				event.preventDefault();

				var over = event.target.closest( selector );

				if ( ! Reorder.owns( list, over ) || over === dragging ) {
					return;
				}

				// Past the halfway line of the item under the pointer, the
				// dragged one belongs after it rather than before — but which
				// halfway line depends on how the list is actually laid out.
				//
				// Chips in a row sit side by side, so the question is
				// left-or-right. Asking top-or-bottom there means two items in
				// the same row give the same answer over most of their area,
				// which is why one could not be dropped into the first
				// position at all.
				var box = over.getBoundingClientRect();

				var past = Reorder.isHorizontal( over )
					? ( event.clientX - box.left ) / box.width > 0.5
					: ( event.clientY - box.top ) / box.height > 0.5;

				var reference = past ? over.nextElementSibling : over;

				// Already exactly there. dragover fires continuously — many
				// times per second — and the list has been reordered
				// underneath the pointer since the last one, so without this
				// a single crossing keeps re-inserting and the item travels
				// two or three places at once instead of one.
				if ( reference === dragging || dragging.nextElementSibling === reference ) {
					return;
				}

				list.insertBefore( dragging, reference );
			} );

			[ 'dragend', 'drop' ].forEach( function ( type ) {
				list.addEventListener( type, function ( event ) {
					event.preventDefault();

					if ( dragging ) {
						dragging.classList.remove( 'is-dragging' );
						dragging.draggable = false;
						dragging = null;
						Reorder.sync( list );
					}
				} );
			} );
		}
	};

	window.SugarCommerceFieldsModules.Reorder = Reorder;
} )();

/**
 * SugarCommerce Fields — repeaters, media pickers and tags.
 */
( function () {
	'use strict';

	var kit = window.SugarCommerceFieldsModules;
	var t = kit.t;
	var icon = kit.icon;

	// Pulled from the shared modules object rather than closed over: this is
	// a separate IIFE from the one that resolved it, and referencing `config`
	// directly here threw a ReferenceError that stopped every module after it
	// from initialising.
	var config = kit.config;

	var Repeater = {

		/**
		 * Bind every repeater within a root.
		 *
		 * @param {Element} root Container.
		 */
		init: function ( root ) {
			root.querySelectorAll( '.field-kit__repeater' ).forEach( function ( wrap ) {
				if ( wrap.dataset.fkBound ) {
					return;
				}

				wrap.dataset.fkBound = '1';

				wrap.addEventListener( 'click', function ( event ) {
					var addButton = event.target.closest( '.field-kit__repeater-add' );

					// This repeater's own button. A click inside a repeater
					// nested in one of the rows bubbles up to this listener
					// as well, and without the check "Add a file" inside a
					// price also added a row to the list of prices.
					if ( addButton && Repeater.owns( wrap, addButton ) ) {
						event.preventDefault();

						Repeater.add( wrap );
					}

					var remove = event.target.closest( '.field-kit__repeater-remove' );

					if ( remove && Repeater.owns( wrap, remove ) ) {
						event.preventDefault();
						Repeater.remove( wrap, remove.closest( '.field-kit__repeater-row' ) );
					}

					// The whole header, not just the chevron on it. A title
					// that says which row this is, next to a control that
					// opens it, and only the control opening it, is a target
					// the width of an icon on a strip the width of the panel.
					var header = event.target.closest( '.field-kit__repeater-header' );

					if ( header && Repeater.owns( wrap, header ) && ! event.target.closest( '.field-kit__repeater-actions' ) ) {
						event.preventDefault();
						Repeater.toggle( header.closest( '.field-kit__repeater-row' ) );
					}
				} );

				// A collapsed row is known by its title, so the title has to
				// follow what is typed into the field it comes from --
				// otherwise renaming a tier and folding it away shows the
				// name it used to have until the page is reloaded.
				var key = wrap.dataset.rowTitle;

				if ( key ) {
					wrap.addEventListener( 'input', function ( event ) {
						var row = event.target.closest( '.field-kit__repeater-row' );

						if ( ! row || ! Repeater.namesField( event.target, key ) ) {
							return;
						}

						Repeater.retitle( row, event.target.value );
					} );
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
		owns: function ( wrap, element ) {
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
		own: function ( wrap, selector ) {
			var found = wrap.querySelectorAll( selector );

			for ( var i = 0; i < found.length; i++ ) {
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
		namesField: function ( input, key ) {
			var name = input.getAttribute( 'name' );

			return !! name && name.slice( -( key.length + 2 ) ) === '[' + key + ']';
		},

		/**
		 * Write a row's header text.
		 *
		 * @param {Element} row   The row.
		 * @param {string}  value What the title field holds.
		 */
		retitle: function ( row, value ) {
			var title = row.querySelector( '.field-kit__repeater-title' );

			if ( ! title ) {
				return;
			}

			var fallback = title.dataset.fallback || '';

			title.textContent = String( value ).trim() || fallback;
		},

		/**
		 * Fold a row away, or open it.
		 *
		 * @param {Element} row The row.
		 */
		toggle: function ( row ) {
			if ( ! row ) {
				return;
			}

			var closed = row.classList.toggle( 'is-closed' );
			var button = row.querySelector( '.field-kit__repeater-toggle' );

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
		 * @param {Element} wrap The repeater.
		 */
		add: function ( wrap ) {
			var template = Repeater.own( wrap, '.field-kit__repeater-template' );

			var list = Repeater.own( wrap, '.field-kit__repeater-rows' );

			if ( ! template || ! list ) {
				return;
			}

			var max = parseInt( wrap.dataset.maxRows || '0', 10 );

			if ( max > 0 && list.children.length >= max ) {
				return;
			}

			var row = template.content.firstElementChild.cloneNode( true );

			list.appendChild( row );

			// Through Reorder rather than reindex(): the template is rendered
			// for a row that has no position yet, so it arrives with both its
			// move buttons disabled — and the row that used to be last still
			// has its "move down" switched off. sync() renumbers and settles
			// all of that; reindex() on its own only renumbers, which left
			// every added row unable to move in either direction.
			kit.Reorder.sync( list );

			// The new row's markup has never been past the modules, so a
			// combobox, colour picker or media button inside it is inert
			// until it has. init() is re-entrant — every module marks what it
			// has bound — so this reaches only what was just inserted.
			kit.init( row );

			var empty = Repeater.own( wrap, '.field-kit__repeater-empty' );

			if ( empty ) {
				empty.hidden = true;
			}

			// Open, whatever the saved rows do. The template is rendered for
			// a row with nothing in it, and folding away the fields somebody
			// just asked for is the one thing adding a row must not do.
			if ( row.classList.contains( 'is-closed' ) ) {
				Repeater.toggle( row );
			}

			// Focus into the new row, or adding one is silent and leaves a
			// keyboard user still on the button.
			var first = row.querySelector( 'input:not([type="hidden"]), select, textarea' );

			if ( first ) {
				first.focus();
			}

			kit.announce( Repeater.status( wrap ), t( 'rowAdded', 'Row added.' ) );
		},

		/**
		 * Remove a row.
		 *
		 * @param {Element} wrap The repeater.
		 * @param {Element} row  The row.
		 */
		remove: function ( wrap, row ) {
			var list = row.parentNode;
			var min = parseInt( wrap.dataset.minRows || '0', 10 );

			if ( min > 0 && list.children.length <= min ) {
				return;
			}

			var next = row.nextElementSibling || row.previousElementSibling;

			row.remove();

			// sync() rather than reindex(), for the same reason as add(): the
			// new first and last rows have moves that are no longer possible.
			kit.Reorder.sync( list );

			var empty = Repeater.own( wrap, '.field-kit__repeater-empty' );

			if ( empty ) {
				empty.hidden = list.children.length > 0;
			}

			var target = next
				? next.querySelector( 'input:not([type="hidden"]), select, textarea, button' )
				: Repeater.own( wrap, '.field-kit__repeater-add' );

			if ( target ) {
				target.focus();
			}

			kit.announce( Repeater.status( wrap ), t( 'rowRemoved', 'Row removed.' ) );
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
		status: function ( wrap ) {
			return wrap.querySelector( ':scope > .field-kit__repeater-status' ) || wrap.querySelector( '[aria-live]' );
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
		retarget: function ( row, from, to ) {
			var escaped = CSS.escape( from );

			[ 'for', 'aria-controls', 'data-target', 'data-modal', 'data-editor', 'list' ].forEach( function ( attribute ) {
				row.querySelectorAll( '[' + attribute + '="' + escaped + '"]' ).forEach( function ( element ) {
					element.setAttribute( attribute, to );
				} );
			} );

			// Space-separated lists: the one token is replaced, not the value.
			[ 'aria-describedby', 'aria-labelledby', 'aria-owns' ].forEach( function ( attribute ) {
				row.querySelectorAll( '[' + attribute + '~="' + escaped + '"]' ).forEach( function ( element ) {
					var tokens = element.getAttribute( attribute ).split( /\s+/ ).map( function ( token ) {
						return token === from ? to : token;
					} );

					element.setAttribute( attribute, tokens.join( ' ' ) );
				} );
			} );
		},

		/**
		 * Carry a row's new name into the lists nested in it.
		 *
		 * @param {Element|DocumentFragment} root    The row, or a template's content.
		 * @param {RegExp}                   pattern The row's old name, as a prefix.
		 * @param {string}                   prefix  Its new one.
		 */
		rename: function ( root, pattern, prefix ) {
			root.querySelectorAll( '.field-kit__repeater[data-field-name]' ).forEach( function ( nested ) {
				nested.dataset.fieldName = nested.dataset.fieldName.replace( pattern, prefix );
			} );

			root.querySelectorAll( 'template' ).forEach( function ( template ) {
				template.content.querySelectorAll( '[name]' ).forEach( function ( input ) {
					input.setAttribute( 'name', input.getAttribute( 'name' ).replace( pattern, prefix ) );
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
		reindex: function ( list ) {
			var wrap = list.closest( '.field-kit__repeater' );

			if ( ! wrap ) {
				return;
			}

			var base = wrap.dataset.fieldName || '';

			Array.from( list.children ).forEach( function ( row, index ) {
				row.dataset.index = String( index );

				// The template is rendered at index -1, one no saved row can
				// hold, so the pattern has to admit the sign. Digits alone
				// left every added row named [-1], and two of them posted as
				// one -- the last one typed.
				var pattern = new RegExp( '^' + base.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) + '\\[-?\\d*\\]' );
				var prefix = base + '[' + index + ']';

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
					var id = element.id.replace( /__row-?\d+/, '' );
					var next = id + '__row' + index;

					// Move everything pointing at the old id, or the
					// association is lost the moment a row is renumbered.
					Repeater.retarget( row, element.id, next );

					element.id = next;
				} );
			} );
		}
	};

	/* ====================================================================
	 * Media
	 * ================================================================= */

	var Media = {

		/**
		 * Bind every single-item media field within a root.
		 *
		 * @param {Element} root Container.
		 */
		init: function ( root ) {
			root.querySelectorAll( '.field-kit__media' ).forEach( function ( wrap ) {
				if ( wrap.dataset.fkBound ) {
					return;
				}

				wrap.dataset.fkBound = '1';

				wrap.addEventListener( 'click', function ( event ) {
					if ( event.target.closest( '.field-kit__media-choose' ) ) {
						event.preventDefault();
						Media.open( wrap );
					}

					if ( event.target.closest( '.field-kit__media-clear' ) ) {
						event.preventDefault();
						Media.clear( wrap );
					}
				} );
			} );
		},

		/**
		 * Open the media frame.
		 *
		 * @param {Element} wrap The field.
		 */
		open: function ( wrap ) {
			if ( ! window.wp || ! window.wp.media ) {
				return;
			}

			var frame = window.wp.media( {
				title: wrap.dataset.frameTitle,
				library: { type: wrap.dataset.mimeType || undefined },
				multiple: false,
				button: { text: wrap.dataset.chooseLabel }
			} );

			frame.on( 'select', function () {
				Media.apply( wrap, frame.state().get( 'selection' ).first().toJSON() );
			} );

			frame.open();
		},

		/**
		 * Store and preview a selection.
		 *
		 * @param {Element} wrap       The field.
		 * @param {Object}  attachment Attachment data.
		 */
		apply: function ( wrap, attachment ) {
			var input = wrap.querySelector( '.field-kit__media-value' );
			var preview = wrap.querySelector( '.field-kit__media-preview' );
			var clear = wrap.querySelector( '.field-kit__media-clear' );
			var filenameInput = wrap.querySelector( 'input.field-kit__media-filename' );

			// file_url stores the URL; every other media field stores the id.
			input.value = wrap.dataset.returns === 'url' ? attachment.url : attachment.id;
			input.dispatchEvent( new Event( 'change', { bubbles: true } ) );

			// `file` holds an attachment id, which is nothing to show anyone,
			// so a readonly input beside it carries the name.
			if ( filenameInput ) {
				filenameInput.value = attachment.filename || attachment.title || '';
			}

			// The button under a picture changes the picture.
			var chooser = wrap.querySelector( '.field-kit__media-choose' );

			if ( chooser && wrap.dataset.replaceLabel ) {
				chooser.textContent = wrap.dataset.replaceLabel;
			}

			if ( preview ) {
				var sizes = attachment.sizes || {};
				var thumb = sizes.medium || sizes.thumbnail;

				preview.dataset.empty = 'false';
				preview.textContent = '';

				// Built, not written: the URL and alt text are whatever the
				// media library holds, and a string of markup is the one
				// place they must not be interpolated.
				if ( thumb ) {
					var image = document.createElement( 'img' );
					image.src = thumb.url;
					image.alt = attachment.alt || '';
					preview.appendChild( image );
				} else {
					var name = document.createElement( 'span' );
					name.className = 'field-kit__media-filename';
					preview.appendChild( name );
				}

				var filename = preview.querySelector( '.field-kit__media-filename' );

				if ( filename ) {
					filename.textContent = attachment.filename || attachment.title || '';
				}
			}

			if ( clear ) {
				clear.hidden = false;
			}
		},

		/**
		 * Clear a selection.
		 *
		 * @param {Element} wrap The field.
		 */
		clear: function ( wrap ) {
			var input = wrap.querySelector( '.field-kit__media-value' );
			var preview = wrap.querySelector( '.field-kit__media-preview' );
			var clear = wrap.querySelector( '.field-kit__media-clear' );
			var choose = wrap.querySelector( '.field-kit__media-choose' );
			var filenameInput = wrap.querySelector( 'input.field-kit__media-filename' );

			input.value = '';
			input.dispatchEvent( new Event( 'change', { bubbles: true } ) );

			if ( filenameInput ) {
				filenameInput.value = '';
			}

			// And with nothing chosen it goes back to offering to choose.
			if ( choose && wrap.dataset.replaceLabel && wrap.dataset.chooseLabel ) {
				choose.textContent = wrap.dataset.chooseLabel;
			}

			if ( preview ) {
				preview.innerHTML = '';
				preview.dataset.empty = 'true';
			}

			if ( clear ) {
				clear.hidden = true;
			}

			// The button that was just used is now hidden, so focus has to go
			// somewhere deliberate rather than to the body.
			if ( choose ) {
				choose.focus();
			}
		}
	};

	/* ====================================================================
	 * Tags
	 * ================================================================= */

	var Tags = {

		/**
		 * Mirror each tags input into a visible, announced list.
		 *
		 * The input stays the source of truth and stays editable, so the
		 * field is fully usable before this runs and remains so if it fails.
		 *
		 * @param {Element} root Container.
		 */
		init: function ( root ) {
			root.querySelectorAll( '.field-kit__tags' ).forEach( function ( wrap ) {
				if ( wrap.dataset.fkBound ) {
					return;
				}

				wrap.dataset.fkBound = '1';

				var input = wrap.querySelector( '.field-kit__tags-input' );
				var list = wrap.querySelector( '.field-kit__tags-list' );

				if ( ! input || ! list ) {
					return;
				}

				var separator = input.dataset.separator || ',';

				function render() {
					list.innerHTML = '';

					input.value.split( separator ).map( function ( tag ) {
						return tag.trim();
					} ).filter( Boolean ).forEach( function ( tag ) {
						var pill = document.createElement( 'span' );
						pill.className = 'field-kit__tag';
						pill.textContent = tag;
						list.appendChild( pill );
					} );
				}

				input.addEventListener( 'input', render );
				render();
			} );
		}
	};


	/* ====================================================================
	 * Code editor
	 * ================================================================= */

	var CodeEditor = {

		/**
		 * Upgrade every code field to CodeMirror.
		 *
		 * The control stays a textarea underneath, so a user who has turned
		 * syntax highlighting off in their profile — in which case
		 * wp_enqueue_code_editor() returns false and there are no settings
		 * to apply — still gets a working, keyboard-accessible field rather
		 * than nothing.
		 *
		 * @param {Element} root Container.
		 */
		init: function ( root ) {
			if ( ! window.wp || ! window.wp.codeEditor || ! config.codeEditors ) {
				return;
			}

			root.querySelectorAll( 'textarea.field-kit__code' ).forEach( function ( textarea ) {
				if ( textarea.dataset.fkBound ) {
					return;
				}

				var settings = config.codeEditors[ textarea.dataset.language ];

				if ( ! settings ) {
					return;
				}

				textarea.dataset.fkBound = '1';

				var editor = window.wp.codeEditor.initialize( textarea, settings );

				// CodeMirror renders into its own element and stops firing
				// the textarea's events, so the value is written back on
				// change — otherwise the field submits whatever it loaded
				// with, however much was typed.
				if ( editor && editor.codemirror ) {
					editor.codemirror.on( 'change', function ( instance ) {
						textarea.value = instance.getValue();
					} );

					// The generated element is not a form control and gets no
					// label, so the field's own label is pointed at it.
					var wrapper = editor.codemirror.getWrapperElement();

					if ( wrapper ) {
						wrapper.setAttribute( 'aria-label', textarea.getAttribute( 'aria-label' ) || '' );
					}
				}
			} );
		}
	};

	window.SugarCommerceFieldsModules.CodeEditor = CodeEditor;

	/* ====================================================================
	 * Visual editor
	 * ================================================================= */

	var Wysiwyg = {

		/**
		 * Start every visual editor that nothing else has started.
		 *
		 * wp_editor() prints the editor's markup where it is called and its
		 * settings in the page footer, and core starts the editor from those
		 * settings once the page has loaded. Markup that arrives later -- a
		 * flyout fetched over REST -- comes without them: the Visual tab did
		 * nothing, the Code toolbar was empty, and only the bare textarea
		 * worked. So an editor core does not know about is started here,
		 * from core's own defaults (wp_enqueue_editor() prints them), on the
		 * markup wp_editor() already drew.
		 *
		 * An editor core did start is left alone.
		 *
		 * @param {Element} root Container.
		 */
		init: function ( root ) {
			root.querySelectorAll( '.field-kit__wysiwyg-wrap[data-editor]' ).forEach( function ( wrap ) {
				var id = wrap.dataset.editor;
				var textarea = document.getElementById( id );

				if ( wrap.dataset.fkBound || ! textarea || ! wrap.contains( textarea ) ) {
					return;
				}

				if ( Wysiwyg.startedByCore( id, textarea ) ) {
					wrap.dataset.fkBound = '1';
					return;
				}

				if ( ! window.wp || ! window.wp.editor || typeof window.wp.editor.getDefaultSettings !== 'function' || ! window.tinymce ) {
					return;
				}

				wrap.dataset.fkBound = '1';
				Wysiwyg.start( wrap, id, textarea );
			} );
		},

		/**
		 * Whether core started this editor, from settings printed with the
		 * page.
		 *
		 * @param {string}  id       Editor id.
		 * @param {Element} textarea Its textarea.
		 * @return {boolean} Yes when core owns it.
		 */
		startedByCore: function ( id, textarea ) {
			var pre = window.tinyMCEPreInit;
			var editor = window.tinymce && window.tinymce.get( id );
			var qt = window.QTags && window.QTags.instances && window.QTags.instances[ id ];

			if ( ! pre || ! ( ( pre.mceInit && pre.mceInit[ id ] ) || ( pre.qtInit && pre.qtInit[ id ] ) ) || Wysiwyg.ours[ id ] ) {
				return false;
			}

			return !! ( ( editor && editor.getElement() === textarea ) || ( qt && qt.canvas === textarea ) || ! window.tinymce );
		},

		/**
		 * Ids of the editors this module started.
		 */
		ours: {},

		/**
		 * Start one editor on markup wp_editor() drew.
		 *
		 * @param {Element} wrap     The field's wrapper.
		 * @param {string}  id       Editor id.
		 * @param {Element} textarea Its textarea.
		 */
		start: function ( wrap, id, textarea ) {
			var defaults = window.wp.editor.getDefaultSettings();
			var editorWrap = document.getElementById( 'wp-' + id + '-wrap' );
			var mce = {};
			var key;

			// The same editor id came back -- a panel opened a second time --
			// and whatever was started on the copy that has gone is stopped
			// first, or TinyMCE keeps the old instance and the new textarea
			// never becomes an editor.
			Wysiwyg.stop( id );

			for ( key in defaults.tinymce ) {
				if ( Object.prototype.hasOwnProperty.call( defaults.tinymce, key ) ) {
					mce[ key ] = defaults.tinymce[ key ];
				}
			}

			mce.selector = '#' + id;
			mce.body_class = id;

			// wp_editor()'s toolbars rather than the two-button row core's
			// script defaults to, so the same field has the same editor
			// wherever it is drawn.
			if ( wrap.dataset.teeny ) {
				mce.toolbar1 = 'bold,italic,underline,blockquote,strikethrough,bullist,numlist,alignleft,aligncenter,alignright,undo,redo,link,fullscreen';
				mce.toolbar2 = '';
			} else {
				mce.toolbar1 = 'formatselect,bold,italic,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,wp_more,fullscreen,wp_adv';
				mce.toolbar2 = 'strikethrough,hr,forecolor,pastetext,removeformat,charmap,outdent,indent,undo,redo,wp_help';
			}

			if ( wrap.dataset.height ) {
				mce.height = parseInt( wrap.dataset.height, 10 );
			}

			// The textarea is what the form sends, so it is kept in step with
			// what is typed, and told -- a panel watching for changes listens
			// to the form, not to an iframe.
			mce.setup = function ( editor ) {
				editor.on( 'change input keyup undo redo SetContent', function () {
					if ( editor.isHidden() ) {
						return;
					}

					var before = textarea.value;

					editor.save();

					if ( textarea.value !== before ) {
						textarea.dispatchEvent( new Event( 'input', { bubbles: true } ) );
						textarea.dispatchEvent( new Event( 'change', { bubbles: true } ) );
					}
				} );
			};

			// Where core's Visual and Code tabs look for the settings, so
			// switching starts TinyMCE when the editor opened on Code.
			window.tinyMCEPreInit = window.tinyMCEPreInit || { mceInit: {}, qtInit: {} };
			window.tinyMCEPreInit.mceInit = window.tinyMCEPreInit.mceInit || {};
			window.tinyMCEPreInit.qtInit = window.tinyMCEPreInit.qtInit || {};
			window.tinyMCEPreInit.mceInit[ id ] = mce;
			// wp_editor()'s own buttons, not the shorter list core's script
			// defaults to: the same field has the same toolbar wherever it
			// is drawn.
			window.tinyMCEPreInit.qtInit[ id ] = { id: id, buttons: 'strong,em,link,block,del,ins,img,ul,ol,li,code,more,close' };

			Wysiwyg.ours[ id ] = true;

			if ( window.quicktags && editorWrap && editorWrap.querySelector( '.quicktags-toolbar' ) ) {
				window.quicktags( window.tinyMCEPreInit.qtInit[ id ] );

				if ( window.QTags && typeof window.QTags._buttonsInit === 'function' ) {
					window.QTags._buttonsInit();
				}
			}

			// Opened on whichever tab the person last used, as wp_editor()
			// said by the class it gave the wrapper.
			if ( ! editorWrap || editorWrap.classList.contains( 'tmce-active' ) ) {
				window.tinymce.init( mce );
			}

			if ( ! window.wpActiveEditor ) {
				window.wpActiveEditor = id;
			}
		},

		/**
		 * Stop an editor this module started, whether or not its markup is
		 * still on the page.
		 *
		 * @param {string} id Editor id.
		 */
		stop: function ( id ) {
			var editor = window.tinymce && window.tinymce.get( id );

			if ( editor ) {
				if ( ! editor.isHidden() && document.body.contains( editor.getElement() ) ) {
					editor.save();
				}

				editor.remove();
			}

			if ( window.QTags && window.QTags.instances && window.QTags.instances[ id ] ) {
				delete window.QTags.instances[ id ];
			}

			if ( window.wpActiveEditor === id ) {
				window.wpActiveEditor = null;
			}

			delete Wysiwyg.ours[ id ];
		},

		/**
		 * Stop every editor this module started within a root that is about
		 * to go.
		 *
		 * @param {Element} root Container.
		 */
		destroy: function ( root ) {
			root.querySelectorAll( '.field-kit__wysiwyg-wrap[data-editor]' ).forEach( function ( wrap ) {
				var id = wrap.dataset.editor;

				if ( Wysiwyg.ours[ id ] ) {
					Wysiwyg.stop( id );
				}

				delete wrap.dataset.fkBound;
			} );
		}
	};

	window.SugarCommerceFieldsModules.Wysiwyg = Wysiwyg;

	/* ====================================================================
	 * Colour picker
	 * ================================================================= */

	var ColorPicker = {

		/**
		 * Upgrade every colour field to core's picker.
		 *
		 * Enqueueing wp-color-picker is not enough: it is a jQuery plugin and
		 * does nothing until it is called. The control was a plain text input
		 * on screen for exactly that reason.
		 *
		 * Only a field that asked for core's picker (`'picker' =>
		 * 'wordpress'`, or `alpha`) is one of these; every other colour
		 * field is the native control, which ColorField drives. The input
		 * keeps working if jQuery or the plugin is missing -- a hex value
		 * can still be typed.
		 *
		 * @param {Element} root Container.
		 */
		init: function ( root ) {
			var $ = window.jQuery;

			if ( ! $ || ! $.fn || ! $.fn.wpColorPicker ) {
				return;
			}

			root.querySelectorAll( 'input.field-kit__color' ).forEach( function ( input ) {
				if ( input.dataset.fkBound ) {
					return;
				}

				input.dataset.fkBound = '1';

				/**
				 * Tell the kit the value moved, without telling iris.
				 *
				 * iris fires its change callback *before* it writes the new
				 * value to the input — verified in iris.min.js `_change`,
				 * which calls `_trigger("change")` and only then
				 * `element.val()`. It also listens for `change` on the input
				 * itself and re-reads the value when one arrives.
				 *
				 * So dispatching a native change from here handed iris the
				 * *old* value and it reset the picker: the palette opened,
				 * swatches highlighted, and clicking one changed nothing.
				 * From the clear callback it was worse — that one is reached
				 * from iris's own change listener, so a native change there
				 * loops.
				 *
				 * A custom event does neither. Conditions listens for it.
				 */
				function notify() {
					input.dispatchEvent( new CustomEvent( 'field-kit:change', { bubbles: true } ) );
				}

				$( input ).wpColorPicker( {
					palettes: input.dataset.palette ? input.dataset.palette.split( ',' ) : true,
					change: notify,
					clear: notify
				} );
			} );
		}
	};

	window.SugarCommerceFieldsModules.ColorPicker = ColorPicker;

	/* ====================================================================
	 * Colour: the browser's picker and a hex box, kept in step
	 * ================================================================= */

	var ColorField = {

		/**
		 * A hex colour as the field stores it, or '' for anything else.
		 *
		 * The server's rule (Color::hex()), so what the box shows after a
		 * paste is what will be saved: `E8A5C4`, `#e8a5c4` and `#E8A5C4`
		 * are all `#e8a5c4`, and `#abc` is `#aabbcc`.
		 *
		 * @param {string} value What was typed, pasted or picked.
		 * @return {string} `#rrggbb`, or ''.
		 */
		normalize: function ( value ) {
			var hex = String( value || '' ).trim().replace( /^#/, '' );

			if ( /^[0-9a-f]{3}$/i.test( hex ) ) {
				hex = hex.charAt( 0 ) + hex.charAt( 0 ) + hex.charAt( 1 ) + hex.charAt( 1 ) + hex.charAt( 2 ) + hex.charAt( 2 );
			}

			return /^[0-9a-f]{6}$/i.test( hex ) ? '#' + hex.toLowerCase() : '';
		},

		/**
		 * Bind every native colour field within a root.
		 *
		 * The hex box is the field -- it has the name and is what is
		 * saved -- and the swatch, the clear button and the presets all
		 * write to it. Whatever writes, the box announces an `input` and a
		 * `change`, so conditional fields and a panel's unsaved-changes
		 * check hear a picked colour exactly as they hear a typed one.
		 *
		 * @param {Element} root Container.
		 */
		init: function ( root ) {
			root.querySelectorAll( '.field-kit__color-field' ).forEach( function ( wrap ) {
				if ( wrap.dataset.fkBound ) {
					return;
				}

				wrap.dataset.fkBound = '1';

				var swatch = wrap.querySelector( '.field-kit__color-swatch' );
				var hex = wrap.querySelector( '.field-kit__color-hex' );
				var clear = wrap.querySelector( '.field-kit__color-clear' );
				var presets = wrap.querySelectorAll( '.field-kit__color-preset' );

				if ( ! swatch || ! hex ) {
					return;
				}

				/**
				 * Show a colour everywhere but the box.
				 *
				 * @param {string} value `#rrggbb`, or '' for none.
				 */
				function paint( value ) {
					wrap.dataset.empty = value ? 'false' : 'true';

					if ( value ) {
						swatch.value = value;
					}

					if ( clear ) {
						clear.hidden = ! value;
					}

					presets.forEach( function ( preset ) {
						preset.setAttribute( 'aria-pressed', preset.dataset.color === value ? 'true' : 'false' );
					} );
				}

				/**
				 * Put a colour in the box, and say so.
				 *
				 * @param {string} value `#rrggbb`, or '' for none.
				 */
				function set( value ) {
					hex.value = value;
					paint( value );
					hex.dispatchEvent( new Event( 'input', { bubbles: true } ) );
					hex.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				}

				// While the picker is open the swatch fires `input` as the
				// colour moves, and the box follows it.
				swatch.addEventListener( 'input', function () {
					set( ColorField.normalize( swatch.value ) );
				} );

				// Typing: the swatch follows as soon as the box holds a
				// colour, and the box is left alone until it is done with --
				// rewriting "#E8A" to "#ee88aa" mid-word would fight the
				// person typing it.
				hex.addEventListener( 'input', function () {
					var value = ColorField.normalize( hex.value );

					if ( value || '' === hex.value.trim() ) {
						paint( value );
					}
				} );

				// Done with: tidied into the form it will be saved in.
				hex.addEventListener( 'change', function () {
					var value = ColorField.normalize( hex.value );

					if ( value && value !== hex.value ) {
						hex.value = value;
					}

					if ( value || '' === hex.value.trim() ) {
						paint( value );
					}
				} );

				if ( clear ) {
					clear.addEventListener( 'click', function () {
						set( '' );

						// The button hides itself; focus goes where the next
						// colour would be typed rather than to the page.
						hex.focus();
					} );
				}

				presets.forEach( function ( preset ) {
					preset.addEventListener( 'click', function () {
						set( ColorField.normalize( preset.dataset.color ) );
					} );
				} );
			} );
		}
	};

	window.SugarCommerceFieldsModules.ColorField = ColorField;

	/* ====================================================================
	 * Icon picker
	 * ================================================================= */

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
	var IconPicker = {

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
		init: function ( root ) {
			root.querySelectorAll( '.field-kit__icon-picker' ).forEach( function ( wrap ) {
				if ( wrap.dataset.fkBound ) {
					return;
				}

				wrap.dataset.fkBound = '1';

				var button = wrap.querySelector( '.field-kit__icon-button' );
				var clear = wrap.querySelector( '.field-kit__icon-clear' );

				if ( ! button ) {
					return;
				}

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
			} );
		},

		/**
		 * The sets a field offers, as the server described them.
		 *
		 * @param {Element} wrap The field.
		 * @return {Array<Object>} Sets.
		 */
		sets: function ( wrap ) {
			try {
				return JSON.parse( wrap.dataset.sets || '[]' );
			} catch ( error ) {
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
		initial: function ( wrap, sets ) {
			var input = wrap.querySelector( '.field-kit__icon-value' );
			var current = input && input.value ? input.value.split( ':' )[ 0 ] : '';

			return sets.filter( function ( set ) {
				return set.id === current;
			} )[ 0 ] || sets[ 0 ] || null;
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
		fetch: function ( wrap, set, search, page ) {
			var endpoint = wrap.dataset.endpoint || ( config.restUrl ? config.restUrl + 'icons' : '' );
			var url;

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

			var key = url.toString();

			if ( ! IconPicker.cache[ key ] ) {
				IconPicker.cache[ key ] = kit.request( key, {
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': wrap.dataset.nonce || config.restNonce || '' }
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
		prefetch: function ( wrap ) {
			var set = IconPicker.initial( wrap, IconPicker.sets( wrap ) );

			if ( set && typeof fetch === 'function' ) {
				IconPicker.fetch( wrap, set, '', 1 ).catch( function () {} );
			}
		},

		/**
		 * Build the dialog, once per page.
		 *
		 * @return {Element} The dialog.
		 */
		build: function () {
			if ( IconPicker.dialog ) {
				return IconPicker.dialog;
			}

			var dialog = document.createElement( 'dialog' );

			dialog.className = 'field-kit__icon-dialog';
			dialog.setAttribute( 'role', 'dialog' );
			dialog.setAttribute( 'aria-modal', 'true' );
			dialog.setAttribute( 'aria-labelledby', 'field-kit-icon-title' );
			dialog.innerHTML =
				'<div class="field-kit__icon-dialog-header">' +
					'<h2 class="field-kit__icon-dialog-title" id="field-kit-icon-title"></h2>' +
					'<button type="button" class="field-kit__icon-dialog-close">' + icon( 'close' ) + '</button>' +
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

			dialog.querySelector( '.field-kit__icon-dialog-title' ).textContent = t( 'iconTitle', 'Choose an icon' );
			dialog.querySelector( '.field-kit__icon-dialog-close' ).setAttribute( 'aria-label', t( 'iconClose', 'Close' ) );
			dialog.querySelector( 'label' ).textContent = t( 'iconSearch', 'Search icons' );
			dialog.querySelector( '.field-kit__icon-search' ).setAttribute( 'placeholder', t( 'iconSearch', 'Search icons' ) );
			dialog.querySelector( '.field-kit__icon-tabs' ).setAttribute( 'aria-label', t( 'iconSets', 'Icon sets' ) );
			dialog.querySelector( '.field-kit__icon-grid' ).setAttribute( 'aria-label', t( 'iconGrid', 'Icons' ) );
			dialog.querySelector( '.field-kit__icon-none' ).textContent = t( 'iconNone', 'No icon' );

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
		bindDialog: function ( dialog ) {
			var search = dialog.querySelector( '.field-kit__icon-search' );
			var grid = dialog.querySelector( '.field-kit__icon-grid' );
			var tabs = dialog.querySelector( '.field-kit__icon-tabs' );
			var results = dialog.querySelector( '.field-kit__icon-results' );
			var timer = null;

			dialog.querySelector( '.field-kit__icon-dialog-close' ).addEventListener( 'click', function () {
				IconPicker.close();
			} );

			dialog.querySelector( '.field-kit__icon-none' ).addEventListener( 'click', function () {
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
				var first;

				if ( 'ArrowDown' === event.key ) {
					first = grid.querySelector( '[aria-selected="true"]' ) || grid.querySelector( '[role="option"]' );

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
				var tab = event.target.closest( '[role="tab"]' );

				if ( tab ) {
					IconPicker.select( tab.dataset.set );
				}
			} );

			// The tab list is one stop, and the arrows move along it.
			tabs.addEventListener( 'keydown', function ( event ) {
				var all = Array.prototype.slice.call( tabs.querySelectorAll( '[role="tab"]' ) );
				var at = all.indexOf( document.activeElement );
				var next = null;

				if ( 'ArrowRight' === event.key || 'ArrowLeft' === event.key ) {
					next = all[ ( at + ( 'ArrowRight' === event.key ? 1 : all.length - 1 ) ) % all.length ];
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
				var option = event.target.closest( '[role="option"]' );

				if ( option ) {
					IconPicker.choose( option );
				}
			} );

			grid.addEventListener( 'keydown', function ( event ) {
				IconPicker.navigate( event );
			} );

			// The next page, when the end of this one scrolls into view.
			if ( window.IntersectionObserver ) {
				new window.IntersectionObserver( function ( entries ) {
					var state = IconPicker.state;

					if ( entries[ 0 ].isIntersecting && state && ! state.loading && state.page < state.pages ) {
						IconPicker.load( state.page + 1 );
					}
				}, { root: results, rootMargin: '200px' } ).observe( dialog.querySelector( '.field-kit__icon-sentinel' ) );
			}
		},

		/**
		 * Open the dialog for a field.
		 *
		 * @param {Element} wrap The field.
		 */
		open: function ( wrap ) {
			var sets = IconPicker.sets( wrap );
			var set = IconPicker.initial( wrap, sets );

			if ( ! set ) {
				return;
			}

			if ( window.performance && window.performance.mark ) {
				window.performance.mark( 'field-kit:icons-open' );
			}

			var dialog = IconPicker.build();
			var search = dialog.querySelector( '.field-kit__icon-search' );
			var input = wrap.querySelector( '.field-kit__icon-value' );

			IconPicker.state = {
				wrap: wrap,
				sets: sets,
				set: set,
				search: '',
				page: 0,
				pages: 0,
				loading: false,
				ticket: 0,
				value: input ? input.value : '',

				// Opened on a chosen icon, the grid goes on loading until
				// it is drawn, and scrolls to it.
				seeking: !! ( input && input.value )
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
		close: function () {
			var dialog = IconPicker.dialog;

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
		closed: function () {
			var state = IconPicker.state;

			IconPicker.state = null;

			if ( state ) {
				IconPicker.expanded( state.wrap, false );

				var button = state.wrap.querySelector( '.field-kit__icon-button' );

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
		expanded: function ( wrap, open ) {
			var button = wrap.querySelector( '.field-kit__icon-button' );

			if ( button ) {
				button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			}
		},

		/**
		 * Draw the set tabs for the open field.
		 */
		tabs: function () {
			var state = IconPicker.state;
			var list = IconPicker.dialog.querySelector( '.field-kit__icon-tabs' );
			var html = '';

			state.sets.forEach( function ( set ) {
				var selected = set.id === state.set.id;
				var style = 'outline' === set.style
					? t( 'iconOutline', 'Outline' )
					: ( 'solid' === set.style ? t( 'iconSolid', 'Solid' ) : '' );

				html += '<button type="button" role="tab" class="field-kit__icon-tab" id="field-kit-icon-tab-' + IconPicker.escape( set.id ) + '"' +
					' data-set="' + IconPicker.escape( set.id ) + '"' +
					' aria-selected="' + ( selected ? 'true' : 'false' ) + '"' +
					' aria-controls="field-kit-icon-grid" tabindex="' + ( selected ? '0' : '-1' ) + '">' +
					IconPicker.escape( set.label ) +
					( style ? ' <span class="field-kit__icon-tab-style">' + IconPicker.escape( style ) + '</span>' : '' ) +
					'</button>';
			} );

			list.innerHTML = html;
			list.hidden = state.sets.length < 2;

			IconPicker.revealTab();

			IconPicker.dialog.querySelector( '.field-kit__icon-results' ).setAttribute( 'aria-labelledby', 'field-kit-icon-tab-' + state.set.id );
			IconPicker.dialog.querySelector( '.field-kit__icon-license' ).textContent = state.set.license || '';
		},

		/**
		 * Bring the chosen tab into view.
		 *
		 * On a phone the tabs are one row that scrolls sideways, and the
		 * chosen one may be off the end of it. Asked again once the dialog
		 * is open, because a closed dialog has no layout to scroll.
		 */
		revealTab: function () {
			var list = IconPicker.dialog.querySelector( '.field-kit__icon-tabs' );
			var current = list.querySelector( '[aria-selected="true"]' );

			if ( current && list.scrollWidth > list.clientWidth ) {
				list.scrollLeft = current.offsetLeft - list.offsetLeft - ( list.clientWidth - current.offsetWidth ) / 2;
			}
		},

		/**
		 * Switch to another set, keeping the search.
		 *
		 * @param {string} id The set's id.
		 */
		select: function ( id ) {
			var state = IconPicker.state;
			var set = state ? state.sets.filter( function ( one ) {
				return one.id === id;
			} )[ 0 ] : null;

			if ( ! set || set.id === state.set.id ) {
				return;
			}

			state.set = set;
			state.seeking = false;
			IconPicker.tabs();

			var tab = IconPicker.dialog.querySelector( '[data-set="' + set.id + '"]' );

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
		load: function ( page ) {
			var state = IconPicker.state;
			var dialog = IconPicker.dialog;
			var grid = dialog.querySelector( '.field-kit__icon-grid' );
			var status = dialog.querySelector( '.field-kit__icon-status' );
			var empty = dialog.querySelector( '.field-kit__icon-empty' );

			// Numbered, so the answer to "car" cannot land after the answer
			// to "cart" and replace it.
			var ticket = ++state.ticket;
			var set = state.set;

			state.loading = true;

			if ( 1 === page ) {
				grid.setAttribute( 'aria-busy', 'true' );
			}

			IconPicker.fetch( state.wrap, set, state.search, page ).then( function ( data ) {
				if ( IconPicker.state !== state || ticket !== state.ticket ) {
					return;
				}

				state.loading = false;
				state.page = data.page || page;
				state.pages = data.pages || 0;

				var html = IconPicker.options( data.icons || [], state.value );

				if ( 1 === page ) {
					grid.innerHTML = html;
					grid.removeAttribute( 'aria-busy' );
					dialog.querySelector( '.field-kit__icon-results' ).scrollTop = 0;

					if ( window.performance && window.performance.mark ) {
						window.performance.mark( 'field-kit:icons-painted' );
					}
				} else {
					grid.insertAdjacentHTML( 'beforeend', html );
				}

				empty.hidden = data.total > 0;
				empty.textContent = data.total > 0 ? '' : t( 'iconNoMatch', 'No icons match.' );

				// A count rather than the page, so a search says how many
				// it found and not how many are drawn so far.
				status.textContent = state.search
					? ( 1 === data.total ? t( 'iconMatch', '1 icon matches.' ) : t( 'iconMatches', '%d icons match.' ).replace( '%d', String( data.total ) ) )
					: ( 1 === data.total ? t( 'iconOne', '1 icon' ) : t( 'iconCount', '%d icons' ).replace( '%d', String( data.total ) ) );

				if ( state.seeking && IconPicker.seek( state, grid ) ) {
					return;
				}

				// Short of the end and nothing to scroll: the sentinel is
				// already in view and will not cross into it again.
				if ( state.page < state.pages && grid.scrollHeight <= grid.parentNode.clientHeight + 200 ) {
					IconPicker.load( state.page + 1 );
				}
			} ).catch( function () {
				if ( IconPicker.state === state && ticket === state.ticket ) {
					state.loading = false;
					grid.removeAttribute( 'aria-busy' );
					status.textContent = t( 'iconFailed', 'The icons could not be loaded.' );
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
		seek: function ( state, grid ) {
			var chosen = grid.querySelector( '[aria-selected="true"]' );

			if ( chosen ) {
				state.seeking = false;
				grid.querySelectorAll( '[tabindex="0"]' ).forEach( function ( other ) {
					other.setAttribute( 'tabindex', '-1' );
				} );
				chosen.setAttribute( 'tabindex', '0' );
				chosen.scrollIntoView( { block: 'center' } );

				return false;
			}

			if ( state.search || 0 !== state.value.indexOf( state.set.id + ':' ) || state.page >= Math.min( state.pages, 10 ) ) {
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
		options: function ( icons, chosen ) {
			return icons.map( function ( icon ) {
				var label = IconPicker.escape( icon.label );

				return '<div role="option" class="field-kit__icon-option" tabindex="-1"' +
					' data-value="' + IconPicker.escape( icon.value ) + '"' +
					' aria-selected="' + ( icon.value === chosen ? 'true' : 'false' ) + '"' +
					' aria-label="' + label + '" title="' + label + '">' + icon.svg + '</div>';
			} ).join( '' );
		},

		/**
		 * Move focus to an option, making it the grid's one tab stop.
		 *
		 * @param {Element} option The option.
		 */
		focus: function ( option ) {
			var grid = option.parentNode;

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
		navigate: function ( event ) {
			var option = event.target.closest( '[role="option"]' );

			if ( ! option ) {
				return;
			}

			var grid = option.parentNode;
			var all = Array.prototype.slice.call( grid.querySelectorAll( '[role="option"]' ) );
			var at = all.indexOf( option );
			var columns = all.filter( function ( one ) {
				return one.offsetTop === all[ 0 ].offsetTop;
			} ).length || 1;
			var next = null;

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
						IconPicker.dialog.querySelector( '.field-kit__icon-search' ).focus();

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
		choose: function ( option ) {
			if ( ! IconPicker.state ) {
				return;
			}

			IconPicker.write( IconPicker.state.wrap, {
				value: option.dataset.value,
				label: option.getAttribute( 'aria-label' ) || '',
				svg: option.innerHTML
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
		write: function ( wrap, choice ) {
			var input = wrap.querySelector( '.field-kit__icon-value' );
			var glyph = wrap.querySelector( '.field-kit__icon-glyph' );
			var name = wrap.querySelector( '.field-kit__icon-name' );
			var clear = wrap.querySelector( '.field-kit__icon-clear' );

			if ( ! input ) {
				return;
			}

			input.value = choice ? choice.value : '';
			wrap.dataset.empty = choice ? 'false' : 'true';

			if ( glyph ) {
				glyph.innerHTML = choice ? choice.svg : '';
			}

			if ( name ) {
				name.textContent = choice ? choice.label : ( wrap.dataset.placeholder || t( 'iconTitle', 'Choose an icon' ) );
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
		escape: function ( text ) {
			return String( null === text || undefined === text ? '' : text )
				.replace( /&/g, '&amp;' )
				.replace( /</g, '&lt;' )
				.replace( />/g, '&gt;' )
				.replace( /"/g, '&quot;' )
				.replace( /'/g, '&#039;' );
		}
	};

	window.SugarCommerceFieldsModules.IconPicker = IconPicker;

	/* ====================================================================
	 * Panel tabs
	 * ================================================================= */

	var PanelTabs = {

		/**
		 * Bind every tab list within a root.
		 *
		 * @param {Element} root Container.
		 */
		init: function ( root ) {
			root.querySelectorAll( '.field-kit__panel-tabs' ).forEach( function ( wrap ) {
				if ( wrap.dataset.fkBound ) {
					return;
				}

				wrap.dataset.fkBound = '1';
				PanelTabs.bind( wrap );
			} );
		},

		/**
		 * Wire one tab list.
		 *
		 * @param {Element} wrap The tabbed panel.
		 */
		bind: function ( wrap ) {
			var tabs = Array.prototype.slice.call(
				wrap.querySelectorAll( '.field-kit__panel-tab' )
			);

			if ( ! tabs.length ) {
				return;
			}

			/**
			 * Show one tab's panel and hide the rest.
			 *
			 * @param {number}  index      Which tab.
			 * @param {boolean} moveFocus  Whether to focus it.
			 */
			function select( index, moveFocus ) {
				tabs.forEach( function ( tab, i ) {
					var chosen = i === index;
					var panel = wrap.querySelector( '#' + CSS.escape( tab.getAttribute( 'aria-controls' ) ) );

					tab.setAttribute( 'aria-selected', chosen ? 'true' : 'false' );

					// Only the selected tab is in the tab order: arrow keys
					// move between them, and tabbing leaves the list.
					tab.tabIndex = chosen ? 0 : -1;
					tab.classList.toggle( 'is-active', chosen );

					if ( panel ) {
						panel.hidden = ! chosen;
					}
				} );

				if ( moveFocus ) {
					tabs[ index ].focus();
				}
			}

			tabs.forEach( function ( tab, index ) {
				tab.addEventListener( 'click', function () {
					select( index, false );
				} );

				tab.addEventListener( 'keydown', function ( event ) {
					var last = tabs.length - 1;

					switch ( event.key ) {
						// Vertical list, so up and down are the ones that
						// matter — but left and right are bound too, because
						// a tab list is a tab list whichever way it is drawn.
						case 'ArrowDown':
						case 'ArrowRight':
							event.preventDefault();
							select( index === last ? 0 : index + 1, true );
							break;
						case 'ArrowUp':
						case 'ArrowLeft':
							event.preventDefault();
							select( index === 0 ? last : index - 1, true );
							break;
						case 'Home':
							event.preventDefault();
							select( 0, true );
							break;
						case 'End':
							event.preventDefault();
							select( last, true );
							break;
						default:
							break;
					}
				} );
			} );
		}
	};

	window.SugarCommerceFieldsModules.PanelTabs = PanelTabs;

	/* ====================================================================
	 * Merge tag chooser
	 * ================================================================= */

	var TagModal = {

		/**
		 * Bind every Add Tag button within a root.
		 *
		 * @param {Element} root Container.
		 */
		init: function ( root ) {
			root.querySelectorAll( '.field-kit__tag-button' ).forEach( function ( button ) {
				if ( button.dataset.fkBound ) {
					return;
				}

				button.dataset.fkBound = '1';
				TagModal.bind( button );
			} );
		},

		/**
		 * Wire one button to its dialog.
		 *
		 * @param {Element} button The Add Tag button.
		 */
		bind: function ( button ) {
			var modal = document.getElementById( button.dataset.modal );

			if ( ! modal ) {
				return;
			}

			var backdrop = modal.nextElementSibling;
			var search = modal.querySelector( '.field-kit__tag-search-input' );
			var empty = modal.querySelector( '.field-kit__tag-empty' );
			var rows = Array.prototype.slice.call( modal.querySelectorAll( '.field-kit__tag-items li' ) );

			function open() {
				modal.hidden = false;

				if ( backdrop ) {
					backdrop.hidden = false;
				}

				if ( search ) {
					search.value = '';
					filter( '' );
					search.focus();
				}

				document.addEventListener( 'keydown', onKey );
			}

			function close() {
				modal.hidden = true;

				if ( backdrop ) {
					backdrop.hidden = true;
				}

				document.removeEventListener( 'keydown', onKey );

				// Focus goes back where it came from, or it lands at the top
				// of the document and the next tab starts the page again.
				button.focus();
			}

			function onKey( event ) {
				if ( 'Escape' === event.key ) {
					close();

					return;
				}

				if ( 'ArrowDown' === event.key || 'ArrowUp' === event.key ) {
					step( 'ArrowDown' === event.key ? 1 : -1, event );
				}
			}

			/**
			 * Move through the rows that are showing with the arrow keys.
			 *
			 * Down from the search box lands on the first match, and up
			 * from the first row goes back to the box, so somebody who
			 * typed a word reaches its tag without leaving the keyboard
			 * for a Tab per row. Enter on a row is the button's own click.
			 *
			 * @param {number} by    1 for down, -1 for up.
			 * @param {Event}  event The key press.
			 */
			function step( by, event ) {
				var items = rows.filter( function ( row ) {
					return ! row.hidden;
				} ).map( function ( row ) {
					return row.querySelector( '.field-kit__tag-item' );
				} ).filter( Boolean );

				var at = items.indexOf( document.activeElement );

				// Only from the box or a row: the arrows belong to whatever
				// else has focus, which is the close button and nothing.
				if ( -1 === at && document.activeElement !== search ) {
					return;
				}

				var next = items[ at + by ];

				if ( ! next && by < 0 && search ) {
					next = search;
				}

				if ( next ) {
					event.preventDefault();
					next.focus();
				}
			}

			function filter( term ) {
				var needle = term.toLowerCase().trim();
				var shown = 0;

				// The heading of the group being walked through, and whether
				// any row under it is showing: a heading over nothing is
				// hidden, so a search leaves the matches and the names of the
				// groups they are in.
				var heading = null;

				rows.forEach( function ( row ) {
					if ( row.classList.contains( 'field-kit__tag-group' ) ) {
						heading = row;
						heading.hidden = true;

						return;
					}

					var item = row.querySelector( '.field-kit__tag-item' );
					var match = '' === needle
						|| ( item && item.dataset.search && item.dataset.search.indexOf( needle ) > -1 );

					row.hidden = ! match;

					if ( match ) {
						shown ++;

						if ( heading ) {
							heading.hidden = false;
						}
					}
				} );

				if ( empty ) {
					empty.hidden = shown > 0;
				}
			}

			button.addEventListener( 'click', open );

			var closer = modal.querySelector( '.field-kit__tag-close' );

			if ( closer ) {
				closer.addEventListener( 'click', close );
			}

			if ( backdrop ) {
				backdrop.addEventListener( 'click', close );
			}

			if ( search ) {
				search.addEventListener( 'input', function () {
					filter( search.value );
				} );
			}

			rows.forEach( function ( row ) {
				var item = row.querySelector( '.field-kit__tag-item' );

				if ( ! item ) {
					return;
				}

				item.addEventListener( 'click', function () {
					TagModal.insert( button.dataset.editor, item.dataset.tag || '' );
					close();
				} );
			} );
		},

		/**
		 * Put a tag into an editor.
		 *
		 * A visual editor is not an input at all — its content lives in an
		 * iframe — so writing to the textarea underneath it would be
		 * overwritten the moment the editor syncs.
		 *
		 * @param {string} editorId The editor's id.
		 * @param {string} tag      The tag to insert.
		 */
		insert: function ( editorId, tag ) {
			if ( '' === tag ) {
				return;
			}

			var editor = window.tinymce ? window.tinymce.get( editorId ) : null;

			if ( editor && ! editor.isHidden() ) {
				editor.execCommand( 'mceInsertContent', false, tag );

				// Written through to the textarea now. That is what a
				// Preview button beside the editor reads, and without this
				// a tag put in a moment ago was not in the preview.
				editor.save();

				return;
			}

			var textarea = document.getElementById( editorId );

			if ( ! textarea ) {
				return;
			}

			var start = textarea.selectionStart;
			var end = textarea.selectionEnd;

			if ( 'number' !== typeof start ) {
				textarea.value += tag;
			} else {
				textarea.value = textarea.value.slice( 0, start ) + tag + textarea.value.slice( end );
				textarea.selectionStart = start + tag.length;
				textarea.selectionEnd = textarea.selectionStart;
			}

			textarea.focus();
			textarea.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		}
	};

	window.SugarCommerceFieldsModules.TagModal = TagModal;

	/* ====================================================================
	 * Email panels
	 * ================================================================= */

	var EmailPanel = {

		/**
		 * Bind every email panel within a root.
		 *
		 * One behaviour the markup promises and core does not supply: a merge
		 * tag inserts itself where the author was last typing. Folding the
		 * panel is the Accordion module's, since the header is core's
		 * accordion trigger like every collapsible section's.
		 *
		 * @param {Element} root Container.
		 */
		init: function ( root ) {
			root.querySelectorAll( '.field-kit__email' ).forEach( function ( panel ) {
				if ( panel.dataset.fkBound ) {
					return;
				}

				panel.dataset.fkBound = '1';
				EmailPanel.bindTags( panel );
				EmailPanel.bindStatus( panel );
			} );
		},

		/**
		 * Keep the pill beside the name saying what its switch says.
		 *
		 * The pill is drawn from what was saved. Throw the switch and it
		 * would go on saying On beside a switch that is off, until a save
		 * and a reload agreed with it.
		 *
		 * @param {Element} panel The panel.
		 */
		bindStatus: function ( panel ) {
			var pill = panel.querySelector( '.privacy-settings-accordion-trigger [data-follows]' );

			if ( ! pill ) {
				return;
			}

			var follows = pill.dataset.follows;
			var input = null;

			// The switch is the checkbox whose name ends in the part's key;
			// the hidden input of the same name before it is its "off".
			panel.querySelectorAll( 'input[type="checkbox"][name]' ).forEach( function ( box ) {
				if ( ! input && box.name.slice( -( follows.length + 2 ) ) === '[' + follows + ']' ) {
					input = box;
				}
			} );

			if ( ! input ) {
				return;
			}

			input.addEventListener( 'change', function () {
				pill.textContent = input.checked ? pill.dataset.on : pill.dataset.off;
				pill.classList.toggle( 'field-kit__settings-card-status--success', input.checked );
				pill.classList.toggle( 'field-kit__settings-card-status--neutral', ! input.checked );
			} );
		},

		/**
		 * Insert a merge tag where the author was last typing.
		 *
		 * @param {Element} panel The panel.
		 */
		bindTags: function ( panel ) {
			var target = null;

			panel.querySelectorAll( 'input[type="text"], textarea' ).forEach( function ( input ) {
				input.addEventListener( 'focus', function () {
					target = input;
				} );
			} );

			panel.querySelectorAll( '.field-kit__email-tag' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					EmailPanel.insert( panel, target, button.dataset.tag || '' );
				} );
			} );
		},

		/**
		 * Put a tag into whichever editor is in play.
		 *
		 * @param {Element}      panel The panel.
		 * @param {Element|null} input The last focused input, if any.
		 * @param {string}       tag   The tag to insert.
		 */
		insert: function ( panel, input, tag ) {
			if ( '' === tag ) {
				return;
			}

			// A visual editor is not an input at all — its content lives in an
			// iframe — so writing to the textarea underneath it would be
			// overwritten the moment the editor syncs.
			var editor = EmailPanel.activeEditor( panel );

			if ( editor && ! editor.isHidden() ) {
				editor.execCommand( 'mceInsertContent', false, tag );

				return;
			}

			var field = input || panel.querySelector( 'textarea, input[type="text"]' );

			if ( ! field ) {
				return;
			}

			var start = field.selectionStart;
			var end = field.selectionEnd;

			if ( 'number' !== typeof start ) {
				field.value += tag;
			} else {
				field.value = field.value.slice( 0, start ) + tag + field.value.slice( end );
				field.selectionStart = start + tag.length;
				field.selectionEnd = field.selectionStart;
			}

			field.focus();
			field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		},

		/**
		 * The TinyMCE instance for this panel's body, if there is one.
		 *
		 * @param {Element} panel The panel.
		 * @return {Object|null} The editor.
		 */
		activeEditor: function ( panel ) {
			var textarea = panel.querySelector( 'textarea.wp-editor-area' );

			if ( ! textarea || ! window.tinymce ) {
				return null;
			}

			return window.tinymce.get( textarea.id ) || null;
		}
	};

	window.SugarCommerceFieldsModules.EmailPanel = EmailPanel;

	/* ====================================================================
	 * Buttons that run an action
	 * ================================================================= */

	var ActionButton = {

		/**
		 * Bind every action button within a root.
		 *
		 * One handler for licences, email previews, test sends and whatever
		 * a consumer wires up: they are all the same shape — post a payload,
		 * name a handler, show what came back.
		 *
		 * @param {Element} root Container.
		 */
		init: function ( root ) {
			if ( kit.bound( root, 'ActionButton' ) ) {
				return;
			}

			root.addEventListener( 'click', function ( event ) {
				var button = event.target.closest( '[data-endpoint][data-action]' );

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
		run: function ( button ) {
			if ( button.dataset.confirm && ! window.confirm( button.dataset.confirm ) ) {
				return;
			}

			// A button outside a field collects from its own container, never
			// from the document: that would send every named input on the
			// page -- other licence keys, password fields -- to this one's
			// endpoint.
			var wrap = button.closest( '.field-kit__field' ) || button.parentNode;
			var status = wrap.querySelector( '[aria-live]' );
			var spinner = wrap.querySelector( '.spinner' );

			ActionButton.syncEditors( wrap );

			var payload = ActionButton.payload( button, wrap );

			// Anything else on the page that belongs in this request can add
			// itself: a listener is handed the payload and writes to it. A
			// screen with one card for how every email looks sends that card
			// with each email's preview this way, and the field stays
			// ignorant of the screen it happens to be on.
			button.dispatchEvent( new CustomEvent( 'fieldkit:action-payload', {
				bubbles: true,
				detail: { action: button.dataset.action, payload: payload }
			} ) );

			// Disabled for the duration, or an impatient double click runs a
			// licence activation twice.
			button.disabled = true;

			if ( spinner ) {
				spinner.classList.add( 'is-active' );
			}

			kit.request( button.dataset.endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': button.dataset.nonce || config.restNonce || ''
				},
				body: JSON.stringify( {
					action: button.dataset.action,
					payload: payload
				} )
			} ).then( function ( response ) {
				return response.json().catch( function () {
					return { success: false, message: t( 'actionFailed', 'That did not work.' ) };
				} );
			} ).then( function ( result ) {
				ActionButton.report( wrap, status, result );
			} ).catch( function () {
				ActionButton.report( wrap, status, {
					success: false,
					message: t( 'actionFailed', 'That did not work.' )
				} );
			} ).finally( function () {
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
		syncEditors: function ( wrap ) {
			if ( ! window.tinymce ) {
				return;
			}

			wrap.querySelectorAll( 'textarea[id]' ).forEach( function ( textarea ) {
				var editor = window.tinymce.get( textarea.id );

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
		payload: function ( button, wrap ) {
			var payload = {};
			var key = wrap.dataset ? wrap.dataset.fieldKey : '';

			wrap.querySelectorAll( '[name]' ).forEach( function ( input ) {
				if ( input.type === 'checkbox' || input.type === 'radio' ) {
					if ( ! input.checked ) {
						return;
					}
				}

				// Bracketed names are the field's own structure; the last
				// segment is what a handler is going to look for.
				var name = input.name.replace( /^.*\[([^\]]+)\]$/, '$1' );

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
		report: function ( wrap, status, result ) {
			var message = result.message || ( result.success
				? t( 'actionDone', 'Done.' )
				: t( 'actionFailed', 'That did not work.' ) );

			if ( status ) {
				status.classList.remove( 'field-kit__status--success', 'field-kit__status--error' );
				status.classList.add( result.success ? 'field-kit__status--success' : 'field-kit__status--error' );
				kit.announce( status, message );
			}

			// A handler may hand back markup to show — an email preview, for
			// instance — which goes in a region of its own rather than into
			// the live region, where it would be read out in full.
			if ( result.data && result.data.html ) {
				var preview = wrap.querySelector( '.field-kit__action-preview' );

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
		applyState: function ( wrap, state ) {
			var licence = wrap.querySelector( '.field-kit__license' );

			if ( ! licence ) {
				return;
			}

			var active = !! state.active;
			var badge = wrap.querySelector( '.field-kit__license-state' );
			var text = wrap.querySelector( '.field-kit__license-state-text' );
			var glyph = badge && badge.querySelector( '.sugarcommerce-icon' );
			var button = wrap.querySelector( '.field-kit__license-action' );
			var input = wrap.querySelector( '.field-kit__license-key' );
			var meta = wrap.querySelector( '.field-kit__license-meta' );
			var sites = wrap.querySelector( '.field-kit__license-sites' );

			if ( badge ) {
				badge.classList.toggle( 'field-kit__license-state--active', active );
				badge.classList.toggle( 'field-kit__license-state--inactive', ! active );
			}

			if ( text ) {
				text.textContent = active
					? licence.dataset.labelActive
					: licence.dataset.labelInactive;
			}

			kit.swapIcon( glyph, active ? 'active' : 'inactive' );

			if ( button ) {
				button.textContent = active
					? button.dataset.labelDeactivate
					: button.dataset.labelActivate;

				// The registered name of the *other* action, so the next
				// press reaches the handler that undoes this one.
				button.dataset.action = active
					? ( button.dataset.actionDeactivate || '' )
					: ( button.dataset.actionActivate || '' );

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

			if ( sites && meta && Array.isArray( state.sites ) && 2 === state.sites.length ) {
				sites.hidden = false;
				sites.textContent = ( meta.dataset.sitesTemplate || '%1$s of %2$s sites' )
					.replace( '%1$s', String( state.sites[ 0 ] ) )
					.replace( '%2$s', String( state.sites[ 1 ] ) );
			}
		}
	};

	/* ====================================================================
	 * Tooltips
	 * ================================================================= */

	var Tooltip = {

		/**
		 * Bind every tooltip within a root.
		 *
		 * The panel is already in the markup and already hidden, so a tooltip
		 * that this never reaches is a button that does nothing rather than
		 * an explanation that does not exist — the panel is still associated
		 * with the button through aria-describedby, so it is still announced.
		 *
		 * @param {Element} root Container.
		 */
		init: function ( root ) {
			root.querySelectorAll( '.field-kit__tooltip' ).forEach( function ( wrap ) {
				if ( wrap.dataset.fkBound ) {
					return;
				}

				wrap.dataset.fkBound = '1';

				var button = wrap.querySelector( '.field-kit__tooltip-toggle' );
				var panel = wrap.querySelector( '.field-kit__tooltip-panel' );

				if ( ! button || ! panel ) {
					return;
				}

				function open() {
					panel.hidden = false;
					button.setAttribute( 'aria-expanded', 'true' );
					Tooltip.place( panel, button );
				}

				function close() {
					panel.hidden = true;
					button.setAttribute( 'aria-expanded', 'false' );
				}

				// Hover and focus both, because a tooltip that only opens on
				// hover is one that does not exist for anyone using a
				// keyboard — which is the failing this component was written
				// to avoid.
				wrap.addEventListener( 'mouseenter', open );
				wrap.addEventListener( 'mouseleave', close );
				button.addEventListener( 'focus', open );
				button.addEventListener( 'blur', close );

				// Click as well, for touch, where there is no hover at all.
				button.addEventListener( 'click', function ( event ) {
					event.preventDefault();

					if ( panel.hidden ) {
						open();
					} else {
						close();
					}
				} );

				button.addEventListener( 'keydown', function ( event ) {
					if ( 'Escape' === event.key && ! panel.hidden ) {
						event.stopPropagation();
						close();
					}
				} );
			} );
		},

		/**
		 * The left and right edges a panel has to stay inside.
		 *
		 * @param {Element} panel The panel.
		 * @return {{left: number, right: number}} Viewport coordinates.
		 */
		edges: function ( panel ) {
			var left = 0;
			var right = window.innerWidth || document.documentElement.clientWidth;
			var node = panel.parentElement;

			while ( node && node !== document.body ) {
				var style = window.getComputedStyle( node );

				if ( /(auto|scroll|hidden|clip)/.test( style.overflowX + style.overflowY ) ) {
					var rect = node.getBoundingClientRect();

					left = Math.max( left, rect.left );
					right = Math.min( right, rect.right );
				}

				node = node.parentElement;
			}

			return { left: left, right: right };
		},

		/**
		 * Keep the panel on the screen.
		 *
		 * It is centred on its icon, which is wrong twice: a tooltip on the
		 * first field of a metabox opens off the top of the scrolled area,
		 * and one on a label in the left-hand column of a settings table
		 * hangs half its width off the left of the window — far enough that
		 * the first words are behind the admin menu.
		 *
		 * So it flips below when there is no room above, and slides sideways
		 * when there is none beside. The arrow is moved back onto the icon by
		 * the same distance, because an arrow that no longer points at
		 * anything is worse than no arrow.
		 *
		 * @param {Element} panel  The panel.
		 * @param {Element} button The icon it belongs to.
		 */
		place: function ( panel, button ) {
			var gap = 8;

			// Measured from a clean slate: this runs again every time the
			// panel opens, and a shift left over from the last position would
			// be measured as though it were the natural one.
			delete panel.dataset.position;
			panel.style.transform = '';
			panel.style.removeProperty( '--fk-tooltip-arrow' );

			var box = panel.getBoundingClientRect();

			if ( box.top < gap ) {
				panel.dataset.position = 'below';
			}

			// The window, or whatever is nearer than the window and cuts off
			// what leaves it: a slide-in panel scrolls its own body, so a
			// tooltip near its left edge was still inside the window and lost
			// its first words to the panel's edge.
			var edges = this.edges( panel );
			var room = edges.right - gap;
			var shift = 0;

			if ( box.left < edges.left + gap ) {
				shift = edges.left + gap - box.left;
			} else if ( box.right > room ) {
				shift = room - box.right;
			}

			if ( 0 === shift ) {
				return;
			}

			panel.style.transform = 'translateX(calc(-50% + ' + Math.round( shift ) + 'px))';

			// The arrow follows the icon rather than the panel. Clamped
			// inside the panel's own corners, so it stays a pointer rather
			// than becoming a notch in the edge.
			var icon = button.getBoundingClientRect();
			var centre = icon.left + ( icon.width / 2 ) - ( box.left + shift );

			panel.style.setProperty(
				'--fk-tooltip-arrow',
				Math.round( Math.min( Math.max( centre, 12 ), box.width - 12 ) ) + 'px'
			);
		}
	};

	window.SugarCommerceFieldsModules.Tooltip = Tooltip;

	window.SugarCommerceFieldsModules.ActionButton = ActionButton;



	window.SugarCommerceFieldsModules.Repeater = Repeater;
	window.SugarCommerceFieldsModules.Media = Media;
	window.SugarCommerceFieldsModules.Tags = Tags;

	/* =================================================================
	 * Money
	 * ================================================================= */

	var Money = {

		/**
		 * Keep each amount's symbol in step with the currency of its row.
		 *
		 * Only where there is more than one currency to be in: a store with
		 * one gets static text from PHP and never reaches this.
		 *
		 * @param {Element} root Container.
		 */
		init: function ( root ) {
			root.querySelectorAll( '.field-kit__money[data-currency-key]' ).forEach( function ( wrap ) {
				if ( wrap.dataset.fkBound ) {
					return;
				}

				wrap.dataset.fkBound = '1';

				var source = Money.source( wrap );

				if ( ! source ) {
					return;
				}

				Money.apply( wrap, source.value );

				source.addEventListener( 'change', function () {
					Money.apply( wrap, source.value );
				} );
			} );
		},

		/**
		 * The control holding this amount's currency code.
		 *
		 * The kit's sibling lookup, which is scoped to the row: a repeater
		 * has one per row, and the first match in the form would give every
		 * row the first row's currency.
		 *
		 * @param {Element} wrap The money field.
		 * @return {Element|null} The control, if there is one.
		 */
		source: function ( wrap ) {
			return kit.sibling( wrap, wrap.dataset.currencyKey );
		},

		/**
		 * Draw the symbol for a code.
		 *
		 * @param {Element} wrap The money field.
		 * @param {string}  code The currency code.
		 */
		apply: function ( wrap, code ) {
			var symbol = wrap.querySelector( '.field-kit__money-symbol' );

			if ( ! symbol ) {
				return;
			}

			var symbols = {};

			try {
				symbols = JSON.parse( wrap.dataset.symbols || '{}' );
			} catch ( error ) {
				return;
			}

			// The code itself when it is not one we know, which is what PHP
			// draws too -- an empty affix is a control with a gap where its
			// symbol should be.
			symbol.textContent = symbols[ code ] || code || symbol.textContent;

			// The room kept for it follows it. PHP decides this for the
			// symbol it draws, and a switch from "£" to "CHF" here left
			// the code drawn over the first digits.
			wrap.classList.toggle( 'field-kit__money--wide', symbol.textContent.length > 1 );
		}
	};

	window.SugarCommerceFieldsModules.Money = Money;

	/* =================================================================
	 * Region
	 * ================================================================= */

	var Region = {

		/**
		 * Fit each region control to the country of its row.
		 *
		 * PHP drew two controls -- a text input, live, and a select, empty
		 * and disabled -- because it could not know the country at render
		 * time. This asks the server for that country's regions and keeps
		 * whichever control fits: the select where there is a list, the text
		 * input where there is not. Every step is guarded so that a field
		 * with no country beside it, or a request that fails, is left as it
		 * was drawn, with the text input live and submitting.
		 *
		 * @param {Element} root Container.
		 */
		init: function ( root ) {
			root.querySelectorAll( '.field-kit__region[data-country-key]' ).forEach( function ( wrap ) {
				if ( wrap.dataset.fkBound ) {
					return;
				}

				wrap.dataset.fkBound = '1';

				var source = kit.sibling( wrap, wrap.dataset.countryKey );

				if ( ! source ) {
					return;
				}

				Region.load( wrap, source.value );

				source.addEventListener( 'change', function () {
					// Another country, so whatever was chosen or typed for
					// the last one is not an answer any more: "TX" is not
					// a county, and Ontario's 'ON' is a state of Nigeria.
					// The first load keeps what was stored; a change of
					// country starts again.
					if ( source.value !== wrap.fieldKitCountry ) {
						Region.empty( wrap );
					}

					Region.load( wrap, source.value );
				} );
			} );
		},

		/**
		 * Fetch a country's regions and fit the control to them.
		 *
		 * Numbered, as the combobox numbers its searches: a slow answer for
		 * the previous country must not land after the answer for this one
		 * and put the wrong list in the select.
		 *
		 * @param {Element} wrap    The region field.
		 * @param {string}  country The country code.
		 */
		load: function ( wrap, country ) {
			var endpoint = wrap.dataset.searchEndpoint || ( config.restUrl ? config.restUrl + 'search' : '' );
			var ticket;
			var url;

			wrap.fieldKitCountry = country;

			wrap.fieldKitTicket = ( wrap.fieldKitTicket || 0 ) + 1;
			ticket = wrap.fieldKitTicket;

			if ( ! country || ! endpoint || typeof fetch !== 'function' ) {
				Region.apply( wrap, [] );

				return;
			}

			try {
				url = new URL( endpoint, window.location.origin );
			} catch ( error ) {
				Region.apply( wrap, [] );

				return;
			}

			url.searchParams.set( 'source', 'region' );
			url.searchParams.set( 'args[country]', country );

			kit.request( url, {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': wrap.dataset.searchNonce || config.restNonce || '' }
			} ).then( function ( response ) {
				return response.ok ? response.json() : { results: [] };
			} ).then( function ( data ) {
				if ( ticket === wrap.fieldKitTicket ) {
					Region.apply( wrap, data.results || [] );
				}
			} ).catch( function () {
				if ( ticket === wrap.fieldKitTicket ) {
					Region.apply( wrap, [] );
				}
			} );
		},

		/**
		 * Make one control live and the other inert.
		 *
		 * Only one may submit, so the one standing down is disabled as well
		 * as hidden: a hidden control still posts. Nothing is announced. A
		 * select appearing where a text box was is a change of control, not
		 * of state, and the label reads the same either way.
		 *
		 * @param {Element} wrap    The region field.
		 * @param {Array}   regions Rows of {id, text}; empty for a country without any.
		 */
		apply: function ( wrap, regions ) {
			var select = wrap.querySelector( '.field-kit__region-select' );
			var text = wrap.querySelector( '.field-kit__region-text' );

			if ( ! select || ! text ) {
				return;
			}

			// Whichever control is live holds the current value: the stored
			// one from the text input before the first swap, the chosen one
			// from the select after it.
			var current = select.disabled ? text.value : select.value;

			select.textContent = '';

			// A searchable select is inside the combobox built over it,
			// once one has been: that is then the thing to show and hide.
			var box = select.closest( '.field-kit__combobox' );

			if ( ! regions.length ) {
				select.disabled = true;
				select.hidden = true;
				text.disabled = false;
				text.hidden = false;

				if ( box ) {
					box.hidden = true;
				}

				return;
			}

			if ( ! select.hasAttribute( 'required' ) ) {
				select.appendChild( Region.option( '', select.dataset.placeholder || t( 'selectRegion', 'Select a region' ) ) );
			}

			regions.forEach( function ( region ) {
				var option = Region.option( String( region.id ), String( region.text ) );

				if ( option.value === current ) {
					option.selected = true;
				}

				select.appendChild( option );
			} );

			text.disabled = true;
			text.hidden = true;
			select.disabled = false;
			select.hidden = false;

			Region.search( select, box );
		},

		/**
		 * Put a search box over a select that asked for one.
		 *
		 * Built here and not with every other combobox on the page, because
		 * until now the select was empty: the combobox takes its placeholder
		 * and its way back to nothing from the empty option, and there was
		 * none. Once built it is only told that its list has changed.
		 *
		 * @param {HTMLSelectElement} select The region select, filled.
		 * @param {Element|null}      box    Its combobox, if it has one already.
		 */
		search: function ( select, box ) {
			var Combobox = window.SugarCommerceFieldsModules.Combobox;

			if ( 'true' !== select.dataset.searchable || ! Combobox ) {
				return;
			}

			if ( box ) {
				box.hidden = false;
				select.dispatchEvent( new Event( 'field-kit:refresh' ) );

				return;
			}

			select.classList.add( 'field-kit__select--enhanced' );
			select.dataset.fkBound = '1';
			Combobox.build( select );
		},

		/**
		 * Forget what was chosen or typed.
		 *
		 * @param {Element} wrap The region field.
		 */
		empty: function ( wrap ) {
			var select = wrap.querySelector( '.field-kit__region-select' );
			var text = wrap.querySelector( '.field-kit__region-text' );

			if ( text ) {
				text.value = '';
			}

			if ( select ) {
				select.textContent = '';
			}
		},

		/**
		 * One option.
		 *
		 * @param {string} value Its value.
		 * @param {string} label Its text.
		 * @return {Element} The option.
		 */
		option: function ( value, label ) {
			var option = document.createElement( 'option' );

			option.value = value;
			option.textContent = label;

			return option;
		}
	};

	window.SugarCommerceFieldsModules.Region = Region;

	/* ====================================================================
	 * Password reveal
	 * ================================================================= */

	var PasswordReveal = {

		/**
		 * Bind every reveal button within a root.
		 *
		 * The button is rendered hide-if-no-js, so one this never reaches
		 * is one nobody sees rather than one that does nothing.
		 *
		 * @param {Element} root Container.
		 */
		init: function ( root ) {
			root.querySelectorAll( '.field-kit__password-toggle' ).forEach( function ( button ) {
				if ( button.dataset.fkBound ) {
					return;
				}

				button.dataset.fkBound = '1';

				button.addEventListener( 'click', function () {
					PasswordReveal.toggle( button );
				} );
			} );
		},

		/**
		 * Show or hide what has been typed.
		 *
		 * The input's type is what does it; everything else -- the icon, the
		 * pressed state, the label -- is the button describing the state it
		 * has put the input in. Mirrors core's own toggle on the profile
		 * screen, which swaps the label along with the icon.
		 *
		 * @param {Element} button The toggle.
		 */
		toggle: function ( button ) {
			var wrap = button.closest( '.field-kit__password' );
			var input = wrap ? wrap.querySelector( 'input' ) : null;
			var glyph = button.querySelector( '.sugarcommerce-icon' );

			if ( ! input ) {
				return;
			}

			var show = 'password' === input.type;

			input.type = show ? 'text' : 'password';

			button.setAttribute( 'data-toggle', show ? '1' : '0' );
			button.setAttribute( 'aria-pressed', show ? 'true' : 'false' );
			button.setAttribute(
				'aria-label',
				show ? t( 'hidePassword', 'Hide password' ) : t( 'showPassword', 'Show password' )
			);

			kit.swapIcon( glyph, show ? 'hide' : 'show' );
		}
	};

	window.SugarCommerceFieldsModules.PasswordReveal = PasswordReveal;

	/* ====================================================================
	 * Password remove
	 * ================================================================= */

	/**
	 * The Remove beside a secret that has something stored.
	 *
	 * A password box is always drawn empty and an empty one is saved as
	 * "unchanged", so emptying it cannot mean "remove". The button says it
	 * instead: it enables the hidden flag the field set reads, says in the
	 * box what the save will do, and becomes Undo. Nothing is sent until
	 * the form is.
	 *
	 * Typing a new value takes the mark away again. The server would let
	 * the typed value win in any case; this keeps the box from promising a
	 * removal that will not happen.
	 */
	var PasswordRemove = {

		/**
		 * Bind every remove button within a root.
		 *
		 * @param {Element} root Container.
		 */
		init: function ( root ) {
			root.querySelectorAll( '.field-kit__password-remove' ).forEach( function ( button ) {
				var wrap = button.closest( '.field-kit__password' );
				var input = wrap ? wrap.querySelector( 'input:not([type="hidden"])' ) : null;

				if ( button.dataset.fkBound || ! input ) {
					return;
				}

				button.dataset.fkBound = '1';

				button.addEventListener( 'click', function () {
					PasswordRemove.mark( button, ! wrap.classList.contains( 'is-removing' ) );

					// The form has changed although no control was typed in.
					input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				} );

				input.addEventListener( 'input', function () {
					if ( '' !== input.value && wrap.classList.contains( 'is-removing' ) ) {
						PasswordRemove.mark( button, false );
					}
				} );
			} );
		},

		/**
		 * Mark a field for removal, or take the mark away.
		 *
		 * @param {Element} button   The remove button.
		 * @param {boolean} removing Whether the stored value is to go.
		 */
		mark: function ( button, removing ) {
			var wrap = button.closest( '.field-kit__password' );
			var input = wrap.querySelector( 'input:not([type="hidden"])' );
			var flag = wrap.querySelector( '.field-kit__password-clear' );

			if ( ! flag ) {
				return;
			}

			flag.disabled = ! removing;
			wrap.classList.toggle( 'is-removing', removing );

			if ( removing ) {
				// What the box said before, to put back on Undo.
				input.dataset.fkPlaceholder = input.getAttribute( 'placeholder' ) || '';
				input.value = '';
				input.setAttribute( 'placeholder', button.dataset.pending || '' );
			} else if ( undefined !== input.dataset.fkPlaceholder ) {
				input.setAttribute( 'placeholder', input.dataset.fkPlaceholder );
				delete input.dataset.fkPlaceholder;
			}

			button.textContent = removing ? button.dataset.undo : button.dataset.remove;
			button.setAttribute( 'aria-label', removing ? button.dataset.undoLabel : button.dataset.removeLabel );
		}
	};

	window.SugarCommerceFieldsModules.PasswordRemove = PasswordRemove;

	/* ====================================================================
	 * Character count
	 * ================================================================= */

	var Counter = {

		/**
		 * Bind every count within a root.
		 *
		 * The number is rendered by PHP from the saved value, so a count this
		 * never reaches is right until the first keystroke rather than blank.
		 *
		 * @param {Element} root Container.
		 */
		init: function ( root ) {
			root.querySelectorAll( '[data-counter]' ).forEach( function ( counter ) {
				if ( counter.dataset.fkBound ) {
					return;
				}

				counter.dataset.fkBound = '1';

				var control = Counter.control( counter );

				if ( ! control ) {
					return;
				}

				control.addEventListener( 'input', function () {
					Counter.update( counter, control );
				} );
			} );
		},

		/**
		 * The control a count belongs to.
		 *
		 * The one rendered immediately before it -- bare, or inside the
		 * wrapper an affix or a reveal button puts round it. Found from the
		 * count rather than by id, so a count in a repeater row cloned from
		 * the template follows its own input and not the first on the page.
		 *
		 * @param {Element} counter The count.
		 * @return {Element|null} The input or textarea, if there is one.
		 */
		control: function ( counter ) {
			var previous = counter.previousElementSibling;

			if ( ! previous ) {
				return null;
			}

			if ( 'INPUT' === previous.tagName || 'TEXTAREA' === previous.tagName ) {
				return previous;
			}

			return previous.querySelector( 'input, textarea' );
		},

		/**
		 * Write the current length.
		 *
		 * Counted in characters, as PHP counted the initial value, rather
		 * than in code units -- an emoji is one character to the person who
		 * typed it.
		 *
		 * @param {Element} counter The count.
		 * @param {Element} control The input or textarea.
		 */
		update: function ( counter, control ) {
			var max = parseInt( control.getAttribute( 'maxlength' ), 10 ) || 0;
			var length = Array.from( control.value || '' ).length;

			counter.textContent = length + ' / ' + max;
			counter.classList.toggle( 'field-kit__count--over', length > max );
		}
	};

	window.SugarCommerceFieldsModules.Counter = Counter;

	/* ====================================================================
	 * Bootstrap
	 * ================================================================= */


	/* ====================================================================
	 * Accordion sections
	 * ================================================================= */

	var Accordion = {

		/**
		 * Bind every accordion trigger within a root.
		 *
		 * Core's privacy guide binds its own triggers in privacy-tools.js,
		 * which only that screen loads; the markup is core's, the script
		 * has to be ours. Same behaviour: the button says whether its panel
		 * is expanded, and the panel is hidden when it is not.
		 *
		 * @param {Element} root Container.
		 */
		init: function ( root ) {
			root.querySelectorAll( '.field-kit__accordion .privacy-settings-accordion-trigger' ).forEach( function ( button ) {
				if ( button.dataset.fkBound ) {
					return;
				}

				button.dataset.fkBound = '1';

				button.addEventListener( 'click', function () {
					var open = 'true' === button.getAttribute( 'aria-expanded' );
					var panel = document.getElementById( button.getAttribute( 'aria-controls' ) || '' );

					button.setAttribute( 'aria-expanded', open ? 'false' : 'true' );

					if ( panel ) {
						panel.hidden = open;
					}
				} );
			} );
		}
	};

	window.SugarCommerceFieldsModules.Accordion = Accordion;

	/**
	 * The bar a settings page's save button rides in.
	 *
	 * wp-register-setting-fields draws "Saved" in it on the load that
	 * follows a clean save. That is true until something on the page
	 * changes, and from then on it is describing a form that no longer
	 * exists -- so the first edit replaces it with "Unsaved changes", which
	 * is true from then until the form is sent. The same element, and a
	 * status region: the change of words is what is announced.
	 *
	 * The bar is stuck to the foot of the window while there is more page
	 * under it and is the last thing on the page once there is not. Which
	 * of the two it is cannot be asked of CSS, so a one-pixel sentinel
	 * after the bar is watched: in view, the bar has come to rest and is
	 * drawn as the last line of the form (`is-resting`); out of view, it
	 * is floating over content and is the docked bar the stylesheet draws
	 * by default.
	 *
	 * A collapsed card also opens when a required control inside it stops
	 * the form being sent. The browser reports that against the control,
	 * and a control in a closed <details> cannot be focused or shown a
	 * message, so without this the Save button would appear to do nothing.
	 */
	var SettingsBar = {
		init: function ( root ) {
			// On a phone the tabs are one row that scrolls sideways, and
			// the tab being shown may be off the end of it.
			root.querySelectorAll( '.field-kit__settings-sidebar-link--current' ).forEach( function ( link ) {
				var list = link.closest( '.field-kit__settings-sidebar-list' );

				if ( list && list.scrollWidth > list.clientWidth ) {
					list.scrollLeft = link.offsetLeft - list.offsetLeft - ( list.clientWidth - link.offsetWidth ) / 2;
				}
			} );

			root.querySelectorAll( '.field-kit__settings--modern form' ).forEach( function ( form ) {
				var status = form.querySelector( '.field-kit__settings-status' );

				if ( form.dataset.fkBarBound ) {
					return;
				}

				form.dataset.fkBarBound = '1';

				function changed() {
					if ( status && ! status.classList.contains( 'is-unsaved' ) ) {
						status.classList.add( 'is-unsaved' );
						status.textContent = t( 'unsavedChanges', 'Unsaved changes' );
					}
				}

				form.addEventListener( 'input', changed );
				form.addEventListener( 'change', changed );

				SettingsBar.watch( form.querySelector( '.field-kit__settings-savebar' ) );

				// Capturing: `invalid` does not bubble.
				form.addEventListener( 'invalid', function ( event ) {
					var card = event.target.closest( 'details.field-kit__settings-card' );

					if ( card ) {
						card.open = true;
					}
				}, true );
			} );
		},

		/**
		 * Say on the bar whether it is floating over the page or at rest.
		 *
		 * @param {Element|null} bar The save bar.
		 */
		watch: function ( bar ) {
			if ( ! bar || ! window.IntersectionObserver ) {
				return;
			}

			var sentinel = document.createElement( 'span' );

			sentinel.className = 'field-kit__settings-savebar-end';
			sentinel.setAttribute( 'aria-hidden', 'true' );
			bar.insertAdjacentElement( 'afterend', sentinel );

			new window.IntersectionObserver( function ( entries ) {
				bar.classList.toggle( 'is-resting', entries[ 0 ].isIntersecting );
			} ).observe( sentinel );
		}
	};

	window.SugarCommerceFieldsModules.SettingsBar = SettingsBar;

	/**
	 * Bind every module within a root.
	 *
	 * Safe to call again: each module marks what it has bound, so newly
	 * inserted markup — a repeater row, a flyout that just opened — can be
	 * initialised without rebinding what is already live.
	 *
	 * @param {Element} root Container, defaulting to the document.
	 */
	function init( root ) {
		root = root || document;

		[ 'Conditions', 'Range', 'Toggle', 'Clipboard', 'CodeGenerator', 'Combobox', 'Reorder', 'Repeater', 'Media', 'Tags', 'CodeEditor', 'Wysiwyg', 'ColorPicker', 'TagModal', 'PanelTabs', 'EmailPanel', 'ActionButton', 'Tooltip', 'ColorField', 'IconPicker', 'Money', 'PasswordReveal', 'PasswordRemove', 'Counter', 'Region', 'Accordion', 'SettingsBar' ].forEach( function ( name ) {
			var module = window.SugarCommerceFieldsModules[ name ];

			if ( module && typeof module.init === 'function' ) {
				module.init( root );
			}
		} );
	}

	window.SugarCommerceFieldsModules.init = init;

	/**
	 * Let go of what the modules started within a root that is about to be
	 * removed: a flyout closing, a row deleted.
	 *
	 * Only a module that holds something outside the markup -- a TinyMCE
	 * instance is kept by id in TinyMCE's own registry -- has anything to
	 * do. Left alone, the same id coming back on a new copy of the markup
	 * found the old instance still registered.
	 *
	 * @param {Element} root Container.
	 */
	function destroy( root ) {
		if ( ! root ) {
			return;
		}

		Object.keys( window.SugarCommerceFieldsModules ).forEach( function ( name ) {
			var module = window.SugarCommerceFieldsModules[ name ];

			if ( module && typeof module === 'object' && typeof module.destroy === 'function' ) {
				module.destroy( root );
			}
		} );
	}

	window.SugarCommerceFieldsModules.destroy = destroy;

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			init( document );
		} );
	} else {
		init( document );
	}

	// Run once more when everything has loaded. Each module marks what it has
	// bound, so this rebinds nothing — it only picks up a control whose
	// library was not there the first time. Belt and braces: the failure mode
	// when a jQuery plugin has not loaded yet is that the field silently
	// stays a plain input, with nothing in the console to explain it.
	window.addEventListener( 'load', function () {
		init( document );
	} );
} )();
