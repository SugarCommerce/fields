/**
 * One metabox's fields, as a panel in the block editor's document sidebar.
 */

import { useDispatch, useSelect } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { createElement as h } from '@wordpress/element';
import { getPlugin, registerPlugin } from '@wordpress/plugins';

import { shown } from './conditions';
import { control } from './controls';

/**
 * The editor's document panel.
 *
 * The editor's own since WordPress 6.6. Edit-post's is a deprecated copy
 * that warns, asked for only if the editor's is missing -- from the page,
 * because a script depending on edit-post for a fallback it never uses
 * would load it wherever this loads.
 *
 * @return {Function|undefined} The component, if there is one.
 */
export function documentPanel() {
	return (
		PluginDocumentSettingPanel ||
		( window.wp &&
			window.wp.editPost &&
			window.wp.editPost.PluginDocumentSettingPanel )
	);
}

/**
 * The panel.
 *
 * Reads and writes the post's meta through the editor's store. The editor
 * saves that meta over REST with the rest of the post, so there is no save
 * here: a change is an edit to the post, undone by the editor's undo and
 * marked unsaved like any other.
 *
 * @param {Object}   props        Its props.
 * @param {Object}   props.panel  The panel, as PHP described it.
 * @param {Function} props.Within The editor's document panel.
 * @return {Object} The panel.
 */
export function Panel( { panel, Within } ) {
	const meta = useSelect(
		( select ) =>
			select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {},
		[]
	);

	const { editPost } = useDispatch( 'core/editor' );

	// The editor merges a meta edit into the meta it holds, so one key
	// is all an edit has to carry.
	const set = ( key ) => ( value ) =>
		editPost( { meta: { [ key ]: value } } );

	return h(
		Within,
		{
			name: panel.name,
			title: panel.title,
			className: 'sugarcommerce-fields-panel',
		},
		h(
			'div',
			{ style: { display: 'grid', gap: '16px', minWidth: 0 } },
			panel.fields
				.filter( ( field ) => shown( field, meta ) )
				.map( ( field ) =>
					h(
						'div',
						{
							key: field.key,
							className: 'sugarcommerce-fields-panel__field',
							style: { minWidth: 0 },
						},
						control( field, meta[ field.key ], set( field.key ) )
					)
				)
		)
	);
}

/**
 * Register a plugin for each panel, under the name PHP gave it.
 *
 * A name already registered -- a second copy of the same build -- is left
 * as it is.
 *
 * @param {Array<Object>} panels The panels, as PHP described them.
 * @param {Function}      Within The editor's document panel.
 */
export function register( panels, Within ) {
	panels.forEach( ( panel ) => {
		if ( getPlugin( panel.plugin ) ) {
			return;
		}

		registerPlugin( panel.plugin, {
			render: () => h( Panel, { panel, Within } ),
		} );
	} );
}
