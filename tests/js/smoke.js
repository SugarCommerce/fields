/**
 * Smoke test for the field kit's script.
 *
 * The PHP suite renders markup and never executes a line of JavaScript, so a
 * ReferenceError in a module is invisible to it. That is not hypothetical:
 * `config` was resolved in one IIFE and used in another, and the resulting
 * throw stopped the init loop dead — every module after it silently never
 * ran, and the only symptom was that several fields "didn't work".
 *
 * This loads the script against a minimal DOM and calls every module's init,
 * which is the path that broke.
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const vm = require( 'vm' );

const source = fs.readFileSync(
	path.join( __dirname, '..', '..', 'assets', 'js', 'field-kit.js' ),
	'utf8'
);

/**
 * The smallest DOM the script touches.
 *
 * Deliberately minimal rather than jsdom: the point is to catch a reference
 * that does not resolve, not to assert behaviour, and a real DOM would drag
 * in a dependency this library otherwise does not have.
 */
function makeElement() {
	const element = {
		dataset: {},
		classList: { add() {}, remove() {}, contains: () => false, toggle() {} },
		style: {},
		offsetWidth: 240,
		offsetHeight: 30,
		offsetLeft: 0,
		offsetTop: 0,
		hidden: false,
		disabled: false,
		value: '',
		id: '',
		children: [],
		addEventListener() {},
		removeEventListener() {},
		querySelector: () => null,
		querySelectorAll: () => [],
		closest: () => null,
		hidden: false,
		getAttribute: () => null,
		setAttribute() {},
		hasAttribute: () => false,
		removeAttribute() {},
		appendChild() {},
		insertBefore() {},
		remove() {},
		focus() {},
		dispatchEvent() {},
		cloneNode() { return makeElement(); },
	};

	return element;
}

const documentStub = {
	readyState: 'complete',
	currentScript: { id: 'sugarcommerce-fields-js' },
	addEventListener() {},
	createElement: () => makeElement(),
	querySelector: () => null,
	querySelectorAll: () => [],
	body: makeElement(),
	ownerDocument: null,
};

const context = {
	document: documentStub,
	getComputedStyle: () => ( {} ),
	console,
	setTimeout,
	clearTimeout,
	fetch: () => Promise.resolve( { ok: true, json: () => Promise.resolve( {} ) } ),
	CSS: { escape: ( value ) => String( value ) },
	Event: function ( type ) { this.type = type; },
	CustomEvent: function ( type ) { this.type = type; },
	URL,
	Promise,
	navigator: { clipboard: null },
	location: { origin: 'https://example.test' },
};

context.window = context;
context.window.addEventListener = () => {};
context.window.removeEventListener = () => {};
context.window.setTimeout = setTimeout;
context.window.clearTimeout = clearTimeout;
context.window.isSecureContext = true;
context.window.location = context.location;
context.window.SugarCommerceFields = {
	'sugarcommerce-fields': {
		restUrl: 'https://example.test/wp-json/sugarcommerce-fields/v1/',
		restNonce: 'x',
		i18n: {},
		icons: { close: '<svg data-icon="close"></svg>', show: '<svg data-icon="show"></svg>', hide: '<svg data-icon="hide"></svg>' },
	},
};

let failures = 0;

try {
	vm.createContext( context );
	vm.runInContext( source, context, { filename: 'field-kit.js' } );
} catch ( error ) {
	console.error( `  script threw while loading: ${ error.message }` );
	process.exit( 1 );
}

const modules = context.window.SugarCommerceFieldsModules;

if ( ! modules ) {
	console.error( '  the script exposed no modules' );
	process.exit( 1 );
}

/*
 * The controls the script builds take their icons from the configuration,
 * drawn in PHP. Asked for by key; nothing comes back for a key there is no
 * icon for, so a control is never handed "undefined" as markup.
 */
if ( '<svg data-icon="close"></svg>' !== modules.icon( 'close' ) || '' !== modules.icon( 'nope' ) ) {
	console.error( '  icon(): did not read the configured icons' );
	failures ++;
}

/*
 * Read out of the script's own init list rather than kept by hand here. The
 * hand-written copy had drifted: IconPreview and another module were both added to
 * the script and never to this list, so neither was ever checked -- and a
 * module that is not on the list is exactly the one nobody notices is broken.
 */
const bootstrap = source.match( /\[\s*'Conditions'[^\]]*\]/ );

if ( ! bootstrap ) {
	console.error( '  could not find the script\'s module list' );
	process.exit( 1 );
}

const expected = bootstrap[ 0 ].match( /'([A-Za-z]+)'/g ).map( ( name ) => name.slice( 1, -1 ) );

expected.forEach( ( name ) => {
	if ( ! modules[ name ] ) {
		console.error( `  ${ name }: not exposed` );
		failures ++;

		return;
	}

	if ( typeof modules[ name ].init !== 'function' ) {
		return;
	}

	try {
		modules[ name ].init( documentStub );
	} catch ( error ) {
		console.error( `  ${ name }.init threw: ${ error.message }` );
		failures ++;
	}
} );

// The bootstrap's own loop, which is where the ReferenceError surfaced.
try {
	modules.init( documentStub );
} catch ( error ) {
	console.error( `  init() threw: ${ error.message }` );
	failures ++;
}

/*
 * The colour picker must not fire a native change.
 *
 * iris runs its change callback before it writes the value, and re-reads the
 * input when a change arrives — so a native change handed it the old value
 * and it reset the picker. The palette opened, swatches highlighted, and
 * clicking one did nothing. From the clear callback the same dispatch loops,
 * because that one is reached from iris's own change listener.
 *
 * Asserted here because it is invisible in markup and the PHP suite cannot
 * reach it: the whole failure lives in which event name is used.
 */
( function () {
	const dispatched = [];
	const input = makeElement();

	input.dispatchEvent = ( event ) => dispatched.push( event.type );
	input.classList.contains = ( name ) => name === 'field-kit__color';

	let options = null;

	context.window.jQuery = Object.assign(
		() => ( { wpColorPicker: ( passed ) => { options = passed; } } ),
		{ fn: { wpColorPicker: () => {} } }
	);

	const root = Object.assign( makeElement(), {
		querySelectorAll: ( selector ) =>
			( 'input.field-kit__color' === selector ? [ input ] : [] ),
	} );

	modules.ColorPicker.init( root );

	if ( ! options || typeof options.change !== 'function' ) {
		console.error( '  ColorPicker: wpColorPicker was never called with a change callback' );
		failures ++;
	} else {
		options.change();
		options.clear();

		if ( dispatched.includes( 'change' ) ) {
			console.error( '  ColorPicker: fires a native change, which iris reads as a fresh edit' );
			failures ++;
		}

		if ( 2 !== dispatched.filter( ( type ) => 'field-kit:change' === type ).length ) {
			console.error( `  ColorPicker: expected two field-kit:change events, got ${ JSON.stringify( dispatched ) }` );
			failures ++;
		}
	}
} )();

/*
 * The combobox list is sized from the input, not from its wrapper.
 *
 * It used to be stretched across the wrapper with left:0;right:0, so anything
 * that made the wrapper wider than the input — core does, on a term screen —
 * left the list hanging past the control it belongs to. Measured from the
 * input, it cannot. This asserts the measurement happens at all, since the
 * failure is invisible in markup and the PHP suite cannot reach it.
 */
( function () {
	const source = fs.readFileSync(
		path.join( __dirname, '..', '..', 'assets', 'js', 'field-kit.js' ),
		'utf8'
	);

	const placed = /list\.style\.width\s*=\s*input\.offsetWidth/.test( source );
	const stretched = /\.field-kit__combobox-list[\s\S]{0,400}?right:\s*0/.test(
		fs.readFileSync(
			path.join( __dirname, '..', '..', 'assets', 'css', 'field-kit.css' ),
			'utf8'
		)
	);

	if ( ! placed ) {
		console.error( '  Combobox: the list is not measured from the input' );
		failures ++;
	}

	if ( stretched ) {
		console.error( '  Combobox: the list is stretched across its wrapper again' );
		failures ++;
	}
} )();

/*
 * Adding a row leaves it usable.
 *
 * The template is rendered for a row that has no position, so both its move
 * buttons come out disabled — and the row that used to be last still has its
 * "move down" switched off. add() renumbered the clone and stopped there, so
 * every added row was stuck where it landed. And nothing ran the modules over
 * the new markup, so a combobox, colour picker or media button inside a row
 * added after page load was inert: it rendered, and did nothing.
 *
 * Both are invisible to the PHP suite, which never adds a row.
 */
