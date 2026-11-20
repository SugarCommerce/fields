/**
 * What other code reads from the kit: its modules, in its build's own entry
 * (`window.SugarCommerceFields[ handle ].modules`),
 * its shape and when it is there; init() and destroy(); the native events
 * the kit listens for and fires; and the one module that touches jQuery.
 */

import { afterEach, beforeAll, describe, expect, test, vi } from 'vitest';

vi.mock( '@wordpress/api-fetch', () => ( {
	default: () => Promise.resolve( {} ),
} ) );

// In the order it has always been published in. A plugin reads
// Repeater.add, Reorder.move and Media.init; a panel calls init and destroy.
const PUBLISHED = [
	'Conditions',
	'Range',
	'Toggle',
	'Clipboard',
	'CodeGenerator',
	'announce',
	'bound',
	'claim',
	'sibling',
	't',
	'icon',
	'swapIcon',
	'config',
	'request',
	'Combobox',
	'Reorder',
	'CodeEditor',
	'Wysiwyg',
	'ColorPicker',
	'ColorField',
	'IconPicker',
	'PanelTabs',
	'TagModal',
	'EmailPanel',
	'Tooltip',
	'ActionButton',
	'Repeater',
	'Media',
	'Gallery',
	'Tags',
	'Money',
	'Region',
	'PasswordReveal',
	'PasswordRemove',
	'Counter',
	'Accordion',
	'SettingsBar',
	'Slug',
	'Location',
	'Embed',
	'Address',
	'Guard',
	'FrontForm',
	'init',
	'destroy',
];

let modules;
let startedEarly;

beforeAll( async () => {
	Object.defineProperty( document, 'currentScript', {
		value: { id: 'sugarcommerce-fields-js' },
		configurable: true,
	} );
	window.SugarCommerceFields = {
		'sugarcommerce-fields': {
			restUrl: 'https://a.test/wp-json/sugarcommerce-fields/v1/',
			restNonce: 'n',
			i18n: { leaveChanges: 'Leave?' },
			guard: [ '.guarded' ],
		},
	};

	// Loaded while the page is still loading, as a footer script is.
	Object.defineProperty( document, 'readyState', {
		value: 'loading',
		configurable: true,
	} );

	// The page is this build's: a copy drives only markup stamped for it,
	// and one stamp on a wrapper claims everything inside.
	document.body.setAttribute(
		'data-field-kit-build',
		'sugarcommerce-fields'
	);

	await import( '../../assets/js/field-kit.js' );

	startedEarly = Object.keys( document.fieldKitBound || {} );
	delete document.readyState;

	modules = window.SugarCommerceFields[ 'sugarcommerce-fields' ].modules;
} );

afterEach( () => {
	document.body.innerHTML = '';
	delete window.jQuery;
} );

