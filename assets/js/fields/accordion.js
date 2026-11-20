/**
 * Accordion sections: a heading that folds the fields after it away.
 */

const Accordion = {
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
	init( root ) {
		root.querySelectorAll(
			'.field-kit__accordion .privacy-settings-accordion-trigger'
		).forEach( function ( button ) {
			if ( button.dataset.fkBound ) {
				return;
			}

			button.dataset.fkBound = '1';

			button.addEventListener( 'click', function () {
				const open = 'true' === button.getAttribute( 'aria-expanded' );
				const panel = document.getElementById(
					button.getAttribute( 'aria-controls' ) || ''
				);

				button.setAttribute( 'aria-expanded', open ? 'false' : 'true' );

				if ( panel ) {
					panel.hidden = open;
				}
			} );
		} );
	},
};

export default Accordion;
