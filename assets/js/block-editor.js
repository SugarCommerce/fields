/**
 * SugarCommerce Fields: the block editor's sidebar
 *
 * Draws a metabox's fields in the document sidebar, with the editor's own
 * controls, reading and writing the post's meta through the editor's store.
 * The editor saves that meta over REST with the rest of the post, so there is
 * no save here: a change is an edit to the post, undone by the editor's undo
 * and marked unsaved like any other.
 *
 * Plain script, no build: wp.element.createElement rather than JSX, and
 * nothing a browser that runs the block editor does not already understand.
 *
 * The fields come from PHP, resolved and translated. They are found the way
 * the kit's own script finds its configuration -- by the id WordPress stamps
 * on this script's element -- so a second plugin bundling a prefixed copy of
 * the library loads its own copy with its own fields.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.element || ! wp.components || ! wp.data ) {
		return;
	}

	var config = ( function () {
		var el = document.currentScript;
		var handle = el && el.id ? el.id.replace( /-js$/, '' ) : '';
		var registry = window.SugarCommerceFields || {};

		return registry[ handle ] || {};
	} )();

	// The editor's since WordPress 6.6; edit-post's is a copy that warns.
	var DocumentPanel =
		( wp.editor && wp.editor.PluginDocumentSettingPanel ) ||
		( wp.editPost && wp.editPost.PluginDocumentSettingPanel );

	if ( ! DocumentPanel || ! Array.isArray( config.panels ) ) {
		return;
	}

	var h = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var c = wp.components;

	/**
	 * Equality the way PHP's == has it, near enough for a condition.
	 *
	 * The conditions are the metabox's, written for PHP: `true` matches a
	 * ticked box stored as 1, and "5" matches 5. Strict equality would leave
	 * the same field shown in one place and hidden in the other.
	 *
	 * @param {*} a One value.
	 * @param {*} b The other.
	 * @return {boolean} Whether PHP would call them equal.
	 */
	function looseEquals( a, b ) {
		if ( typeof a === 'boolean' || typeof b === 'boolean' ) {
			return truthy( a ) === truthy( b );
		}

		if ( a === null || a === undefined || b === null || b === undefined ) {
			return ! truthy( a ) && ! truthy( b );
		}

		if ( isNumeric( a ) && isNumeric( b ) ) {
			return Number( a ) === Number( b );
		}

		return String( a ) === String( b );
	}

	function isNumeric( value ) {
		return (
			( typeof value === 'number' && isFinite( value ) ) ||
			( typeof value === 'string' && value.trim() !== '' && isFinite( Number( value ) ) )
		);
	}

	/**
	 * Truthiness as PHP's empty() sees it: "0" is empty.
	 *
	 * @param {*} value The value.
	 * @return {boolean} Whether it is not empty.
	 */
	function truthy( value ) {
		if ( Array.isArray( value ) ) {
			return value.length > 0;
		}

		return !! value && value !== '0';
	}

	/**
	 * Whether one condition holds, by the operators Conditions knows.
	 *
	 * @param {Object} condition The condition: field, operator, value.
	 * @param {*}      current   The named field's value.
	 * @return {boolean} Whether it holds.
	 */
	function holds( condition, current ) {
		var expected = condition.value;
		var list = Array.isArray( expected ) ? expected : [ expected ];

		switch ( condition.operator ) {
			case '!=':
				return ! looseEquals( current, expected );
			case '>':
				return Number( current ) > Number( expected );
			case '>=':
				return Number( current ) >= Number( expected );
			case '<':
				return Number( current ) < Number( expected );
			case '<=':
				return Number( current ) <= Number( expected );
			case 'in':
				return list.some( function ( one ) {
					return looseEquals( current, one );
				} );
			case 'not_in':
				return ! list.some( function ( one ) {
					return looseEquals( current, one );
				} );
			case 'contains':
				return typeof current === 'string' && String( expected ) !== '' && current.indexOf( String( expected ) ) !== -1;
			case 'not_contains':
				return typeof current !== 'string' || String( expected ) === '' || current.indexOf( String( expected ) ) === -1;
			case 'empty':
				return ! truthy( current );
			case 'not_empty':
				return truthy( current );
			default:
				return looseEquals( current, expected );
		}
	}

	function shown( field, meta ) {
		return ( field.conditions || [] ).every( function ( condition ) {
			return holds( condition, meta[ condition.field ] );
		} );
	}

	/**
	 * What an empty control sends: null, which REST reads as "delete".
	 *
	 * An empty string would be stored as one, where the metabox deletes an
	 * empty value -- and a field with a default would then read back blank.
	 *
	 * @param {*} value The control's value.
	 * @return {*} The value to store.
	 */
	function stored( value ) {
		return value === '' || value === undefined ? null : value;
	}

	function text( value ) {
		return value === null || value === undefined ? '' : String( value );
	}

	/**
	 * A number box, holding what is typed until it is a number.
	 *
	 * The editor's store holds the number. A box bound straight to it would
	 * turn "1." into 1 as it was typed, and a decimal could never be entered.
	 *
	 * @param {Object} props The field, its value and the setter.
	 * @return {Object} The control.
	 */
	function NumberField( props ) {
		var field = props.field;
		var state = useState( text( props.value ) );
		var typed = state[ 0 ];
		var setTyped = state[ 1 ];

		// Undo, or another control, changed the value: show it.
		useEffect(
			function () {
				var parsed = typed.trim() === '' ? null : Number( typed );

				if ( parsed !== ( props.value === undefined ? null : props.value ) ) {
					setTyped( text( props.value ) );
				}
			},
			[ props.value ]
		);

		return h( c.TextControl, {
			__next40pxDefaultSize: true,
			__nextHasNoMarginBottom: true,
			type: 'number',
			label: field.label,
			help: field.help || undefined,
			value: typed,
			min: field.min === null ? undefined : field.min,
			max: field.max === null ? undefined : field.max,
			step: field.step === null ? undefined : field.step,
			placeholder: field.placeholder || undefined,
			onChange: function ( next ) {
				setTyped( next );

				if ( String( next ).trim() === '' ) {
					props.onChange( null );
					return;
				}

				var number = field.integer ? parseInt( next, 10 ) : parseFloat( next );

				if ( ! isNaN( number ) ) {
					props.onChange( number );
				}
			},
		} );
	}

	/**
	 * A label above a control that has no single input to point one at.
	 *
	 * @param {Object} props Label, help and the control.
	 * @return {Object} The group.
	 */
	function Group( props ) {
		// The editor's own label, drawn as the legend: its look comes from
		// the component rather than a class, so a plain legend would not
		// match the labels around it.
		var Label = c.BaseControl && c.BaseControl.VisualLabel;

		return h(
			'fieldset',
			{
				className: 'sugarcommerce-fields-panel__group',
				style: { border: 0, margin: 0, padding: 0, minWidth: 0 },
			},
			Label ? h( Label, { as: 'legend' }, props.label ) : h( 'legend', null, props.label ),
			props.children,
			props.help
				? h(
						'p',
						{
							className: 'components-base-control__help',
							style: { marginTop: '8px', marginBottom: 0, fontSize: '12px', color: '#757575' },
						},
						props.help
				  )
				: null
		);
	}

	function options( list ) {
		return list.map( function ( option ) {
			if ( option.options ) {
				return h( 'optgroup', { key: 'g:' + option.label, label: option.label }, options( option.options ) );
			}

			return h( 'option', { key: option.value, value: option.value }, option.label );
		} );
	}

	/**
	 * The control for one field.
	 *
	 * @param {Object}   field    The field, as PHP described it.
	 * @param {*}        value    Its value in the editor's store.
	 * @param {Function} onChange Stores a new value.
	 * @return {Object} The control.
	 */
	function control( field, value, onChange ) {
		var common = {
			__next40pxDefaultSize: true,
			__nextHasNoMarginBottom: true,
			label: field.label,
			help: field.help || undefined,
		};

		function extend( extra ) {
			return Object.assign( {}, common, extra );
		}

		switch ( field.control ) {
			case 'number':
				return h( NumberField, { field: field, value: value, onChange: onChange } );

			case 'range':
				return h(
					c.RangeControl,
					extend( {
						value: value === null || value === undefined ? undefined : Number( value ),
						min: field.min === null ? undefined : field.min,
						max: field.max === null ? undefined : field.max,
						step: field.step === null ? undefined : field.step,
						allowReset: true,
						onChange: function ( next ) {
							onChange( stored( next ) );
						},
					} )
				);

			case 'textarea':
				return h(
					c.TextareaControl,
					extend( {
						value: text( value ),
						placeholder: field.placeholder || undefined,
						maxLength: field.maxLength || undefined,
						onChange: function ( next ) {
							onChange( stored( next ) );
						},
					} )
				);

			case 'select':
				return h(
					c.SelectControl,
					extend( {
						value: text( value ),
						onChange: function ( next ) {
							onChange( stored( next ) );
						},
					} ),
					field.emptyLabel === null ? null : h( 'option', { value: '' }, field.emptyLabel ),
					options( field.options || [] )
				);

			case 'button_group':
				// A row of buttons 280 pixels wide holds three or so before the
				// labels are cut; past that it is a list of radios.
				if ( c.__experimentalToggleGroupControl && ( field.options || [] ).length <= 3 ) {
					return h(
						c.__experimentalToggleGroupControl,
						extend( {
							isBlock: true,
							value: text( value ) || undefined,
							onChange: function ( next ) {
								onChange( stored( next ) );
							},
						} ),
						( field.options || [] ).map( function ( option ) {
							return h( c.__experimentalToggleGroupControlOption, {
								key: option.value,
								value: option.value,
								label: option.label,
							} );
						} )
					);
				}
			// Falls through: radios.

			case 'radio':
				return h(
					c.RadioControl,
					extend( {
						selected: text( value ),
						options: field.options || [],
						onChange: function ( next ) {
							onChange( stored( next ) );
						},
					} )
				);

			case 'checkbox':
			case 'toggle':
				var box = h(
					'toggle' === field.control ? c.ToggleControl : c.CheckboxControl,
					extend( {
						label: field.checkboxLabel || field.label,
						help: field.checkboxLabel ? undefined : common.help,
						checked: truthy( value ),
						// 0 rather than null: the metabox stores an unticked
						// box as 0, and a default of "ticked" would otherwise
						// come back the moment it was unticked.
						onChange: function ( next ) {
							onChange( next ? 1 : 0 );
						},
					} )
				);

				// A box with words of its own beside it still has the field's
				// label, which the metabox shows in the row's heading.
				return field.checkboxLabel && field.checkboxLabel !== field.label
					? h( Group, { label: field.label, help: field.help }, box )
					: box;

			case 'color':
				return h(
					Group,
					{ label: field.label, help: field.help },
					h( c.ColorPalette, {
						colors: field.presets || [],
						value: text( value ) || undefined,
						clearable: true,
						enableAlpha: false,
						onChange: function ( next ) {
							onChange( stored( next ) );
						},
					} )
				);

			default:
				// text, email, url, tel, date, time and datetime: one input,
				// of the type the metabox draws, storing the string it sends.
				return h(
					c.TextControl,
					extend( {
						type: 'datetime' === field.control ? 'datetime-local' : field.control,
						value: text( value ),
						placeholder: field.placeholder || undefined,
						maxLength: field.maxLength || undefined,
						onChange: function ( next ) {
							onChange( stored( next ) );
						},
					} )
				);
		}
	}

	/**
	 * One metabox's fields, as a panel in the document sidebar.
	 *
	 * @param {Object} props The panel, as PHP described it.
	 * @return {Object} The panel.
	 */
	function Panel( props ) {
		var panel = props.panel;

		var meta = wp.data.useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
		}, [] );

		var editPost = wp.data.useDispatch( 'core/editor' ).editPost;

		// The editor merges a meta edit into the meta it holds, so one key
		// is all an edit has to carry.
		function set( key ) {
			return function ( value ) {
				var edit = {};

				edit[ key ] = value;
				editPost( { meta: edit } );
			};
		}

		return h(
			DocumentPanel,
			{
				name: panel.name,
				title: panel.title,
				className: 'sugarcommerce-fields-panel',
			},
			h(
				'div',
				{ style: { display: 'grid', gap: '16px', minWidth: 0 } },
				panel.fields
					.filter( function ( field ) {
						return shown( field, meta );
					} )
					.map( function ( field ) {
						return h(
							'div',
							{ key: field.key, className: 'sugarcommerce-fields-panel__field', style: { minWidth: 0 } },
							control( field, meta[ field.key ], set( field.key ) )
						);
					} )
			)
		);
	}

	config.panels.forEach( function ( panel ) {
		if ( wp.plugins.getPlugin( panel.plugin ) ) {
			return;
		}

		wp.plugins.registerPlugin( panel.plugin, {
			render: function () {
				return h( Panel, { panel: panel } );
			},
		} );
	} );
} )( window.wp );
