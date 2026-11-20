/**
 * What a closed repeater row says after its name (`summary`), the grab
 * handle on a chip of an ordered picker, and an image field's empty box
 * keeping its picture when its words change.
 */

import { afterEach, beforeAll, describe, expect, test, vi } from 'vitest';

vi.mock( '@wordpress/api-fetch', () => ( {
	default: () => Promise.resolve( {} ),
} ) );

let modules;

beforeAll( async () => {
	Object.defineProperty( document, 'currentScript', {
		value: { id: 'sugarcommerce-fields-js' },
		configurable: true,
	} );
	window.SugarCommerceFields = {
		'sugarcommerce-fields': {
			restUrl: 'https://a.test/wp-json/sugarcommerce-fields/v1/',
			restNonce: 'n',
			i18n: {},
			icons: { drag: '<svg class="drag"></svg>' },
		},
	};

	await import( '../../assets/js/field-kit.js' );

	modules = window.SugarCommerceFields[ 'sugarcommerce-fields' ].modules;
} );

afterEach( () => {
	document.body.innerHTML = '';
} );

// The price summary SugarCart configures, as PHP normalises it.
const PRICE = [
	{
		text: [ '{amount} / {interval|lower}' ],
		when: [ { field: 'recurring', operator: '=', value: '1' } ],
	},
	{
		text: [ '{amount} · one-off', 'Free · one-off' ],
		when: [ { field: 'recurring', operator: '!=', value: '1' } ],
	},
	{
		text: [ '{trial}-day trial' ],
		when: [ { field: 'recurring', operator: '=', value: '1' } ],
	},
	{ text: [ 'was {compare}' ], when: [] },
	{
		text: [ 'Licence' ],
		when: [ { field: 'licence', operator: '=', value: true } ],
	},
];

/**
 * A price row: the fields as the renderer draws them, closed.
 *
 * @param {Object} values What the row holds.
 * @return {string} Markup.
 */
function priceRow( values ) {
	const v = Object.assign(
		{
			amount: '19.99',
			recurring: '0',
			interval: 'month',
			trial: '0',
			compare: '',
			licence: false,
			fileAmount: '',
		},
		values
	);

	const card = ( value, title ) =>
		`<label class="field-kit__card"><input type="radio" name="prices[0][recurring]" value="${ value }"${
			v.recurring === value ? ' checked' : ''
		}><span class="field-kit__card-body"><span class="field-kit__card-title">${ title }</span><span class="field-kit__card-description">Charge a fee</span></span></label>`;

	return `
	<li class="field-kit__repeater-row field-kit__repeater-row--collapsible is-closed" data-index="0">
		<div class="field-kit__repeater-header">
			<span class="field-kit__repeater-heading">
				<span class="field-kit__repeater-title">Standard</span>
				<span class="field-kit__repeater-summary"></span>
			</span>
		</div>
		<div class="field-kit__repeater-fields">
			<div class="field-kit__repeater" data-field-name="prices[0][files]">
				<ol class="field-kit__repeater-rows">
					<li class="field-kit__repeater-row" data-index="0">
						<div class="field-kit__field" data-field-key="amount"><div class="field-kit__money"><span class="field-kit__money-symbol">£</span><input type="text" name="prices[0][files][0][amount]" value="${
							v.fileAmount
						}"></div></div>
					</li>
				</ol>
			</div>
			<div class="field-kit__field" data-field-key="recurring">${ card(
				'0',
				'One-off'
			) }${ card( '1', 'Recurring' ) }</div>
			<div class="field-kit__field" data-field-key="amount"><div class="field-kit__money"><span class="field-kit__money-symbol">£</span><input type="text" name="prices[0][amount]" value="${
				v.amount
			}"></div></div>
			<div class="field-kit__field" data-field-key="compare"><div class="field-kit__money"><span class="field-kit__money-symbol">£</span><input type="text" name="prices[0][compare]" value="${
				v.compare
			}"></div></div>
			<div class="field-kit__field" data-field-key="interval"><select name="prices[0][interval]">
				<option value="month"${
					'month' === v.interval ? ' selected' : ''
				}>Month</option>
				<option value="year"${ 'year' === v.interval ? ' selected' : '' }>Year</option>
			</select></div>
			<div class="field-kit__field" data-field-key="trial"><input type="number" name="prices[0][trial]" value="${
				v.trial
			}"></div>
			<div class="field-kit__field" data-field-key="licence"><label><input type="checkbox" name="prices[0][licence]" value="1"${
				v.licence ? ' checked' : ''
			}> Issues a licence</label></div>
		</div>
	</li>`;
}

