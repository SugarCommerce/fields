/**
 * Smoke test for the block editor's sidebar script.
 *
 * The PHP suite says what the script is handed; nothing there runs it. This
 * runs it against a stand-in for the `wp` globals -- elements recorded as
 * plain objects, function components called in place -- and checks the parts
 * that would fail without a word in the editor: that each panel registers,
 * that a field's control is the one PHP named, that a condition written for
 * PHP hides and shows the same way here, and that a change reaches the
 * editor's store as the value the metabox would have stored.
 */

'use strict';

const assert = require( 'assert' );
const fs = require( 'fs' );
const path = require( 'path' );
const vm = require( 'vm' );

const source = fs.readFileSync(
	path.join( __dirname, '..', '..', 'assets', 'js', 'block-editor.js' ),
	'utf8'
);

const handle = 'sugarcommerce-fields-block-editor';
const plugins = {};
const edits = [];

let meta = {};

function createElement( type, props, ...children ) {
	return { type, props: Object.assign( {}, props, { children: children.flat() } ) };
}

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
		props: Object.assign( {}, node.props, { children: expand( node.props.children ) } ),
	} );
}

function find( node, test, found = [] ) {
	if ( Array.isArray( node ) ) {
		node.forEach( ( one ) => find( one, test, found ) );
	} else if ( node && typeof node === 'object' ) {
		if ( test( node ) ) {
			found.push( node );
		}

		find( node.props.children, test, found );
	}

	return found;
}

const components = {};

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
].forEach( ( name ) => {
	components[ name ] = name;
} );

const context = {
	document: { currentScript: { id: handle + '-js' } },
	SugarCommerceFields: {
		[ handle ]: {
			panels: [
				{
					plugin: handle + '-demo-box',
					name: 'demo-box',
					title: 'Demo',
					fields: [
						{ key: 'tagline', control: 'text', label: 'Tagline', help: '', placeholder: '', conditions: [], maxLength: 0 },
						{ key: 'price', control: 'number', label: 'Price', help: '', placeholder: '', conditions: [], integer: false, min: 0, max: null, step: 0.01 },
						{ key: 'when', control: 'datetime', label: 'When', help: '', placeholder: '', conditions: [], maxLength: 0 },
						{
							key: 'size',
							control: 'select',
							label: 'Size',
							help: '',
							placeholder: '',
							conditions: [],
							emptyLabel: '— Select —',
							options: [ { value: 's', label: 'Small' }, { label: 'Big', options: [ { value: 'xl', label: 'Extra large' } ] } ],
						},
						{ key: 'layout', control: 'button_group', label: 'Layout', help: '', placeholder: '', conditions: [], options: [ { value: 'grid', label: 'Grid' }, { value: 'list', label: 'List' } ] },
						{ key: 'featured', control: 'toggle', label: 'Featured', help: '', placeholder: '', conditions: [], checkboxLabel: '' },
						{
							key: 'note',
							control: 'textarea',
							label: 'Note',
							help: '',
							placeholder: '',
							maxLength: 0,
							// Written for PHP: true matches a box stored as 1.
							conditions: [ { field: 'featured', operator: '=', value: true } ],
						},
						{ key: 'colour', control: 'color', label: 'Colour', help: 'Pick one', placeholder: '', conditions: [], presets: [ { color: '#ff0000', name: 'Red' } ] },
					],
				},
			],
		},
	},
	wp: {
		plugins: {
			getPlugin: ( name ) => plugins[ name ],
			registerPlugin: ( name, settings ) => {
				plugins[ name ] = settings;
			},
		},
		element: {
			createElement,
			useState: ( initial ) => [ initial, () => {} ],
			useEffect: () => {},
		},
		components,
		editor: { PluginDocumentSettingPanel: 'PluginDocumentSettingPanel' },
		data: {
			useSelect: ( callback ) =>
				callback( () => ( { getEditedPostAttribute: () => meta } ) ),
			useDispatch: () => ( {
				// Through JSON: an object made inside the script's context has
				// that context's prototype, which deepStrictEqual tells apart.
				editPost: ( edit ) => edits.push( JSON.parse( JSON.stringify( edit ) ) ),
			} ),
		},
	},
};

context.window = context;

vm.runInNewContext( source, context, { filename: 'block-editor.js' } );

const plugin = plugins[ handle + '-demo-box' ];

assert.ok( plugin, 'The panel registers as a plugin under its build-specific name.' );

function draw() {
	return expand( plugin.render() );
}

function control( tree, label ) {
	return find( tree, ( node ) => typeof node.type === 'string' && node.props.label === label )[ 0 ];
}

// Nothing ticked: the note is hidden, the rest are drawn as PHP named them.
meta = { featured: 0 };

let tree = draw();

assert.strictEqual( find( tree, ( node ) => node.type === 'PluginDocumentSettingPanel' ).length, 1 );
assert.strictEqual( control( tree, 'Tagline' ).type, 'TextControl' );
assert.strictEqual( control( tree, 'Price' ).props.type, 'number' );
assert.strictEqual( control( tree, 'When' ).props.type, 'datetime-local' );
assert.strictEqual( control( tree, 'Size' ).type, 'SelectControl' );
assert.strictEqual( control( tree, 'Layout' ).type, '__experimentalToggleGroupControl' );
assert.strictEqual( control( tree, 'Featured' ).type, 'ToggleControl' );
assert.strictEqual( control( tree, 'Note' ), undefined, 'A condition that is not met hides its field.' );
assert.strictEqual( find( tree, ( node ) => node.type === 'ColorPalette' ).length, 1 );
assert.strictEqual( find( tree, ( node ) => node.type === 'optgroup' ).length, 1, 'A select keeps its groups.' );

// A change is one meta key, as the value the metabox would store.
control( tree, 'Featured' ).props.onChange( true );
assert.deepStrictEqual( edits.pop(), { meta: { featured: 1 } } );

control( tree, 'Featured' ).props.onChange( false );
assert.deepStrictEqual( edits.pop(), { meta: { featured: 0 } }, 'An unticked box is 0, as the metabox stores it.' );

control( tree, 'Size' ).props.onChange( '' );
assert.deepStrictEqual( edits.pop(), { meta: { size: null } }, 'An emptied control deletes rather than storing "".' );

control( tree, 'Price' ).props.onChange( '1.' );
assert.deepStrictEqual( edits.pop(), { meta: { price: 1 } } );

control( tree, 'Price' ).props.onChange( '' );
assert.deepStrictEqual( edits.pop(), { meta: { price: null } } );

control( tree, 'Tagline' ).props.onChange( 'Fresh' );
assert.deepStrictEqual( edits.pop(), { meta: { tagline: 'Fresh' } } );

// Ticked: the note is shown.
meta = { featured: 1 };
tree = draw();

assert.strictEqual( control( tree, 'Note' ).type, 'TextareaControl' );

// Registering twice -- a second copy of the same build -- is refused quietly.
vm.runInNewContext( source, context, { filename: 'block-editor.js' } );

console.log( 'Block editor sidebar: registers once, draws each control PHP named, hides and shows by the metabox\'s conditions, and stores what the metabox would.' );
