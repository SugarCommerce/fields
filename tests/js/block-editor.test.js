/**
 * The block editor's sidebar script.
 *
 * The PHP suite says what the script is handed; nothing there runs it. This
 * runs it against stand-ins for the editor's packages -- elements recorded as
 * plain objects, function components called in place -- and checks the parts
 * that would fail without a word in the editor: that each panel registers,
 * that a field's control is the one PHP named, that a condition written for
 * PHP hides and shows the same way here, and that a change reaches the
 * editor's store as the value the metabox would have stored.
 */

import { beforeAll, describe, expect, test, vi } from 'vitest';

const editor = vi.hoisted( () => ( {
	plugins: {},
	edits: [],
	meta: {},
} ) );

vi.mock( '@wordpress/plugins', () => ( {
	getPlugin: ( name ) => editor.plugins[ name ],
	registerPlugin: ( name, settings ) => {
		editor.plugins[ name ] = settings;
	},
} ) );

vi.mock( '@wordpress/element', () => ( {
	createElement: ( type, props, ...children ) => ( {
		type,
		props: Object.assign( {}, props, { children: children.flat() } ),
	} ),
	useState: ( initial ) => [ initial, () => {} ],
	useEffect: () => {},
} ) );

vi.mock( '@wordpress/components', () =>
	Object.fromEntries(
		[
			'TextControl',
			'TextareaControl',
			'SelectControl',
			'RadioControl',
			'CheckboxControl',
			'ToggleControl',
			'RangeControl',
			'ColorPalette',
			'__experimentalToggleGroupControl',
			'__experimentalToggleGroupControlOption',
		]
			.map( ( name ) => [ name, name ] )
			// No visual label: a group falls back to a plain legend.
			.concat( [ [ 'BaseControl', undefined ] ] )
	)
);

vi.mock( '@wordpress/editor', () => ( {
	PluginDocumentSettingPanel: 'PluginDocumentSettingPanel',
} ) );

vi.mock( '@wordpress/data', () => ( {
	useSelect: ( callback ) =>
		callback( () => ( { getEditedPostAttribute: () => editor.meta } ) ),
	useDispatch: () => ( {
		editPost: ( edit ) => editor.edits.push( edit ),
	} ),
} ) );

const handle = 'sugarcommerce-fields-block-editor';

/**
 * Call function components until only host elements and controls are left.
 *
 * @param {*} node An element, or what a component returned.
 * @return {*} The tree with every function component expanded.
 */
function expand( node ) {
	if ( Array.isArray( node ) ) {
		return node.map( expand );
	}

	if ( ! node || typeof node !== 'object' ) {
		return node;
	}

	if ( typeof node.type === 'function' ) {
		return expand( node.type( node.props ) );
	}

	return Object.assign( {}, node, {
		props: Object.assign( {}, node.props, {
			children: expand( node.props.children ),
		} ),
	} );
}

function find( node, test_, found = [] ) {
	if ( Array.isArray( node ) ) {
		node.forEach( ( one ) => find( one, test_, found ) );
	} else if ( node && typeof node === 'object' ) {
		if ( test_( node ) ) {
			found.push( node );
		}

		find( node.props.children, test_, found );
	}

	return found;
}

function control( tree, label ) {
	return find(
		tree,
		( node ) => typeof node.type === 'string' && node.props.label === label
	)[ 0 ];
}

const draw = () => expand( editor.plugins[ handle + '-demo-box' ].render() );