/**
 * A price repeater holding one row, started.
 *
 * @param {Object} values What the row holds.
 * @return {Element} The repeater.
 */
function prices( values ) {
	document.body.innerHTML = `<div class="field-kit__repeater field-kit__repeater--collapsible" data-field-name="prices"><ol class="field-kit__repeater-rows">${ priceRow(
		values
	) }</ol></div>`;

	const wrap = document.querySelector( '.field-kit__repeater' );

	wrap.dataset.rowSummary = JSON.stringify( PRICE );
	modules.Repeater.init( document );

	return wrap;
}

function said( nth = 0 ) {
	return document.querySelectorAll( '.field-kit__repeater-summary' )[ nth ]
		.textContent;
}

describe( 'a closed row’s summary', () => {
	test( 'says what the row holds as it is shown, when it opens', () => {
		prices( {} );

		expect( said() ).toBe( '£19.99 · one-off' );
	} );

	test( 'says only what is set: a zero trial, an empty compare-at and no licence are left out', () => {
		prices( { recurring: '1', trial: '0', compare: '' } );

		expect( said() ).toBe( '£19.99 / month' );
	} );

	test( 'says each setting that is set, a choice by its label and lower-cased where asked', () => {
		prices( {
			recurring: '1',
			interval: 'year',
			trial: '14',
			compare: '29.99',
			licence: true,
		} );

		expect( said() ).toBe(
			'£19.99 / year · 14-day trial · was £29.99 · Licence'
		);
	} );

	test( 'takes the next template where the first has nothing to fill it', () => {
		prices( { amount: '0.00' } );

		expect( said() ).toBe( 'Free · one-off' );
	} );

	test( 'follows the fields as they are changed', () => {
		prices( {} );

		const amount = document.querySelector( '[name="prices[0][amount]"]' );

		amount.value = '25';
		amount.dispatchEvent( new Event( 'input', { bubbles: true } ) );

		expect( said() ).toBe( '£25 · one-off' );

		const recurring = document.querySelector(
			'[name="prices[0][recurring]"][value="1"]'
		);

		recurring.checked = true;
		recurring.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( said() ).toBe( '£25 / month' );

		const licence = document.querySelector( '[name="prices[0][licence]"]' );

		licence.checked = true;
		licence.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( said() ).toBe( '£25 / month · Licence' );
	} );

	test( 'reads the row’s own fields, not one of the same key in a list nested in it', () => {
		prices( { fileAmount: '99' } );

		expect( said() ).toBe( '£19.99 · one-off' );

		// A change inside the nested row is still the price's to summarise.
		const nested = document.querySelector(
			'[name="prices[0][files][0][amount]"]'
		);
		const amount = document.querySelector( '[name="prices[0][amount]"]' );

		amount.value = '5';
		nested.dispatchEvent( new Event( 'input', { bubbles: true } ) );

		expect( said() ).toBe( '£5 · one-off' );
	} );

	test( 'is written for a row added as a copy of another', () => {
		const wrap = prices( { recurring: '1', trial: '7' } );
		const template = document.createElement( 'template' );

		template.className = 'field-kit__repeater-template';
		template.innerHTML = priceRow( {} )
			.replace( /prices\[0\]/g, 'prices[-1]' )
			.replace( ' is-closed', '' );
		wrap.appendChild( template );

		modules.Repeater.duplicate(
			wrap,
			document.querySelector( '.field-kit__repeater-row' )
		);

		expect( said( 1 ) ).toBe( '£19.99 / month · 7-day trial' );
	} );

	test( 'puts a space after a symbol that is letters', () => {
		const field = document.createElement( 'div' );

		field.innerHTML =
			'<div class="field-kit__money"><span class="field-kit__money-symbol">CHF</span><input value="10"></div>';

		expect( modules.Repeater.shown( field ) ).toBe( 'CHF 10' );
	} );

	test( 'is nothing for a repeater without one', () => {
		document.body.innerHTML = `<div class="field-kit__repeater" data-field-name="prices"><ol class="field-kit__repeater-rows">${ priceRow(
			{}
		) }</ol></div>`;

		modules.Repeater.init( document );

		expect( said() ).toBe( '' );
	} );
} );