( function () {
	/**
	 * A repeater row carrying a pair of move buttons.
	 *
	 * @param {boolean} up   Whether "move up" starts disabled.
	 * @param {boolean} down Whether "move down" starts disabled.
	 * @return {object} The row.
	 */
	function makeRow( up, down ) {
		const moveUp = Object.assign( makeElement(), { disabled: up, dataset: { action: 'move-up' } } );
		const moveDown = Object.assign( makeElement(), { disabled: down, dataset: { action: 'move-down' } } );
		const row = makeElement();

		row.moveUp = moveUp;
		row.moveDown = moveDown;
		row.classList.contains = ( name ) => 'field-kit__repeater-row' === name;
		row.querySelector = ( selector ) => {
			if ( selector.includes( 'move-up' ) ) {
				return moveUp;
			}

			if ( selector.includes( 'move-down' ) ) {
				return moveDown;
			}

			return null;
		};
		row.querySelectorAll = () => [];
		row.cloneNode = () => makeRow( up, down );

		return row;
	}

	// One saved row, and a template whose row arrives with both moves off.
	const first = makeRow( true, true );
	const list = makeElement();

	list.children = [ first ];
	list.classList.contains = ( name ) => 'field-kit__repeater-rows' === name;
	list.querySelectorAll = () => list.children;
	list.appendChild = ( node ) => list.children.push( node );

	const template = makeElement();
	template.content = { firstElementChild: makeRow( true, true ) };

	const wrap = makeElement();
	list.closest = () => wrap;
	template.closest = () => wrap;
	wrap.dataset = { fieldName: 'rates' };

	// A saved row that holds a repeater of its own. Its template sits inside
	// the row, so it comes before this repeater's own in the document -- and
	// taking the first one found added the nested repeater's row to this
	// list. It belongs to the inner repeater, which is what closest() says.
	const innerWrap = makeElement();
	const nestedRow = makeRow( true, true );
	const nestedTemplate = makeElement();

	nestedRow.cloneNode = () => Object.assign( makeRow( true, true ), { nested: true } );
	nestedTemplate.content = { firstElementChild: nestedRow };
	nestedTemplate.closest = () => innerWrap;

	wrap.querySelectorAll = ( selector ) => {
		if ( selector.includes( 'template' ) ) {
			return [ nestedTemplate, template ];
		}

		if ( selector.includes( 'rows' ) ) {
			return [ list ];
		}

		return [];
	};
	wrap.querySelector = ( selector ) => wrap.querySelectorAll( selector )[ 0 ] || null;

	const initialised = [];
	const realInit = modules.init;
	modules.init = ( root ) => initialised.push( root );

	try {
		modules.Repeater.add( wrap );
	} catch ( error ) {
		console.error( `  Repeater.add threw: ${ error.message }` );
		failures ++;
	} finally {
		modules.init = realInit;
	}

	const added = list.children[ 1 ];

	if ( added && added.nested ) {
		console.error( '  Repeater: the row added came from a nested repeater\'s template' );
		failures ++;
	}

	if ( ! added ) {
		console.error( '  Repeater: no row was added' );
		failures ++;
	} else {
		if ( added.moveUp.disabled ) {
			console.error( '  Repeater: an added row cannot be moved up; it kept the template\'s disabled state' );
			failures ++;
		}

		if ( first.moveDown.disabled ) {
			console.error( '  Repeater: the row above an added one still cannot be moved down' );
			failures ++;
		}

		if ( ! first.moveUp.disabled ) {
			console.error( '  Repeater: the first row was given a move up' );
			failures ++;
		}

		if ( ! added.moveDown.disabled ) {
			console.error( '  Repeater: the last row was given a move down' );
			failures ++;
		}

		if ( ! initialised.includes( added ) ) {
			console.error( '  Repeater: the added row was never initialised; anything enhanced inside it is inert' );
			failures ++;
		}

		if ( 'false' !== list.dataset.empty ) {
			console.error( '  Repeater: the list still reports itself empty' );
			failures ++;
		}
	}
} )();

/*
 * Renumbering admits the template's index.
 *
 * The template row is rendered at -1, an index no saved row can hold, and
 * the pattern that renumbers names matched digits only. Every added row
 * kept [-1], so two of them posted as one, and dragging could not move them.
 */
( function () {
	/**
	 * A row whose inputs carry the template's index.
	 *
	 * @return {object} The row.
	 */
	function makeRow() {
		const input = Object.assign( makeElement(), { name: 'rates[-1][amount]' } );
		const row = makeElement();

		row.input = input;
		row.querySelectorAll = ( selector ) => ( '[name]' === selector ? [ input ] : [] );

		return row;
	}

	const rows = [ makeRow(), makeRow() ];
	const list = makeElement();
	const wrap = makeElement();

	list.children = rows;
	list.closest = () => wrap;
	wrap.dataset = { fieldName: 'rates' };

	try {
		modules.Repeater.reindex( list );
	} catch ( error ) {
		console.error( `  Repeater.reindex threw: ${ error.message }` );
		failures ++;
	}

	const names = rows.map( ( row ) => row.input.name );

	if ( 'rates[0][amount]' !== names[ 0 ] || 'rates[1][amount]' !== names[ 1 ] ) {
		console.error( `  Repeater: rows cloned from the template are not renumbered: ${ JSON.stringify( names ) }` );
		failures ++;
	}
} )();

/*
 * Reordering chips reaches the combobox.
 *
 * Combobox lives in the block before Reorder. Referred to bare from
 * Reorder.sync, it was a ReferenceError the first time a sortable chip
 * moved -- and the new order never reached the select, so it was lost on save.
 */
( function () {
	const select = Object.assign( makeElement(), { options: [] } );
	const wrap = Object.assign( makeElement(), { querySelector: ( selector ) => ( 'select' === selector ? select : null ) } );
	const chips = makeElement();

	chips.classList.contains = ( name ) => 'field-kit__combobox-chips' === name;
	chips.closest = () => wrap;

	try {
		modules.Reorder.sync( chips );
	} catch ( error ) {
		console.error( `  Reorder.sync on chips threw: ${ error.message }` );
		failures ++;
	}
} )();

/*
 * A root is bound once.
 *
 * init() runs at DOMContentLoaded and again at load, and for every added
 * row. A module listening on the root itself bound a second handler each
 * time: two copies to the clipboard, and every conditional field evaluated
 * twice per keystroke.
 */
( function () {
	const root = makeElement();
	const listeners = [];

	root.addEventListener = ( type ) => listeners.push( type );

	modules.Clipboard.init( root );
	modules.Clipboard.init( root );
	modules.Toggle.init( root );
	modules.Toggle.init( root );
	modules.Conditions.init( root );
	modules.Conditions.init( root );
	modules.ActionButton.init( root );
	modules.ActionButton.init( root );

	// One click for Clipboard, one change for Toggle, three for Conditions,
	// one click for ActionButton.
	if ( 6 !== listeners.length ) {
		console.error( `  a second init() bound the root again: ${ JSON.stringify( listeners ) }` );
		failures ++;
	}
} )();

/*
 * The email panel binds its merge tags; folding it is the accordion's job.
 *
 * It used to carry a toggle of its own, and on a metabox screen core's
 * postboxes.js bound the same button, so the panel toggled twice and never
 * moved. The header is now core's accordion trigger, which core never binds,
 * and the Accordion module folds it once like every collapsible section.
 */
( function () {
	const panel = makeElement();
	const tag = makeElement();
	const clicks = [];

	tag.dataset = { tag: '{name}' };
	tag.addEventListener = ( type, handler ) => clicks.push( handler );
	panel.querySelectorAll = ( selector ) => ( selector.includes( 'email-tag' ) ? [ tag ] : [] );
	panel.querySelector = () => null;

	const root = Object.assign( makeElement(), {
		querySelectorAll: ( selector ) =>
			( selector.includes( 'field-kit__email' ) ? [ panel ] : [] ),
	} );

	// Twice, because init() runs twice on a normal page load.
	modules.EmailPanel.init( root );
	modules.EmailPanel.init( root );

	if ( 1 !== clicks.length ) {
		console.error( `  EmailPanel: expected one click handler on a merge tag, got ${ clicks.length }` );
		failures ++;
	}

	if ( 'function' === typeof modules.EmailPanel.bindToggle ) {
		console.error( '  EmailPanel: still carries a toggle of its own; folding is the Accordion module\'s' );
		failures ++;
	}
} )();

/*
 * A single combobox can be put back to holding nothing.
 *
 * The input is a search box and the select is what posts, so emptying the
 * visible text is not clearing the field — the select has to be emptied too,
 * and for a remote control the option has to go, since it only ever existed
 * to carry the current choice.
 *
 * Read out of the source rather than driven through the stub DOM. The button
 * is built during init(), which needs a good deal more of a document than
 * this file has, and a stub elaborate enough to run it would be asserting on
 * the stub. What matters is the three decisions, and each is one line.
 */
( function () {
	const source = fs.readFileSync(
		path.join( __dirname, '..', '..', 'assets', 'js', 'field-kit.js' ),
		'utf8'
	);

	const checks = [
		[
			/var clearable = ! multiple &&[^;]*required/,
			'the clear button is offered to multiple or required controls, which have nothing to clear to',
		],
		[
			/function clearValue\(\)[\s\S]{0,500}select\.innerHTML = ''/,
			'clearing does not empty a remote control\'s option, so the old choice still posts',
		],
		[
			/function clearValue\(\)[\s\S]{0,500}input\.value = ''/,
			'clearing does not empty the input',
		],
		[
			/function clearValue\(\)[\s\S]{0,700}dispatchEvent/,
			'clearing fires no change event, so nothing watching the field hears it',
		],
		[
			/clear\.hidden = '' === select\.value/,
			'the clear button is shown when there is nothing to clear',
		],
		[
			/field-kit__combobox--clearable/,
			'the wrapper is not marked clearable, so the input reserves no room and the text jumps',
		],
	];

	checks.forEach( ( [ pattern, complaint ] ) => {
		if ( ! pattern.test( source ) ) {
			console.error( `  Combobox: ${ complaint }` );
			failures ++;
		}
	} );
} )();