describe( 'window.SugarCommerceFields[ handle ].modules', () => {
	test( 'is there as soon as the script has run, before the page is ready', () => {
		expect( modules ).toBeTruthy();
		// Nothing was started yet: that waits for the page.
		expect( startedEarly ).toEqual( [] );
	} );

	test( 'keeps its shape and order', () => {
		expect( Object.keys( modules ) ).toEqual( PUBLISHED );
		expect( typeof modules.Repeater.add ).toBe( 'function' );
		expect( typeof modules.Reorder.move ).toBe( 'function' );
		expect( typeof modules.Media.init ).toBe( 'function' );
		expect( modules.config.restNonce ).toBe( 'n' );
		expect( modules.t( 'leaveChanges', 'x' ) ).toBe( 'Leave?' );
	} );

	test( 'init() starts every module, in its own order, on a root', async () => {
		const { ORDER } = await import( '../../assets/js/lib/lifecycle' );

		// A search box before the list that reorders its chips, a row's
		// modules before the editors in it, the guard after everything that
		// sets a field up, so what they do is how the form started.
		expect( ORDER ).toEqual( [
			'Conditions',
			'Range',
			'Toggle',
			'Clipboard',
			'CodeGenerator',
			'Combobox',
			'Reorder',
			'Repeater',
			'Media',
			'Gallery',
			'Tags',
			'CodeEditor',
			'Wysiwyg',
			'ColorPicker',
			'TagModal',
			'PanelTabs',
			'EmailPanel',
			'ActionButton',
			'Tooltip',
			'ColorField',
			'IconPicker',
			'Money',
			'PasswordReveal',
			'PasswordRemove',
			'Counter',
			'Region',
			'Accordion',
			'SettingsBar',
			'Slug',
			'Location',
			'Embed',
			'Address',
			'Guard',
			'FrontForm',
		] );
		const started = [];
		const saved = {};

		ORDER.forEach( ( name ) => {
			saved[ name ] = modules[ name ].init;
			modules[ name ].init = ( root ) => started.push( [ name, root ] );
		} );

		const root = document.createElement( 'div' );

		try {
			modules.init( root );
			modules.init();
		} finally {
			ORDER.forEach( ( name ) => {
				modules[ name ].init = saved[ name ];
			} );
		}

		expect( started.slice( 0, ORDER.length ) ).toEqual(
			ORDER.map( ( name ) => [ name, root ] )
		);
		// No root: the document.
		expect( started[ ORDER.length ][ 1 ] ).toBe( document );
	} );

	test( 'destroy() asks every object module that has a destroy()', () => {
		const asked = [];
		const root = document.createElement( 'div' );

		modules.Extra = { destroy: ( r ) => asked.push( [ 'Extra', r ] ) };

		const destroyWysiwyg = modules.Wysiwyg.destroy;

		modules.Wysiwyg.destroy = ( r ) => asked.push( [ 'Wysiwyg', r ] );

		try {
			modules.destroy( root );
			modules.destroy( null );
		} finally {
			delete modules.Extra;
			modules.Wysiwyg.destroy = destroyWysiwyg;
		}

		expect( asked ).toEqual( [
			[ 'Wysiwyg', root ],
			[ 'Extra', root ],
		] );
	} );

	test( 'a page starts the modules when it is ready, and again at load', () => {
		const calls = [];
		const init = modules.Counter.init;

		modules.Counter.init = ( root ) => calls.push( root );

		try {
			document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
			window.dispatchEvent( new Event( 'load' ) );
		} finally {
			modules.Counter.init = init;
		}

		expect( calls ).toEqual( [ document, document ] );
	} );
} );

describe( 'the unsaved-changes guard and a panel', () => {
	/**
	 * A guarded form inside a flyouts panel, started.
	 *
	 * @return {Object} The panel, the form and its input.
	 */
	function panel() {
		document.body.innerHTML = `
			<div class="wp-flyout">
				<form data-fk-guard>
					<div class="field-kit__field" data-field-key="name">
						<label for="n">Name</label>
						<input id="n" name="name" value="Ada">
					</div>
				</form>
			</div>`;

		modules.Guard.init( document );

		return {
			flyout: document.querySelector( '.wp-flyout' ),
			form: document.querySelector( 'form' ),
			input: document.getElementById( 'n' ),
		};
	}

	// The guard looks a moment after the last keystroke.
	async function type( input, value ) {
		input.value = value;
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		await new Promise( ( resolve ) => setTimeout( resolve, 120 ) );
	}

	test( 'a panel saved -- wpflyout:dirty, natively, with { dirty: false } -- is the new start', async () => {
		const { flyout, form, input } = panel();

		await type( input, 'Grace' );

		expect( modules.Guard.dirty( form ) ).toBe( true );

		flyout.dispatchEvent(
			new CustomEvent( 'wpflyout:dirty', {
				bubbles: true,
				detail: { dirty: false },
			} )
		);

		expect( modules.Guard.dirty( form ) ).toBe( false );
		expect( form.querySelector( '.field-kit__field--changed' ) ).toBeNull();
	} );

	test( 'a panel that became dirty, or said nothing, changes nothing', async () => {
		const { flyout, form, input } = panel();

		await type( input, 'Grace' );

		flyout.dispatchEvent(
			new CustomEvent( 'wpflyout:dirty', {
				bubbles: true,
				detail: { dirty: true },
			} )
		);
		flyout.dispatchEvent(
			new CustomEvent( 'wpflyout:dirty', { bubbles: true } )
		);

		expect( modules.Guard.dirty( form ) ).toBe( true );
	} );

	test( 'with jQuery on the page, a panel saved through it still resets the form', async () => {
		const { default: jQuery } = await import( 'jquery' );
		const { flyout, form, input } = panel();

		window.jQuery = jQuery;
		await type( input, 'Grace' );

		// What flyouts' lib/events.js does: the native event, with jQuery
		// told not to run its own listeners a second time.
		jQuery.event.triggered = 'wpflyout:dirty';
		flyout.dispatchEvent(
			new CustomEvent( 'wpflyout:dirty', {
				bubbles: true,
				detail: { dirty: false },
			} )
		);
		jQuery.event.triggered = undefined;

		expect( modules.Guard.dirty( form ) ).toBe( false );
	} );
} );

