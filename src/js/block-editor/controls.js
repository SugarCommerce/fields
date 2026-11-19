/**
 * The editor's own control for each field, and the value it stores.
 *
 * createElement rather than JSX: the elements are the editor's components,
 * named by what PHP said the field is, and there is no markup to write.
 */

import * as c from '@wordpress/components';
import { createElement as h, useEffect, useState } from '@wordpress/element';

import { truthy } from './conditions';

/**
 * What an empty control sends: null, which REST reads as "delete".
 *
 * An empty string would be stored as one, where the metabox deletes an
 * empty value -- and a field with a default would then read back blank.
 *
 * @param {*} value The control's value.
 * @return {*} The value to store.
 */
export function stored( value ) {
	return value === '' || value === undefined ? null : value;
}

/**
 * A value as the text a box shows.
 *
 * @param {*} value The value.
 * @return {string} The text, '' for nothing.
 */
export function text( value ) {
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
export function NumberField( props ) {
	const field = props.field;
	const [ typed, setTyped ] = useState( text( props.value ) );

	// Undo, or another control, changed the value: show it.
	useEffect( () => {
		const parsed = typed.trim() === '' ? null : Number( typed );

		if ( parsed !== ( props.value === undefined ? null : props.value ) ) {
			setTyped( text( props.value ) );
		}
		// Only when the stored value moves: what is typed is the box's own.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ props.value ] );

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
		onChange( next ) {
			setTyped( next );

			if ( String( next ).trim() === '' ) {
				props.onChange( null );
				return;
			}

			const number = field.integer
				? parseInt( next, 10 )
				: parseFloat( next );

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
export function Group( props ) {
	// The editor's own label, drawn as the legend: its look comes from
	// the component rather than a class, so a plain legend would not
	// match the labels around it.
	const Label = c.BaseControl && c.BaseControl.VisualLabel;

	return h(
		'fieldset',
		{
			className: 'sugarcommerce-fields-panel__group',
			style: { border: 0, margin: 0, padding: 0, minWidth: 0 },
		},
		Label
			? h( Label, { as: 'legend' }, props.label )
			: h( 'legend', null, props.label ),
		props.children,
		props.help
			? h(
					'p',
					{
						className: 'components-base-control__help',
						style: {
							marginTop: '8px',
							marginBottom: 0,
							fontSize: '12px',
							color: '#757575',
						},
					},
					props.help
				)
			: null
	);
}

/**
 * A select's options, with their groups.
 *
 * @param {Array<Object>} list `{ value, label }`, or `{ label, options }`.
 * @return {Array<Object>} Elements.
 */
export function options( list ) {
	return list.map( ( option ) => {
		if ( option.options ) {
			return h(
				'optgroup',
				{ key: 'g:' + option.label, label: option.label },
				options( option.options )
			);
		}

		return h(
			'option',
			{ key: option.value, value: option.value },
			option.label
		);
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
export function control( field, value, onChange ) {
	const common = {
		__next40pxDefaultSize: true,
		__nextHasNoMarginBottom: true,
		label: field.label,
		help: field.help || undefined,
	};
	const extend = ( extra ) => Object.assign( {}, common, extra );
	const store = ( next ) => onChange( stored( next ) );

	switch ( field.control ) {
		case 'number':
			return h( NumberField, { field, value, onChange } );

		case 'range':
			return h(
				c.RangeControl,
				extend( {
					value:
						value === null || value === undefined
							? undefined
							: Number( value ),
					min: field.min === null ? undefined : field.min,
					max: field.max === null ? undefined : field.max,
					step: field.step === null ? undefined : field.step,
					allowReset: true,
					onChange: store,
				} )
			);

		case 'textarea':
			return h(
				c.TextareaControl,
				extend( {
					value: text( value ),
					placeholder: field.placeholder || undefined,
					maxLength: field.maxLength || undefined,
					onChange: store,
				} )
			);

		case 'select':
			return h(
				c.SelectControl,
				extend( {
					value: text( value ),
					onChange: store,
				} ),
				field.emptyLabel === null
					? null
					: h( 'option', { value: '' }, field.emptyLabel ),
				options( field.options || [] )
			);

		case 'button_group':
			// A row of buttons 280 pixels wide holds three or so before the
			// labels are cut; past that it is a list of radios.
			if (
				c.__experimentalToggleGroupControl &&
				( field.options || [] ).length <= 3
			) {
				return h(
					c.__experimentalToggleGroupControl,
					extend( {
						isBlock: true,
						value: text( value ) || undefined,
						onChange: store,
					} ),
					( field.options || [] ).map( ( option ) =>
						h( c.__experimentalToggleGroupControlOption, {
							key: option.value,
							value: option.value,
							label: option.label,
						} )
					)
				);
			}
		// Falls through: radios.

		case 'radio':
			return h(
				c.RadioControl,
				extend( {
					selected: text( value ),
					options: field.options || [],
					onChange: store,
				} )
			);

		case 'checkbox':
		case 'toggle': {
			const box = h(
				'toggle' === field.control
					? c.ToggleControl
					: c.CheckboxControl,
				extend( {
					label: field.checkboxLabel || field.label,
					help: field.checkboxLabel ? undefined : common.help,
					checked: truthy( value ),
					// 0 rather than null: the metabox stores an unticked
					// box as 0, and a default of "ticked" would otherwise
					// come back the moment it was unticked.
					onChange: ( next ) => onChange( next ? 1 : 0 ),
				} )
			);

			// A box with words of its own beside it still has the field's
			// label, which the metabox shows in the row's heading.
			return field.checkboxLabel && field.checkboxLabel !== field.label
				? h( Group, { label: field.label, help: field.help }, box )
				: box;
		}

		case 'color':
			return h(
				Group,
				{ label: field.label, help: field.help },
				h( c.ColorPalette, {
					colors: field.presets || [],
					value: text( value ) || undefined,
					clearable: true,
					enableAlpha: false,
					onChange: store,
				} )
			);

		default:
			// text, email, url, tel, date, time and datetime: one input,
			// of the type the metabox draws, storing the string it sends.
			return h(
				c.TextControl,
				extend( {
					type:
						'datetime' === field.control
							? 'datetime-local'
							: field.control,
					value: text( value ),
					placeholder: field.placeholder || undefined,
					maxLength: field.maxLength || undefined,
					onChange: store,
				} )
			);
	}
}
