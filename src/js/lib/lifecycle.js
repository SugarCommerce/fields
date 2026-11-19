/**
 * Starting the modules on markup, and letting go of it.
 */

import { kit } from './kit';

/**
 * The modules init() starts, in the order it starts them.
 */
export const ORDER = [
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
];

/**
 * Bind every module within a root.
 *
 * Safe to call again: each module marks what it has bound, so newly
 * inserted markup — a repeater row, a flyout that just opened — can be
 * initialised without rebinding what is already live.
 *
 * @param {Element} root Container, defaulting to the document.
 */
export function init( root ) {
	root = root || document;

	ORDER.forEach( ( name ) => {
		const module = kit[ name ];

		if ( module && typeof module.init === 'function' ) {
			module.init( root );
		}
	} );
}

/**
 * Let go of what the modules started within a root that is about to be
 * removed: a flyout closing, a row deleted.
 *
 * Only a module that holds something outside the markup -- a TinyMCE
 * instance is kept by id in TinyMCE's own registry -- has anything to
 * do. Left alone, the same id coming back on a new copy of the markup
 * found the old instance still registered.
 *
 * @param {Element} root Container.
 */
export function destroy( root ) {
	if ( ! root ) {
		return;
	}

	Object.keys( kit ).forEach( ( name ) => {
		const module = kit[ name ];

		if (
			module &&
			typeof module === 'object' &&
			typeof module.destroy === 'function'
		) {
			module.destroy( root );
		}
	} );
}