/*
 * A picker that may make a new item offers to, and only when it should.
 *
 * The row is offered when nothing found is what was typed -- a user found
 * by the address in brackets counts -- and for a user only when what was
 * typed is an address, since the server refuses anything else. Its words
 * start with "Add", which the panels audit relies on to never press it.
 * What it does is read out of the source, for the same reason as the clear
 * button above: driving bind() needs more of a document than this has.
 */
( function () {
	const Combobox = modules.Combobox;
	const expect = ( ok, message ) => {
		if ( ! ok ) {
			console.error( `  Combobox (add): ${ message }` );
			failures ++;
		}
	};

	const found = [ { id: '4', text: 'Sample Packs' }, { id: '9', text: 'Jane Doe (jane@example.test)' } ];

	expect( true === Combobox.offersAdd( found, 'Sample', '' ), 'a partial match did not leave room to add' );
	expect( false === Combobox.offersAdd( found, ' sample packs ', '' ), 'an exact match, in another case, was offered as new' );
	expect( false === Combobox.offersAdd( found, '', '' ), 'nothing typed was offered as new' );
	expect( false === Combobox.offersAdd( found, 'Jane@example.test', 'email' ), 'a user found by address was offered as new' );
	expect( false === Combobox.offersAdd( [], 'Jane Doe', 'email' ), 'a name was offered as a new user, who is added by address' );
	expect( true === Combobox.offersAdd( [], 'new@example.test', 'email' ), 'a new address was not offered' );
	expect( true === Combobox.offersAdd( [ { text: 'Sample Packs', add: true } ], 'Sample Packs', '' ), 'the Add row counted as a match for itself' );

	const label = Combobox.addLabel( ' Sample Packs ', 'collection' );

	expect( 'Add “Sample Packs” as a new collection' === label, `the row reads "${ label }"` );
	expect( 0 === Combobox.addLabel( 'x', '' ).indexOf( 'Add ' ), 'the row does not start with "Add "' );

	const checks = [
		[ /var creatable = ! adds &&/, 'a field that makes real items also keeps the raw text' ],
		[ /results = results\.concat\( \[ \{[\s\S]{0,80}add: true/, 'the Add row is not the last row' ],
		[ /if \( result\.add \) \{\s*add\( String\( result\.text \) \)/, 'choosing the Add row (Enter or click) does not create' ],
		[ /function add\( text \)[\s\S]{0,2000}method: 'POST'[\s\S]{0,400}createEndpoint|kit\.request\( new URL\( createEndpoint/, 'the new item is not posted to the create route' ],
		[ /function add\( text \)[\s\S]{0,3200}take\( String\( answer\.data\.id \)/, 'the new item is not chosen by the id that came back' ],
		[ /function add\( text \)[\s\S]{0,1200}selectedOptions\.length >= max/, 'an item is made past the field\'s limit, where nobody can choose it' ],
		[ /function refuse\([\s\S]{0,600}kit\.announce\( status, notice \)/, 'a refusal is not announced when the list cannot show it' ],
	];

	checks.forEach( ( [ pattern, complaint ] ) => expect( pattern.test( source ), complaint ) );
} )();

/*
 * A drag moves an item one place, not two.
 *
 * dragover fires continuously — many times per second — and the list has
 * already been reordered underneath the pointer since the previous one.
 * Without a guard, a single crossing keeps re-inserting and the item travels
 * two or three places at once, which is what a drag in a flyout was doing.
 */
( function () {
	const source = fs.readFileSync(
		path.join( __dirname, '..', '..', 'assets', 'js', 'field-kit.js' ),
		'utf8'
	);

	if ( ! /reference === dragging \|\| dragging\.nextElementSibling === reference/.test( source ) ) {
		console.error( '  Reorder: a dragover with nothing to change still re-inserts, so one drag moves several places' );
		failures ++;
	}

	if ( ! /if \( ! Reorder\.owns\( list, over \) \|\| over === dragging \)/.test( source ) ) {
		console.error( '  Reorder: dragover no longer bails when there is nothing of this list under the pointer' );
		failures ++;
	}

	/*
	 * A list nested in a row is not the outer list's to reorder.
	 *
	 * Every key and every drag in the inner list bubbles through the outer
	 * one, where the nearest item is still the inner list's -- and moving it
	 * is an insertBefore() on a node the outer list does not hold.
	 */
	const owned = source.match( /Reorder\.owns\( list, (item|dragging|over) \)/g ) || [];

	if ( owned.length < 5 ) {
		console.error( `  Reorder: only ${ owned.length } of the five places an item is acted on check that it is this list's own` );
		failures ++;
	}

	/*
	 * And a nested list is renamed with the row it is in, template included,
	 * or a row added to it later is posted under the row's old index.
	 */
	if ( ! /Repeater\.rename\( row, pattern, prefix \)/.test( source ) || ! /Repeater\.rename\( template\.content, pattern, prefix \)/.test( source ) ) {
		console.error( '  Repeater: renumbering a row no longer renames the lists nested in it' );
		failures ++;
	}

	/*
	 * Which halfway line a drop is measured against.
	 *
	 * Chips in a row sit side by side, so the question is left-or-right.
	 * Asking top-or-bottom there gives two items in the same row the same
	 * answer over most of their area, and one could not be dropped into the
	 * first position at all.
	 *
	 * The helper is pulled out of the source rather than restated, so this
	 * cannot pass against a copy that has drifted.
	 */
	// That it exists is half of it; the other half is that dragover asks it.
	// Testing the helper alone passes against a call site that has gone back
	// to measuring vertically, which is exactly the bug.
	/*
	 * A handler is given the field's own value under `value`.
	 *
	 * Without it a handler has to know the field key to find it, and the key
	 * differs per screen for the same field: a licence is `apfd_license` on a
	 * term screen and `apfd_every_license` on a settings page, so a handler
	 * written for one silently read nothing on the other and reported an
	 * empty box no matter what had been typed into it.
	 */
	if ( ! /payload\.value = input\.value/.test( source ) ) {
		console.error( '  ActionButton: the payload no longer carries the field\'s own value under `value`' );
		failures ++;
	}

	if ( ! /past = Reorder\.isHorizontal\( over \)/.test( source ) ) {
		console.error( '  Reorder: dragover no longer asks which way the list runs, so a grid is measured vertically' );
		failures ++;
	}

	const helper = source.match( /isHorizontal: function \( item \) \{[\s\S]*?\n\t\t\},/ );

	if ( ! helper ) {
		console.error( '  Reorder: isHorizontal is gone, so a grid is measured vertically again' );
		failures ++;
	} else {
		const Reorder = eval( '({ ' + helper[ 0 ] + ' })' );

		const at = ( left, top ) => ( {
			getBoundingClientRect: () => ( { left, top, width: 150, height: 150 } ),
			nextElementSibling: null,
			previousElementSibling: null,
		} );

		const link = ( items ) => {
			items.forEach( ( one, index ) => {
				one.nextElementSibling = items[ index + 1 ] || null;
				one.previousElementSibling = items[ index - 1 ] || null;
			} );

			return items;
		};

		const side = ( over, x, y ) => {
			const box = over.getBoundingClientRect();

			const past = Reorder.isHorizontal( over )
				? ( x - box.left ) / box.width > 0.5
				: ( y - box.top ) / box.height > 0.5;

			return past ? 'after' : 'before';
		};

		// Two columns, so the first two tiles share a row.
		const grid = link( [ at( 0, 0 ), at( 160, 0 ), at( 0, 160 ) ] );

		if ( 'before' !== side( grid[ 0 ], 20, 75 ) ) {
			console.error( '  Reorder: a tile cannot be dropped into the first position of a grid' );
			failures ++;
		}

		if ( 'after' !== side( grid[ 0 ], 130, 75 ) ) {
			console.error( '  Reorder: the right half of a grid tile does not drop after it' );
			failures ++;
		}

		// Rows under each other, where the question really is top-or-bottom.
		const rows = link( [ at( 0, 0 ), at( 0, 160 ) ] );

		if ( 'before' !== side( rows[ 0 ], 75, 10 ) || 'after' !== side( rows[ 0 ], 75, 140 ) ) {
			console.error( '  Reorder: a stacked list is no longer measured vertically' );
			failures ++;
		}
	}
} )();

/*
 * A licence field takes the state its handler reported.
 *
 * The whole point of the field is to say whether the licence is active, so an
 * activation that succeeds and leaves the badge reading "Not active" with a
 * seat count of nought is the one outcome that must not happen — and it is
 * exactly what shipped: the handler's answer went into the status line and
 * nowhere else, and the field told the user to reload.
 *
 * Written against a stub rather than jsdom for the same reason as the rest of
 * this file, but with real classList and textContent, because those are what
 * is being asserted.
 */
( function () {
	/**
	 * An element that remembers what was done to it.
	 *
	 * @param {string} selector What querySelector call should return it.
	 *
	 * @return {Object} The element.
	 */
	function node( selector ) {
		const classes = new Set();

		return {
			selector,
			dataset: {},
			textContent: '',
			hidden: false,
			readOnly: false,
			value: '',
			classList: {
				add: ( name ) => classes.add( name ),
				remove: ( name ) => classes.delete( name ),
				contains: ( name ) => classes.has( name ),
				toggle: ( name, on ) => ( on ? classes.add( name ) : classes.delete( name ) ),
			},
			querySelector: () => null,
		};
	}

	const licence = node( '.field-kit__license' );
	const badge = node( '.field-kit__license-state' );
	const text = node( '.field-kit__license-state-text' );
	const button = node( '.field-kit__license-action' );
	const input = node( '.field-kit__license-key' );
	const meta = node( '.field-kit__license-meta' );
	const sites = node( '.field-kit__license-sites' );

	licence.dataset.labelActive = 'Active';
	licence.dataset.labelInactive = 'Not active';
	meta.dataset.sitesTemplate = '%1$s of %2$s sites';
	button.dataset.actionActivate = 'demo_activate';
	button.dataset.actionDeactivate = 'demo_deactivate';
	button.dataset.labelActivate = 'Activate';
	button.dataset.labelDeactivate = 'Deactivate';
	button.dataset.action = 'demo_activate';

	// The icon is swapped for another, not restyled: an SVG has no class
	// that changes what it draws. Recorded here, and the badge then holds
	// whichever was put in.
	let icon = { glyph: 'inactive' };

	badge.querySelector = ( one ) => ( '.sugarcommerce-icon' === one ? icon : null );
	badge.classList.add( 'field-kit__license-state--inactive' );
	modules.swapIcon = ( el, key ) => {
		if ( el === icon ) {
			icon = { glyph: key };
		}

		return icon;
	};
	sites.hidden = true;
	text.textContent = 'Not active';

	const found = [ licence, badge, text, button, input, meta, sites ];
	const wrap = makeElement();

	wrap.querySelector = ( one ) => found.find( ( each ) => each.selector === one ) || null;

	const ActionButton = modules.ActionButton;

	if ( typeof ActionButton.applyState !== 'function' ) {
		console.error( '  ActionButton: applyState is gone, so a licence never leaves the state it loaded in' );
		failures ++;
	} else {
		// Through report(), not applyState() directly. Testing the helper on
		// its own is how the Reorder bug got past: the helper was correct and
		// nothing called it. This is the path a real response takes.
		const respond = ( state ) => ActionButton.report(
			wrap,
			null,
			{ success: true, message: 'Done.', data: { state } }
		);

		respond( { active: true, key: '*********9012', sites: [ 1, 3 ] } );

		if ( 'Active' !== text.textContent ) {
			console.error( `  License: the badge still reads "${ text.textContent }" after activating` );
			failures ++;
		}

		if ( ! badge.classList.contains( 'field-kit__license-state--active' )
			|| badge.classList.contains( 'field-kit__license-state--inactive' ) ) {
			console.error( '  License: the badge kept its inactive class after activating' );
			failures ++;
		}

		if ( 'active' !== icon.glyph ) {
			console.error( '  License: the badge icon did not change' );
			failures ++;
		}

		if ( '1 of 3 sites' !== sites.textContent || sites.hidden ) {
			console.error( `  License: the seat count reads "${ sites.textContent }" (hidden: ${ sites.hidden })` );
			failures ++;
		}

		// The button has to become the other one, or the next press calls the
		// handler that just ran.
		if ( 'Deactivate' !== button.textContent || 'demo_deactivate' !== button.dataset.action ) {
			console.error( `  License: the button is still "${ button.textContent }" (${ button.dataset.action })` );
			failures ++;
		}

		if ( ! input.readOnly || '*********9012' !== input.value ) {
			console.error( '  License: the key box is still editable, or still holds the typed key' );
			failures ++;
		}

		// And back, which is not symmetrical for free: the reverse swap reads
		// a different pair of data attributes.
		respond( { active: false, key: 'AP-1234-5678-9012', sites: [ 0, 3 ] } );

		if ( 'Not active' !== text.textContent
			|| 'Activate' !== button.textContent
			|| 'demo_activate' !== button.dataset.action
			|| input.readOnly
			|| '0 of 3 sites' !== sites.textContent
			|| ! badge.classList.contains( 'field-kit__license-state--inactive' )
			|| badge.classList.contains( 'field-kit__license-state--active' )
			|| 'inactive' !== icon.glyph ) {
			console.error( '  License: deactivating does not put the field back' );
			failures ++;
		}
	}
} )();

/**
 * An element that remembers its attributes and classes.
 *
 * For the tests that assert on what a module wrote, rather than only that
 * it ran: makeElement() forgets everything set on it, which is right for a
 * smoke test and useless for one that reads the result back.
 *
 * @return {Object} The element.
 */
function recorder() {
	const classes = new Set();
	const attributes = {};

	return {
		dataset: {},
		type: '',
		value: '',
		textContent: '',
		classList: {
			add: ( name ) => classes.add( name ),
			remove: ( name ) => classes.delete( name ),
			contains: ( name ) => classes.has( name ),
			toggle: ( name, on ) => ( on ? classes.add( name ) : classes.delete( name ) ),
		},
		getAttribute: ( name ) => ( name in attributes ? attributes[ name ] : null ),
		setAttribute: ( name, value ) => {
			attributes[ name ] = String( value );
		},
		querySelector: () => null,
		closest: () => null,
		addEventListener() {},
	};
}

/*
 * A reveal button shows what has been typed, and says so.
 *
 * The input's type is what does it, and the button has to describe the
 * state it put the input in -- icon, pressed state and label together, or a
 * screen reader hears "Show password" over a field already showing it. The
 * PHP suite can render the button but never press it.
 */
( function () {
	let icon = { glyph: 'show' };
	const input = recorder();
	const wrap = recorder();
	const button = recorder();
	const clicks = [];

	modules.swapIcon = ( el, key ) => {
		if ( el === icon ) {
			icon = { glyph: key };
		}

		return icon;
	};
	input.type = 'password';
	wrap.querySelector = ( selector ) => ( 'input' === selector ? input : null );
	button.closest = () => wrap;
	button.querySelector = ( selector ) => ( '.sugarcommerce-icon' === selector ? icon : null );
	button.addEventListener = ( type, handler ) => clicks.push( handler );
	button.setAttribute( 'aria-label', 'Show password' );
	button.setAttribute( 'aria-pressed', 'false' );
	button.setAttribute( 'data-toggle', '0' );

	const root = Object.assign( makeElement(), {
		querySelectorAll: ( selector ) => ( selector.includes( 'password-toggle' ) ? [ button ] : [] ),
	} );

	// Twice, because init() runs twice on a normal page load.
	modules.PasswordReveal.init( root );
	modules.PasswordReveal.init( root );

	if ( 1 !== clicks.length ) {
		console.error( `  PasswordReveal: expected one click handler on the button, got ${ clicks.length }` );
		failures ++;

		return;
	}

	clicks[ 0 ]();

	if ( 'text' !== input.type ) {
		console.error( '  PasswordReveal: the input is still a password after a press' );
		failures ++;
	}

	if ( 'hide' !== icon.glyph ) {
		console.error( '  PasswordReveal: the icon did not change' );
		failures ++;
	}

	if ( 'true' !== button.getAttribute( 'aria-pressed' ) || '1' !== button.getAttribute( 'data-toggle' ) ) {
		console.error( '  PasswordReveal: the button does not report itself pressed' );
		failures ++;
	}

	if ( 'Hide password' !== button.getAttribute( 'aria-label' ) ) {
		console.error( `  PasswordReveal: the label still reads "${ button.getAttribute( 'aria-label' ) }" over a shown password` );
		failures ++;
	}

	clicks[ 0 ]();

	if ( 'password' !== input.type
		|| 'show' !== icon.glyph
		|| 'false' !== button.getAttribute( 'aria-pressed' )
		|| '0' !== button.getAttribute( 'data-toggle' )
		|| 'Show password' !== button.getAttribute( 'aria-label' ) ) {
		console.error( '  PasswordReveal: a second press does not hide the password again' );
		failures ++;
	}
} )();

/*
 * A count follows what is typed.
 *
 * Rendered by PHP with the saved value's length and then left to the
 * script, which is the half the PHP suite cannot reach. It also has to find
 * its control through the wrapper an affix puts round the input, or every
 * adorned field with a limit has a count that never moves.
 */
( function () {
	const control = recorder();
	const counter = recorder();
	const listeners = [];

	control.tagName = 'INPUT';
	control.value = 'hello';
	control.setAttribute( 'maxlength', '8' );
	control.addEventListener = ( type, handler ) => listeners.push( [ type, handler ] );
	counter.textContent = '5 / 8';
	counter.previousElementSibling = control;

	modules.Counter.init( Object.assign( makeElement(), {
		querySelectorAll: ( selector ) => ( '[data-counter]' === selector ? [ counter ] : [] ),
	} ) );

	const bound = listeners.find( ( [ type ] ) => 'input' === type );

	if ( ! bound ) {
		console.error( '  Counter: nothing listens to the control, so the count never moves' );
		failures ++;

		return;
	}

	const update = bound[ 1 ];

	control.value = 'hello wor';
	update();

	if ( '9 / 8' !== counter.textContent || ! counter.classList.contains( 'field-kit__count--over' ) ) {
		console.error( `  Counter: reads "${ counter.textContent }" for nine of eight, over: ${ counter.classList.contains( 'field-kit__count--over' ) }` );
		failures ++;
	}

	control.value = 'hi';
	update();

	if ( '2 / 8' !== counter.textContent || counter.classList.contains( 'field-kit__count--over' ) ) {
		console.error( '  Counter: does not come back under the limit' );
		failures ++;
	}

	// Characters, as PHP counted them, not code units.
	control.value = '\u{1F600}\u{1F600}';
	update();

	if ( '2 / 8' !== counter.textContent ) {
		console.error( `  Counter: two emoji read as "${ counter.textContent }"` );
		failures ++;
	}

	// Through the wrapper an affix puts round the input.
	const wrapped = recorder();
	const second = recorder();
	const found = [];

	wrapped.tagName = 'SPAN';
	wrapped.querySelector = ( selector ) => ( 'input, textarea' === selector ? control : null );
	second.previousElementSibling = wrapped;
	control.addEventListener = ( type ) => found.push( type );

	modules.Counter.init( Object.assign( makeElement(), {
		querySelectorAll: ( selector ) => ( '[data-counter]' === selector ? [ second ] : [] ),
	} ) );

	if ( ! found.includes( 'input' ) ) {
		console.error( '  Counter: cannot find its control through an affix wrapper' );
		failures ++;
	}
} )();

/*
 * A region follows the country beside it.
 *
 * PHP draws a text input, live, and a select, empty and disabled, because
 * it cannot know the country at render time. The script asks the endpoint
 * for that country's regions and swaps to the select when there are some,
 * and only then -- a hidden control still posts, so the one standing down
 * has to be disabled, or both submit under one name. And a slow answer for
 * the previous country must not land after the answer for the current one.
 *
 * Asynchronous, because fetch is, so the report waits for it.
 */
const regionChecks = ( async function () {
	/**
	 * An element that keeps the options and listeners given to it.
	 *
	 * @return {object} The element.
	 */
	function control() {
		const element = makeElement();

		element.children = [];
		element.listeners = {};
		element.appendChild = ( node ) => element.children.push( node );
		element.addEventListener = ( type, handler ) => {
			element.listeners[ type ] = handler;
		};

		// Setting textContent on a select empties it; the stub mirrors that.
		Object.defineProperty( element, 'textContent', {
			get: () => element.text || '',
			set: ( value ) => {
				element.text = value;
				element.children = [];
			},
		} );

		return element;
	}

	/**
	 * A region field beside a country control holding the given code.
	 *
	 * @param {string} code The country's value.
	 * @return {object} The parts.
	 */
	function makeRegion( code ) {
		const country = Object.assign( control(), { value: code, name: 'country' } );
		const row = Object.assign( makeElement(), { querySelector: () => country } );
		const select = Object.assign( control(), { disabled: true, hidden: true } );
		const text = Object.assign( control(), { value: 'CA' } );
		const wrap = makeElement();

		wrap.dataset = {
			countryKey: 'country',
			searchEndpoint: 'https://example.test/wp-json/sugarcommerce-fields/v1/search',
			searchNonce: 'n',
		};
		wrap.closest = ( selector ) => ( selector.includes( 'repeater-row' ) ? row : null );
		wrap.querySelector = ( selector ) => {
			if ( selector.includes( 'region-select' ) ) {
				return select;
			}

			if ( selector.includes( 'region-text' ) ) {
				return text;
			}

			return null;
		};

		const root = Object.assign( makeElement(), {
			querySelectorAll: ( selector ) => ( selector.includes( 'field-kit__region' ) ? [ wrap ] : [] ),
		} );

		return { country, select, text, wrap, root };
	}

	// Every request is held until the test answers it, so order can be
	// chosen.
	const requests = [];
	const realFetch = context.fetch;

	context.fetch = ( url ) => new Promise( ( resolve ) => {
		requests.push( {
			url: String( url ),
			answer: ( results ) => resolve( { ok: true, json: () => Promise.resolve( { results } ) } ),
		} );
	} );

	const settle = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

	try {
		// Regions come back: the select takes over, holding the stored value.
		const one = makeRegion( 'US' );

		modules.Region.init( one.root );

		if ( 1 !== requests.length ) {
			console.error( '  Region: nothing was asked for the country beside it' );
			failures ++;
		} else {
			const url = requests[ 0 ].url;

			if ( ! url.includes( 'source=region' ) || ! /args(%5B|\[)country(%5D|\])=US/.test( url ) ) {
				console.error( `  Region: asked the endpoint the wrong question: ${ url }` );
				failures ++;
			}

			requests[ 0 ].answer( [ { id: 'CA', text: 'California' }, { id: 'NY', text: 'New York' } ] );
			await settle();

			if ( one.select.disabled || one.select.hidden ) {
				console.error( '  Region: the select is not live after regions arrived' );
				failures ++;
			}

			if ( ! one.text.disabled || ! one.text.hidden ) {
				console.error( '  Region: the text input still submits beside the select' );
				failures ++;
			}

			const values = one.select.children.map( ( option ) => option.value );

			if ( '["","CA","NY"]' !== JSON.stringify( values ) ) {
				console.error( `  Region: the select holds ${ JSON.stringify( values ) }` );
				failures ++;
			}

			const chosen = one.select.children.filter( ( option ) => option.selected ).map( ( option ) => option.value );

			if ( '["CA"]' !== JSON.stringify( chosen ) ) {
				console.error( `  Region: the stored value was not kept selected: ${ JSON.stringify( chosen ) }` );
				failures ++;
			}

			if ( 'California' !== one.select.children[ 1 ].textContent ) {
				console.error( '  Region: an option is not labelled with its name' );
				failures ++;
			}

			// The country changes to one with no list: back to the text input.
			one.country.value = 'GB';
			one.country.listeners.change();
			requests[ 1 ].answer( [] );
			await settle();

			if ( ! one.select.disabled || ! one.select.hidden || one.select.children.length ) {
				console.error( '  Region: a country with no regions left the select live' );
				failures ++;
			}

			if ( one.text.disabled || one.text.hidden ) {
				console.error( '  Region: the text input did not come back' );
				failures ++;
			}
		}

		// No regions from the start: the text input stays as drawn.
		const two = makeRegion( 'GB' );

		modules.Region.init( two.root );
		requests[ requests.length - 1 ].answer( [] );
		await settle();

		if ( two.text.disabled || two.text.hidden || ! two.select.disabled || ! two.select.hidden ) {
			console.error( '  Region: a country with no regions did not stay on the text input' );
			failures ++;
		}

		// A slow answer for the previous country arrives after the current
		// one and must be ignored.
		const three = makeRegion( 'US' );

		modules.Region.init( three.root );
		const stale = requests[ requests.length - 1 ];

		three.country.value = 'GB';
		three.country.listeners.change();
		requests[ requests.length - 1 ].answer( [] );
		await settle();
		stale.answer( [ { id: 'CA', text: 'California' } ] );
		await settle();

		if ( ! three.select.disabled || three.text.disabled ) {
			console.error( '  Region: a slow answer for the previous country replaced the current one' );
			failures ++;
		}

		// No country control to be found: nothing is asked, text stays live.
		const four = makeRegion( 'US' );
		const asked = requests.length;

		four.wrap.closest = () => null;
		modules.Region.init( four.root );

		if ( requests.length !== asked || four.text.disabled || ! four.select.disabled ) {
			console.error( '  Region: a field with no country beside it did not leave the text input live' );
			failures ++;
		}
	} finally {
		context.fetch = realFetch;
	}
} )();

/*
 * An accordion trigger opens its panel, and says so.
 *
 * The markup is core's but the privacy screen's script is not loaded
 * anywhere else, so the toggle is ours: the button's expanded state and the
 * panel's hidden attribute have to move together or a screen reader hears
 * one thing and sees another.
 */
( function () {
	const button = recorder();
	const panel = recorder();
	const clicks = [];
	const realGetElementById = context.document.getElementById;

	button.dataset = {};
	button.setAttribute( 'aria-expanded', 'false' );
	button.setAttribute( 'aria-controls', 'fk-accordion-1' );
	button.addEventListener = ( type, handler ) => clicks.push( handler );
	panel.hidden = true;

	const root = Object.assign( makeElement(), {
		querySelectorAll: ( selector ) => ( selector.includes( 'accordion-trigger' ) ? [ button ] : [] ),
	} );

	context.document.getElementById = ( id ) => ( 'fk-accordion-1' === id ? panel : null );

	try {
		modules.Accordion.init( root );
		modules.Accordion.init( root );

		if ( 1 !== clicks.length ) {
			console.error( `  Accordion: expected one click handler on the trigger, got ${ clicks.length }` );
			failures ++;

			return;
		}

		clicks[ 0 ]();

		if ( panel.hidden || 'true' !== button.getAttribute( 'aria-expanded' ) ) {
			console.error( '  Accordion: a press did not open the panel and say so' );
			failures ++;
		}

		clicks[ 0 ]();

		if ( ! panel.hidden || 'false' !== button.getAttribute( 'aria-expanded' ) ) {
			console.error( '  Accordion: a second press did not close the panel again' );
			failures ++;
		}
	} finally {
		context.document.getElementById = realGetElementById;
	}
} )();

/*
 * Remove marks a stored secret for clearing, and Undo takes the mark away.
 *
 * The flag is a hidden input that is disabled -- and so not submitted --
 * until the button is pressed. The PHP suite can render the pair and can
 * honour the flag; it cannot press the button, and a button that changed
 * its word without enabling the flag would promise a removal that never
 * happens.
 */
( function () {
	const input = recorder();
	const flag = recorder();
	const wrap = recorder();
	const button = recorder();
	const clicks = [];
	const typed = [];
	const dispatched = [];

	flag.disabled = true;
	input.setAttribute( 'placeholder', 'Saved.' );
	input.addEventListener = ( type, handler ) => 'input' === type && typed.push( handler );
	input.dispatchEvent = ( event ) => dispatched.push( event.type );
	wrap.querySelector = ( selector ) => ( selector.includes( 'password-clear' ) ? flag : input );
	button.closest = () => wrap;
	button.addEventListener = ( type, handler ) => clicks.push( handler );
	Object.assign( button.dataset, {
		remove: 'Remove',
		removeLabel: 'Remove saved API key',
		undo: 'Undo',
		undoLabel: 'Keep saved API key',
		pending: 'Will be removed when you save',
	} );

	const root = Object.assign( makeElement(), {
		querySelectorAll: ( selector ) => ( selector.includes( 'password-remove' ) ? [ button ] : [] ),
	} );

	modules.PasswordRemove.init( root );
	modules.PasswordRemove.init( root );

	if ( 1 !== clicks.length || 1 !== typed.length ) {
		console.error( `  PasswordRemove: expected one handler each on the button and the input, got ${ clicks.length } and ${ typed.length }` );
		failures ++;

		return;
	}

	clicks[ 0 ]();

	if ( flag.disabled || ! wrap.classList.contains( 'is-removing' ) ) {
		console.error( '  PasswordRemove: a press did not enable the flag' );
		failures ++;
	}

	if ( 'Will be removed when you save' !== input.getAttribute( 'placeholder' )
		|| 'Undo' !== button.textContent
		|| 'Keep saved API key' !== button.getAttribute( 'aria-label' ) ) {
		console.error( '  PasswordRemove: the box and the button do not say the value is going' );
		failures ++;
	}

	if ( 'change' !== dispatched[ 0 ] ) {
		console.error( '  PasswordRemove: the form was not told it had changed' );
		failures ++;
	}

	clicks[ 0 ]();

	if ( ! flag.disabled
		|| wrap.classList.contains( 'is-removing' )
		|| 'Saved.' !== input.getAttribute( 'placeholder' )
		|| 'Remove' !== button.textContent
		|| 'Remove saved API key' !== button.getAttribute( 'aria-label' ) ) {
		console.error( '  PasswordRemove: Undo did not put everything back' );
		failures ++;
	}

	// Marked again, and then something typed: the new value is what is
	// wanted, so the mark goes.
	clicks[ 0 ]();
	input.value = 'sk_new';
	typed[ 0 ]();

	if ( ! flag.disabled || 'Remove' !== button.textContent ) {
		console.error( '  PasswordRemove: typing a new value left the field marked for removal' );
		failures ++;
	}
} )();

/*
 * The save bar says the form has changed, and knows when it is at rest.
 */
( function () {
	const status = recorder();
	const bar = recorder();
	const form = recorder();
	const handlers = {};
	const inserted = [];
	let watcher = null;

	status.textContent = 'Saved';
	bar.insertAdjacentElement = ( where, element ) => inserted.push( [ where, element ] );
	form.addEventListener = ( type, handler ) => {
		handlers[ type ] = handler;
	};
	form.querySelector = ( selector ) => ( selector.includes( 'savebar' ) ? bar : status );

	const root = Object.assign( makeElement(), {
		querySelectorAll: ( selector ) => ( selector.includes( 'settings--modern' ) ? [ form ] : [] ),
	} );

	context.window.IntersectionObserver = function ( callback ) {
		watcher = callback;
		this.observe = () => {};
	};

	modules.SettingsBar.init( root );
	modules.SettingsBar.init( root );

	if ( 1 !== inserted.length || 'afterend' !== inserted[ 0 ][ 0 ] ) {
		console.error( '  SettingsBar: expected one sentinel after the bar' );
		failures ++;

		return;
	}

	handlers.input();

	if ( 'Unsaved changes' !== status.textContent || ! status.classList.contains( 'is-unsaved' ) ) {
		console.error( `  SettingsBar: after an edit the bar still says "${ status.textContent }"` );
		failures ++;
	}

	watcher( [ { isIntersecting: true } ] );

	if ( ! bar.classList.contains( 'is-resting' ) ) {
		console.error( '  SettingsBar: the bar is not at rest with the end of the form in view' );
		failures ++;
	}

	watcher( [ { isIntersecting: false } ] );

	if ( bar.classList.contains( 'is-resting' ) ) {
		console.error( '  SettingsBar: the bar is still at rest with more of the form below' );
		failures ++;
	}

	// A folded card opens for the control the browser refused.
	const card = { open: false };

	handlers.invalid( { target: { closest: () => card } } );

	if ( ! card.open ) {
		console.error( '  SettingsBar: a folded card stayed shut over an invalid control' );
		failures ++;
	}
} )();

/*
 * The colour field: what the box shows after a paste is what is saved, and
 * a picked colour reaches the box with an `input` and a `change`, which is
 * what conditional fields and a panel's unsaved-changes check listen for.
 */
( function () {
	const normalize = modules.ColorField.normalize;
	const cases = [
		[ '#E8A5C4', '#e8a5c4' ],
		[ 'e8a5c4', '#e8a5c4' ],
		[ '#abc', '#aabbcc' ],
		[ '  #FFF ', '#ffffff' ],
		[ 'red', '' ],
		[ '#e8a5c', '' ],
		[ '', '' ],
	];

	cases.forEach( ( [ given, expect ] ) => {
		if ( normalize( given ) !== expect ) {
			console.error( `  ColorField: "${ given }" normalised to "${ normalize( given ) }", not "${ expect }"` );
			failures ++;
		}
	} );

	const listeners = {};
	const fired = [];
	const swatch = Object.assign( makeElement(), {
		value: '#000000',
		addEventListener: ( type, fn ) => { listeners[ 'swatch:' + type ] = fn; },
	} );
	const hex = Object.assign( makeElement(), {
		value: '',
		addEventListener: ( type, fn ) => { listeners[ 'hex:' + type ] = fn; },
		dispatchEvent: ( event ) => fired.push( event.type ),
	} );
	const clear = Object.assign( makeElement(), {
		hidden: true,
		addEventListener: ( type, fn ) => { listeners[ 'clear:' + type ] = fn; },
	} );
	const preset = Object.assign( makeElement(), {
		dataset: { color: '#3858e9' },
		pressed: 'false',
		setAttribute( name, value ) { this.pressed = value; },
		addEventListener: ( type, fn ) => { listeners[ 'preset:' + type ] = fn; },
	} );
	const wrap = Object.assign( makeElement(), {
		dataset: { empty: 'true' },
		querySelector: ( selector ) => ( {
			'.field-kit__color-swatch': swatch,
			'.field-kit__color-hex': hex,
			'.field-kit__color-clear': clear,
		}[ selector ] || null ),
		querySelectorAll: () => [ preset ],
	} );
	const root = Object.assign( makeElement(), {
		querySelectorAll: ( selector ) => ( '.field-kit__color-field' === selector ? [ wrap ] : [] ),
	} );

	modules.ColorField.init( root );

	swatch.value = '#E8A5C4';
	listeners[ 'swatch:input' ]();

	if ( '#e8a5c4' !== hex.value || 'false' !== wrap.dataset.empty || clear.hidden ) {
		console.error( `  ColorField: a picked colour did not reach the box (${ hex.value }, empty ${ wrap.dataset.empty })` );
		failures ++;
	}

	if ( ! fired.includes( 'input' ) || ! fired.includes( 'change' ) ) {
		console.error( `  ColorField: a picked colour was not announced (${ JSON.stringify( fired ) })` );
		failures ++;
	}

	listeners[ 'preset:click' ]();

	if ( '#3858e9' !== hex.value || 'true' !== preset.pressed ) {
		console.error( '  ColorField: a preset did not take, or was not marked pressed' );
		failures ++;
	}

	// Typed: the swatch follows as soon as it is a colour.
	hex.value = 'ABC';
	listeners[ 'hex:input' ]();

	if ( '#aabbcc' !== swatch.value ) {
		console.error( `  ColorField: the swatch did not follow a typed colour (${ swatch.value })` );
		failures ++;
	}

	// And the box is tidied once it is done with.
	listeners[ 'hex:change' ]();

	if ( '#aabbcc' !== hex.value ) {
		console.error( `  ColorField: a typed colour was not tidied (${ hex.value })` );
		failures ++;
	}

	listeners[ 'clear:click' ]();

	if ( '' !== hex.value || 'true' !== wrap.dataset.empty || ! clear.hidden ) {
		console.error( '  ColorField: Clear did not empty the field' );
		failures ++;
	}
} )();

/*
 * The icon picker writes a choice to the hidden input and the button, and
 * says so with an `input` and a `change`; and what it builds markup from is
 * escaped.
 */
( function () {
	const fired = [];
	const input = Object.assign( makeElement(), {
		value: '',
		dispatchEvent: ( event ) => fired.push( event.type ),
	} );
	const glyph = Object.assign( makeElement(), { innerHTML: '' } );
	const name = Object.assign( makeElement(), { textContent: 'Choose an icon' } );
	const clear = Object.assign( makeElement(), { hidden: true } );
	const wrap = Object.assign( makeElement(), {
		dataset: { empty: 'true', placeholder: 'No icon', sets: '[{"id":"heroicons-outline","version":"2.2.0"},{"id":"wordpress"}]' },
		querySelector: ( selector ) => ( {
			'.field-kit__icon-value': input,
			'.field-kit__icon-glyph': glyph,
			'.field-kit__icon-name': name,
			'.field-kit__icon-clear': clear,
		}[ selector ] || null ),
	} );

	modules.IconPicker.write( wrap, { value: 'heroicons-outline:star', label: 'Star', svg: '<svg></svg>' } );

	if ( 'heroicons-outline:star' !== input.value || 'Star' !== name.textContent || 'false' !== wrap.dataset.empty || clear.hidden || '<svg></svg>' !== glyph.innerHTML ) {
		console.error( '  IconPicker: a choice was not written to the field' );
		failures ++;
	}

	if ( ! fired.includes( 'input' ) || ! fired.includes( 'change' ) ) {
		console.error( `  IconPicker: a choice was not announced (${ JSON.stringify( fired ) })` );
		failures ++;
	}

	// The set it opens on is the chosen icon's.
	input.value = 'wordpress:cart';

	if ( 'wordpress' !== modules.IconPicker.initial( wrap, modules.IconPicker.sets( wrap ) ).id ) {
		console.error( '  IconPicker: did not open on the chosen icon\'s set' );
		failures ++;
	}

	modules.IconPicker.write( wrap, null );

	if ( '' !== input.value || 'No icon' !== name.textContent || ! clear.hidden || 'true' !== wrap.dataset.empty ) {
		console.error( '  IconPicker: clearing did not empty the field' );
		failures ++;
	}

	if ( '&lt;b&gt;&quot;x&quot;&#039;&amp;' !== modules.IconPicker.escape( '<b>"x"\'&' ) ) {
		console.error( '  IconPicker: escape() let markup through' );
		failures ++;
	}

	// An option's label goes into an attribute, escaped.
	const markup = modules.IconPicker.options( [ { value: 'a:b', label: '"><img src=x onerror=alert(1)>', svg: '<svg></svg>' } ], 'a:b' );

	if ( markup.includes( '"><img' ) || ! markup.includes( 'aria-selected="true"' ) ) {
		console.error( `  IconPicker: an option was built unsafely or unselected: ${ markup }` );
		failures ++;
	}
} )();

/*
 * A visual editor that arrived after the page loaded is started, once, and
 * stopped when its markup goes; one core started is left alone.
 *
 * A panel fetched over REST brings wp_editor()'s markup without the settings
 * wp_editor() prints in the footer, so nothing ever started it: no Visual
 * tab, an empty Code toolbar. And the same panel opened twice brought the
 * same editor id back while TinyMCE still held the first one. The PHP suite
 * renders the markup and cannot see either.
 */
( function () {
	const editors = {};
	const started = [];
	const removed = [];
	const quicktags = [];

	function editorFor( settings ) {
		const id = settings.selector.slice( 1 );
		const element = elements[ id ];

		return {
			id,
			isHidden: () => false,
			getElement: () => element,
			save() {},
			on() {},
			remove() {
				removed.push( id );
				delete editors[ id ];
			},
		};
	}

	const elements = {};

	function editorMarkup( id, extra ) {
		const textarea = Object.assign( recorder(), { id, value: '<p>Hi</p>' } );
		const toolbar = recorder();
		const editorWrap = Object.assign( recorder(), { querySelector: ( s ) => ( '.quicktags-toolbar' === s ? toolbar : null ) } );
		const wrap = Object.assign( recorder(), {
			dataset: Object.assign( { editor: id }, extra || {} ),
			contains: ( el ) => el === textarea,
		} );

		editorWrap.classList.add( 'tmce-active' );
		elements[ id ] = textarea;
		elements[ 'wp-' + id + '-wrap' ] = editorWrap;

		return { wrap, textarea, root: Object.assign( makeElement(), { querySelectorAll: ( s ) => ( s.includes( 'wysiwyg-wrap' ) ? [ wrap ] : [] ) } ) };
	}

	const before = {
		getElementById: documentStub.getElementById,
		body: documentStub.body,
	};

	documentStub.getElementById = ( id ) => elements[ id ] || null;
	documentStub.body = Object.assign( makeElement(), { contains: ( el ) => Object.values( elements ).includes( el ) } );
	context.window.tinymce = {
		init: ( settings ) => {
			started.push( settings );
			editors[ settings.selector.slice( 1 ) ] = editorFor( settings );
		},
		get: ( id ) => editors[ id ] || null,
	};
	context.window.wp = { editor: { getDefaultSettings: () => ( { tinymce: { wpautop: true, toolbar1: 'bold' }, quicktags: { buttons: 'strong,em' } } ) } };
	context.window.quicktags = ( settings ) => quicktags.push( settings.id );
	context.window.QTags = { instances: {}, _buttonsInit() {} };
	delete context.window.tinyMCEPreInit;

	try {
		// Started from core's defaults on the markup that is there, with the
		// field's own height and the short toolbar it asked for.
		const first = editorMarkup( 'panel_body', { teeny: '1', height: '260' } );

		modules.Wysiwyg.init( first.root );
		modules.Wysiwyg.init( first.root );

		if ( 1 !== started.length || '#panel_body' !== started[ 0 ].selector || 260 !== started[ 0 ].height || '' !== started[ 0 ].toolbar2 || typeof started[ 0 ].setup !== 'function' ) {
			console.error( `  Wysiwyg: expected one editor started on #panel_body, got ${ JSON.stringify( started ) }` );
			failures ++;
		}

		if ( 1 !== quicktags.length || ! context.window.tinyMCEPreInit || ! context.window.tinyMCEPreInit.mceInit.panel_body || 'strong,em,link,block,del,ins,img,ul,ol,li,code,more,close' !== context.window.tinyMCEPreInit.qtInit.panel_body.buttons ) {
			console.error( '  Wysiwyg: the Code toolbar, or the settings Visual switches back with, were not set up' );
			failures ++;
		}

		// The panel closes: its editor goes with it.
		modules.destroy( first.root );

		if ( 1 !== removed.length || modules.Wysiwyg.ours.panel_body ) {
			console.error( `  Wysiwyg: closing the panel did not stop its editor (${ removed.join( ',' ) })` );
			failures ++;
		}

		// Opened again: the same id, new markup, a new editor.
		const again = editorMarkup( 'panel_body' );

		modules.Wysiwyg.init( again.root );

		if ( 2 !== started.length || editors.panel_body.getElement() !== again.textarea ) {
			console.error( '  Wysiwyg: a panel opened a second time did not get an editor of its own' );
			failures ++;
		}

		// Closed without being told -- the markup simply replaced -- and
		// opened again: the stale editor is stopped before the new one.
		const third = editorMarkup( 'panel_body' );

		modules.Wysiwyg.init( third.root );

		if ( 3 !== started.length || 2 !== removed.length ) {
			console.error( `  Wysiwyg: a stale editor was not stopped first (started ${ started.length }, removed ${ removed.length })` );
			failures ++;
		}

		// One core printed with the page and started is core's.
		const page = editorMarkup( 'content' );

		context.window.tinyMCEPreInit.mceInit.content = { selector: '#content' };
		editors.content = editorFor( { selector: '#content' } );
		modules.Wysiwyg.init( page.root );

		if ( 3 !== started.length || modules.Wysiwyg.ours.content ) {
			console.error( '  Wysiwyg: restarted an editor core had already started' );
			failures ++;
		}
	} finally {
		documentStub.getElementById = before.getElementById;
		documentStub.body = before.body;
		delete context.window.tinymce;
		delete context.window.wp;
		delete context.window.quicktags;
		delete context.window.QTags;
		delete context.window.tinyMCEPreInit;
	}
} )();

/*
 * A region follows the country beside it, not the first country in the form.
 *
 * In a panel with a table of rates above an address group, the address's
 * region matched `rates[0][country]` -- any name ending in `[country]` --
 * and offered that country's regions.
 */
( function () {
	const groupCountry = { name: 'address[country]' };
	const rowCountry = { name: 'rates[0][country]' };
	const asked = [];
	const form = {
		querySelector: ( selector ) => {
			asked.push( selector );

			if ( '[name="address[country]"]' === selector ) {
				return groupCountry;
			}

			return selector.includes( '$=' ) ? rowCountry : null;
		},
	};
	const own = { getAttribute: ( name ) => ( 'name' === name ? 'address[region]' : null ) };
	const wrap = Object.assign( makeElement(), {
		closest: ( selector ) => ( 'form' === selector ? form : null ),
		querySelector: ( selector ) => ( '[name]' === selector ? own : null ),
	} );

	if ( modules.sibling( wrap, 'country' ) !== groupCountry ) {
		console.error( `  sibling(): found ${ JSON.stringify( modules.sibling( wrap, 'country' ) ) } for address[region], asked ${ asked.join( ' then ' ) }` );
		failures ++;
	}

	// A bare name looks for the bare sibling.
	const top = Object.assign( makeElement(), {
		closest: ( selector ) => ( 'form' === selector ? { querySelector: ( selector2 ) => ( '[name="country"]' === selector2 ? 'top' : 'row' ) } : null ),
		querySelector: () => ( { getAttribute: () => 'region' } ),
	} );

	if ( 'top' !== modules.sibling( top, 'country' ) ) {
		console.error( '  sibling(): a bare region did not find the bare country first' );
		failures ++;
	}
} )();

/**
 * The report, once the asynchronous checks have settled.
 */
function finish() {
	if ( failures ) {
		console.error( `\n${ failures } failure(s)` );
		process.exit( 1 );
	}

	console.log( `  ${ expected.length } modules loaded and initialised cleanly, colour picker signals correctly, a native colour keeps its swatch and box in step, an icon choice is written and escaped, an added repeater row is live and renumbered, chips reorder, a root binds once, a password reveals, a count counts, a region follows its country and not another's, an accordion opens and closes, a secret is marked for removal and unmarked, the save bar says what changed, a late visual editor starts once and stops with its markup, requests go through apiFetch` );
}

/*
 * Requests go through wp.apiFetch, which renews a nonce that ran out while
 * the screen was open. A plain fetch with the nonce printed on the page got a
 * 403 from every search and button the morning after.
 */
async function requestChecks() {
	const calls = [];
	let reply = () => Promise.resolve( { items: [ 1 ] } );

	context.window.wp = {
		apiFetch: ( options ) => {
			calls.push( options );

			return reply( options );
		},
	};

	try {
		if ( typeof modules.request !== 'function' ) {
			console.error( '  request(): not exposed' );
			failures ++;

			return;
		}

		// The icon list asks through apiFetch and gets the body back.
		const wrap = Object.assign( makeElement(), { dataset: { endpoint: 'https://example.test/wp-json/sugarcommerce-fields/v1/icons', nonce: 'stale' } } );
		const page = await modules.IconPicker.fetch( wrap, { id: 'heroicons', version: '1' }, 'cart', 2 );

		if ( 1 !== calls.length || ! /\/icons\?set=heroicons&search=cart&page=2/.test( calls[ 0 ].url ) || true !== calls[ 0 ].parse ) {
			console.error( `  request(): the icon list did not go through apiFetch: ${ JSON.stringify( calls ) }` );
			failures ++;
		} else if ( ! page || 1 !== page.items[ 0 ] ) {
			console.error( '  request(): the icon list lost its answer' );
			failures ++;
		}

		// A POST keeps its method and body; a refusal still reads as one.
		reply = () => Promise.reject( { code: 'rest_forbidden', message: 'No.', data: { status: 403 } } );

		const refused = await modules.request( 'https://example.test/wp-json/x', { method: 'POST', body: '{"a":1}' } );
		const body = await refused.json();

		if ( 'POST' !== calls[ 1 ].method || '{"a":1}' !== calls[ 1 ].body || false !== refused.ok || 403 !== refused.status || 'No.' !== body.message ) {
			console.error( '  request(): a refusal did not read like a fetch Response' );
			failures ++;
		}

		// A body that was not JSON fails to parse, as fetch's would.
		reply = () => Promise.reject( { code: 'invalid_json', message: 'Not JSON.' } );

		const broken = await modules.request( 'https://example.test/wp-json/x' );
		const parsed = await broken.json().then( () => 'parsed', () => 'rejected' );

		if ( 'rejected' !== parsed ) {
			console.error( '  request(): a body that was not JSON parsed' );
			failures ++;
		}
	} finally {
		delete context.window.wp;
	}
}

/**
 * The pure parts of the new modules: what a slug becomes, a count's words,
 * and the map address the script writes, which must be the one PHP writes.
 */
function smallerChecks() {
	const expect = ( ok, message ) => {
		if ( ! ok ) {
			console.error( `  ${ message }` );
			failures ++;
		}
	};

	expect( 'creme-brulee-2026' === modules.Slug.make( '  Crème Brûlée 2026! ' ), `Slug: ${ modules.Slug.make( '  Crème Brûlée 2026! ' ) }` );
	expect( '' === modules.Slug.make( '---' ), 'Slug: hyphens alone are no slug' );
	expect( '5 / 200' === modules.Counter.text( 5, 200, 0 ), 'Counter: a hard limit alone' );
	expect( '5 / 60' === modules.Counter.text( 5, 0, 60 ), 'Counter: a soft limit alone' );
	expect( '5 / 60 (up to 160)' === modules.Counter.text( 5, 160, 60 ), 'Counter: both limits' );
	expect(
		'https://www.openstreetmap.org/export/embed.html?bbox=-1.5541%2C53.7958%2C-1.5441%2C53.8058&layer=mapnik&marker=53.8008%2C-1.5491' === modules.Location.url( 53.8008, -1.5491 ),
		`Location: the map is not LocationType::map_url()'s: ${ modules.Location.url( 53.8008, -1.5491 ) }`
	);
	expect( '53.8008' === modules.Location.number( 53.800800000000002 ), 'Location: a float keeps its binary tail' );
	expect( '0' === modules.Location.number( -0.00000001 ), 'Location: minus nothing is nothing' );
	expect( false === modules.Guard.dirty(), 'Guard: nothing guarded is dirty' );
	expect( true === modules.Guard.confirm(), 'Guard: nothing to confirm asks nothing' );

	// A gallery writes its order to the hidden input, says it changed, and
	// turns its button off when it is full.
	const input = makeElement();
	const add = makeElement();
	const wrap = makeElement();
	const list = makeElement();
	const changes = [];

	input.dispatchEvent = ( event ) => changes.push( event.type );
	wrap.dataset = { max: '2' };
	wrap.querySelector = ( selector ) => ( selector.includes( 'media-value' ) ? input : ( selector.includes( 'gallery-add' ) ? add : null ) );
	list.closest = () => wrap;
	list.querySelectorAll = () => [ 7, 3 ].map( ( id ) => Object.assign( makeElement(), { dataset: { id: String( id ) } } ) );

	modules.Gallery.sync( list );

	expect( '7,3' === input.value, `Gallery: the order written was ${ input.value }` );
	expect( 1 === changes.length, 'Gallery: a new order is not announced as a change' );
	expect( true === add.disabled, 'Gallery: a full gallery still offers to add' );
	expect( 'false' === list.dataset.empty, 'Gallery: a gallery with pictures says it is empty' );

	modules.Gallery.sync( list );

	expect( 1 === changes.length, 'Gallery: the same order is announced as a change' );
}

/**
 * A file source: a bucket's picker fills a file field through the same path
 * a media-library choice takes, a dismissed picker changes nothing, and a
 * button for a source whose script never registered is hidden.
 */
async function sourceChecks() {
	const expect = ( ok, message ) => {
		if ( ! ok ) {
			console.error( `  ${ message }` );
			failures ++;
		}
	};

	const input = makeElement();
	const clear = makeElement();
	const known = makeElement();
	const unknown = makeElement();
	const wrap = makeElement();
	const changes = [];
	let answer = { value: 's3://bucket/packs/one.zip', name: 'one.zip' };
	let asked = null;

	input.dispatchEvent = ( event ) => changes.push( event.type );
	clear.hidden = true;
	known.dataset = { source: 'bucket' };
	unknown.dataset = { source: 'drive' };
	wrap.dataset = { returns: 'url', accept: 'zip,wav' };
	wrap.querySelector = ( selector ) => ( {
		'.field-kit__media-value': input,
		'.field-kit__media-clear': clear,
	}[ selector ] || null );
	wrap.querySelectorAll = ( selector ) => ( selector.includes( 'media-source' ) ? [ known, unknown ] : [] );

	context.window.sugarcommerceFileSources = {
		bucket: {
			label: 'Bucket',
			pick: ( options ) => {
				asked = options;

				return Promise.resolve( answer );
			},
		},
	};

	modules.Media.sources( wrap );

	expect( false === known.hidden, 'Sources: a registered source is hidden' );
	expect( true === unknown.hidden, 'Sources: a source whose script never registered shows a dead button' );

	await modules.Media.fromSource( wrap, 'bucket' );

	expect( 's3://bucket/packs/one.zip' === input.value, `Sources: the field holds ${ input.value }` );
	expect( changes.includes( 'change' ), 'Sources: a picked file is not announced as a change' );
	expect( false === clear.hidden, 'Sources: Remove did not appear for a picked file' );
	expect( asked && 'zip,wav' === asked.accept.join( ',' ) && 'address' === asked.returns, 'Sources: the picker was not told what the field takes' );

	answer = null;
	changes.length = 0;

	await modules.Media.fromSource( wrap, 'bucket' );

	expect( 's3://bucket/packs/one.zip' === input.value && 0 === changes.length, 'Sources: a dismissed picker changed the field' );
	expect( undefined === modules.Media.fromSource( wrap, 'drive' ), 'Sources: an unregistered source was asked' );

	delete context.window.sugarcommerceFileSources;
}

regionChecks.then( requestChecks ).then( sourceChecks ).then( smallerChecks ).then( finish, ( error ) => {
	console.error( `  Region: ${ error.message }` );
	failures ++;
	finish();
} );
