/**
 * Tags: the values typed into one input, shown and announced as a list.
 */

import { ours } from '../lib/dom';

const Tags = {
	/**
	 * Mirror each tags input into a visible, announced list.
	 *
	 * The input stays the source of truth and stays editable, so the
	 * field is fully usable before this runs and remains so if it fails.
	 *
	 * @param {Element} root Container.
	 */
	init( root ) {
		ours( root, '.field-kit__tags' ).forEach( function ( wrap ) {
			if ( wrap.dataset.fkBound ) {
				return;
			}

			wrap.dataset.fkBound = '1';

			const input = wrap.querySelector( '.field-kit__tags-input' );
			const list = wrap.querySelector( '.field-kit__tags-list' );

			if ( ! input || ! list ) {
				return;
			}

			const separator = input.dataset.separator || ',';

			function render() {
				list.innerHTML = '';

				input.value
					.split( separator )
					.map( function ( tag ) {
						return tag.trim();
					} )
					.filter( Boolean )
					.forEach( function ( tag ) {
						const pill = document.createElement( 'span' );
						pill.className = 'field-kit__tag';
						pill.textContent = tag;
						list.appendChild( pill );
					} );
			}

			input.addEventListener( 'input', render );
			render();
		} );
	},
};

export default Tags;