describe( 'an ordered picker’s chips', () => {
	/**
	 * A sortable multiple select with two chosen, started.
	 *
	 * @return {Element} The chip list.
	 */
	function picker() {
		document.body.innerHTML = `<select id="cross" class="field-kit__relational" multiple data-sortable="true">
			<option value="6" selected>Drum &amp; Bass Presets</option>
			<option value="7" selected>Ambient Sample Pack</option>
			<option value="8" selected>Lo-Fi Stems</option>
		</select>`;

		modules.Combobox.init( document );
		modules.Reorder.init( document );

		return document.querySelector( '.field-kit__combobox-chips' );
	}

	test( 'every chip has the same controls: one handle and Remove, nothing to hide on the first or last', () => {
		const chips = picker();
		const rows = [
			...chips.querySelectorAll( '.field-kit__combobox-chip' ),
		];

		expect( rows ).toHaveLength( 3 );
		expect( chips.querySelectorAll( '[data-direction]' ) ).toHaveLength(
			0
		);

		rows.forEach( ( row ) => {
			const buttons = [ ...row.querySelectorAll( 'button' ) ];

			expect( buttons.map( ( b ) => b.className ) ).toEqual( [
				'field-kit__drag-handle field-kit__combobox-chip-handle',
				'field-kit__combobox-chip-remove',
			] );
			expect( buttons.some( ( b ) => b.disabled ) ).toBe( false );
		} );
	} );

	test( 'a handle is named for its chip and where it is, and moves it with the arrow keys', () => {
		const chips = picker();
		const handles = () => [
			...chips.querySelectorAll( '.field-kit__combobox-chip-handle' ),
		];

		expect(
			handles().map( ( h ) => h.getAttribute( 'aria-label' ) )
		).toEqual( [
			'Reorder Drum & Bass Presets, 1 of 3',
			'Reorder Ambient Sample Pack, 2 of 3',
			'Reorder Lo-Fi Stems, 3 of 3',
		] );
		expect( handles()[ 0 ].getAttribute( 'aria-roledescription' ) ).toBe(
			'Sortable'
		);

		handles()[ 0 ].dispatchEvent(
			new KeyboardEvent( 'keydown', { key: 'ArrowDown', bubbles: true } )
		);

		expect(
			handles().map( ( h ) => h.getAttribute( 'aria-label' ) )
		).toEqual( [
			'Reorder Ambient Sample Pack, 1 of 3',
			'Reorder Drum & Bass Presets, 2 of 3',
			'Reorder Lo-Fi Stems, 3 of 3',
		] );

		// And the select posts in the new order.
		const select = document.querySelector( 'select' );

		expect(
			[ ...select.selectedOptions ].map( ( option ) => option.value )
		).toEqual( [ '7', '6', '8' ] );
	} );
} );

describe( 'an image field’s choose button', () => {
	test( 'keeps its picture when its words change to Replace and back', () => {
		document.body.innerHTML = `<div class="field-kit__media field-kit__media--image" data-choose-label="Set Product Image" data-replace-label="Replace">
			<div class="field-kit__media-preview" data-empty="true"></div>
			<input type="hidden" class="field-kit__media-value" value="">
			<p class="field-kit__media-actions"><button class="button field-kit__media-choose"><span class="field-kit__media-choose-icon" aria-hidden="true"><svg></svg></span><span class="field-kit__media-choose-label">Set Product Image</span></button>
			<button class="button field-kit__media-clear" hidden>Remove</button></p>
		</div>`;

		const wrap = document.querySelector( '.field-kit__media' );
		const choose = wrap.querySelector( '.field-kit__media-choose' );

		modules.Media.apply( wrap, { id: 5, filename: 'a.jpg' } );

		expect( choose.textContent ).toBe( 'Replace' );
		expect( choose.querySelector( 'svg' ) ).not.toBeNull();

		modules.Media.clear( wrap );

		expect( choose.textContent ).toBe( 'Set Product Image' );
		expect( choose.querySelector( 'svg' ) ).not.toBeNull();
	} );
} );