describe( 'native events the kit fires', () => {
	test( 'a picked colour is field-kit:change, never a native change', () => {
		document.body.innerHTML =
			'<input class="field-kit__color" data-palette="#fff,#000">';

		const input = document.querySelector( 'input' );
		const heard = [];
		let options = null;

		input.addEventListener( 'change', () => heard.push( 'change' ) );
		document.addEventListener( 'field-kit:change', ( event ) =>
			heard.push( event.type + ':' + event.bubbles )
		);
		window.jQuery = Object.assign(
			( element ) => ( {
				wpColorPicker: ( passed ) => {
					expect( element ).toBe( input );
					options = passed;
				},
			} ),
			{ fn: { wpColorPicker() {} } }
		);

		modules.ColorPicker.init( document );
		modules.ColorPicker.init( document );
		options.change();
		options.clear();

		expect( options.palettes ).toEqual( [ '#fff', '#000' ] );
		expect( heard ).toEqual( [
			'field-kit:change:true',
			'field-kit:change:true',
		] );
	} );
} );

describe( 'the jQuery adapter', () => {
	test( 'without jQuery or the plugin, nothing happens and the input stays', async () => {
		const { colorPicker, hasColorPicker } =
			await import( '../../assets/js/lib/jquery' );
		const input = document.createElement( 'input' );

		expect( hasColorPicker() ).toBe( false );
		expect( colorPicker( input, {} ) ).toBe( false );

		window.jQuery = Object.assign( () => ( {} ), { fn: {} } );

		expect( hasColorPicker() ).toBe( false );
		expect( colorPicker( input, {} ) ).toBe( false );

		document.body.innerHTML = '<input class="field-kit__color">';
		modules.ColorPicker.init( document );

		expect( document.querySelector( 'input' ).dataset.fkBound ).toBe(
			undefined
		);
	} );

	test( 'with it, the picker is started on the input with the options', async () => {
		const { colorPicker } = await import( '../../assets/js/lib/jquery' );
		const input = document.createElement( 'input' );
		const started = [];

		window.jQuery = Object.assign(
			( element ) => ( {
				wpColorPicker: ( options ) =>
					started.push( [ element, options ] ),
			} ),
			{ fn: { wpColorPicker() {} } }
		);

		expect( colorPicker( input, { a: 1 } ) ).toBe( true );
		expect( started ).toEqual( [ [ input, { a: 1 } ] ] );
	} );
} );

describe( "the icon picker's status line", () => {
	test( 'counts the set, or what a search found', () => {
		expect( modules.IconPicker.count( 324, false ) ).toBe( '324 icons' );
		expect( modules.IconPicker.count( 1, false ) ).toBe( '1 icon' );
		expect( modules.IconPicker.count( 5, true ) ).toBe( '5 icons match.' );
		expect( modules.IconPicker.count( 1, true ) ).toBe( '1 icon matches.' );
	} );
} );