beforeAll( async () => {
	Object.defineProperty( document, 'currentScript', {
		value: { id: handle + '-js' },
		configurable: true,
	} );

	window.SugarCommerceFields = {
		[ handle ]: {
			panels: [
				{
					plugin: handle + '-demo-box',
					name: 'demo-box',
					title: 'Demo',
					fields: [
						{
							key: 'tagline',
							control: 'text',
							label: 'Tagline',
							help: '',
							placeholder: '',
							conditions: [],
							maxLength: 0,
						},
						{
							key: 'price',
							control: 'number',
							label: 'Price',
							help: '',
							placeholder: '',
							conditions: [],
							integer: false,
							min: 0,
							max: null,
							step: 0.01,
						},
						{
							key: 'when',
							control: 'datetime',
							label: 'When',
							help: '',
							placeholder: '',
							conditions: [],
							maxLength: 0,
						},
						{
							key: 'size',
							control: 'select',
							label: 'Size',
							help: '',
							placeholder: '',
							conditions: [],
							emptyLabel: '— Select —',
							options: [
								{ value: 's', label: 'Small' },
								{
									label: 'Big',
									options: [
										{ value: 'xl', label: 'Extra large' },
									],
								},
							],
						},
						{
							key: 'layout',
							control: 'button_group',
							label: 'Layout',
							help: '',
							placeholder: '',
							conditions: [],
							options: [
								{ value: 'grid', label: 'Grid' },
								{ value: 'list', label: 'List' },
							],
						},
						{
							key: 'featured',
							control: 'toggle',
							label: 'Featured',
							help: '',
							placeholder: '',
							conditions: [],
							checkboxLabel: '',
						},
						{
							key: 'note',
							control: 'textarea',
							label: 'Note',
							help: '',
							placeholder: '',
							maxLength: 0,
							// Written for PHP: true matches a box stored as 1.
							conditions: [
								{
									field: 'featured',
									operator: '=',
									value: true,
								},
							],
						},
						{
							key: 'colour',
							control: 'color',
							label: 'Colour',
							help: 'Pick one',
							placeholder: '',
							conditions: [],
							presets: [ { color: '#ff0000', name: 'Red' } ],
						},
					],
				},
			],
		},
	};

	await import( '../../src/js/block-editor.js' );
} );

describe( 'the block editor sidebar', () => {
	test( 'registers each panel under its build-specific name', () => {
		expect( editor.plugins[ handle + '-demo-box' ] ).toBeTruthy();
	} );

	test( 'draws each control PHP named, and hides a field whose condition fails', () => {
		editor.meta = { featured: 0 };

		const tree = draw();

		expect(
			find( tree, ( node ) => node.type === 'PluginDocumentSettingPanel' )
		).toHaveLength( 1 );
		expect( control( tree, 'Tagline' ).type ).toBe( 'TextControl' );
		expect( control( tree, 'Price' ).props.type ).toBe( 'number' );
		expect( control( tree, 'When' ).props.type ).toBe( 'datetime-local' );
		expect( control( tree, 'Size' ).type ).toBe( 'SelectControl' );
		expect( control( tree, 'Layout' ).type ).toBe(
			'__experimentalToggleGroupControl'
		);
		expect( control( tree, 'Featured' ).type ).toBe( 'ToggleControl' );
		expect( control( tree, 'Note' ) ).toBeUndefined();
		expect(
			find( tree, ( node ) => node.type === 'ColorPalette' )
		).toHaveLength( 1 );
		// A select keeps its groups.
		expect(
			find( tree, ( node ) => node.type === 'optgroup' )
		).toHaveLength( 1 );
	} );

	test( 'stores one meta key, as the value the metabox would store', () => {
		editor.meta = { featured: 0 };

		const tree = draw();

		control( tree, 'Featured' ).props.onChange( true );
		expect( editor.edits.pop() ).toEqual( { meta: { featured: 1 } } );

		// An unticked box is 0, as the metabox stores it.
		control( tree, 'Featured' ).props.onChange( false );
		expect( editor.edits.pop() ).toEqual( { meta: { featured: 0 } } );

		// An emptied control deletes rather than storing "".
		control( tree, 'Size' ).props.onChange( '' );
		expect( editor.edits.pop() ).toEqual( { meta: { size: null } } );

		control( tree, 'Price' ).props.onChange( '1.' );
		expect( editor.edits.pop() ).toEqual( { meta: { price: 1 } } );

		// Not an integer field: the decimal is kept.
		control( tree, 'Price' ).props.onChange( '1.5' );
		expect( editor.edits.pop() ).toEqual( { meta: { price: 1.5 } } );

		control( tree, 'Price' ).props.onChange( '' );
		expect( editor.edits.pop() ).toEqual( { meta: { price: null } } );

		control( tree, 'Tagline' ).props.onChange( 'Fresh' );
		expect( editor.edits.pop() ).toEqual( { meta: { tagline: 'Fresh' } } );
	} );

	test( 'shows a field once its condition holds', () => {
		editor.meta = { featured: 1 };

		expect( control( draw(), 'Note' ).type ).toBe( 'TextareaControl' );
	} );

	test( 'a second copy of the same build registers nothing', async () => {
		const first = editor.plugins[ handle + '-demo-box' ];

		vi.resetModules();
		await import( '../../src/js/block-editor.js' );

		expect( editor.plugins[ handle + '-demo-box' ] ).toBe( first );
	} );
} );
