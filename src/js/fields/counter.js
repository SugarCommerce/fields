/**
 * Character count: how much has been typed, against a length to aim for
 * and the most allowed.
 */

import { t } from '../lib/config';

const Counter = {
	/**
	 * Bind every count within a root.
	 *
	 * The number is rendered by PHP from the saved value, so a count this
	 * never reaches is right until the first keystroke rather than blank.
	 *
	 * @param {Element} root Container.
	 */
	init( root ) {
		root.querySelectorAll( '[data-counter]' ).forEach(
			function ( counter ) {
				if ( counter.dataset.fkBound ) {
					return;
				}

				counter.dataset.fkBound = '1';

				const control = Counter.control( counter );

				if ( ! control ) {
					return;
				}

				control.addEventListener( 'input', function () {
					Counter.update( counter, control );
				} );
			}
		);
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
	control( counter ) {
		const previous = counter.previousElementSibling;

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
	update( counter, control ) {
		const max = parseInt( control.getAttribute( 'maxlength' ), 10 ) || 0;
		const soft = parseInt( counter.dataset.soft, 10 ) || 0;
		const length = Array.from( control.value || '' ).length;

		counter.textContent = Counter.text( length, max, soft );
		counter.classList.toggle(
			'field-kit__count--over',
			max > 0 && length > max
		);
		counter.classList.toggle(
			'field-kit__count--soft',
			soft > 0 && length > soft
		);
	},

	/**
	 * The words of a count, as Markup::count() writes them.
	 *
	 * @param {number} length Characters.
	 * @param {number} max    The hard limit, or 0.
	 * @param {number} soft   The length to aim for, or 0.
	 * @return {string} The count.
	 */
	text( length, max, soft ) {
		if ( ! soft ) {
			return length + ' / ' + max;
		}

		if ( ! max ) {
			return length + ' / ' + soft;
		}

		return t( 'countUpTo', '%1$d / %2$d (up to %3$d)' )
			.replace( '%1$d', length )
			.replace( '%2$d', soft )
			.replace( '%3$d', max );
	},
};

export default Counter;
