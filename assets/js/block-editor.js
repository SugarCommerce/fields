/**
 * SugarCommerce Fields: the block editor's sidebar.
 *
 * Draws a metabox's fields in the document sidebar, with the editor's own
 * controls (block-editor/panel.js). Registered as `{prefix}-block-editor`
 * (Screens\BlockEditorPanel::enqueue()), on the block editor for a post
 * type whose metabox asked for it.
 *
 * The fields come from PHP, resolved and translated. They are found the way
 * the kit's own script finds its configuration -- by the id WordPress stamps
 * on this script's element -- so a second plugin bundling a prefixed copy of
 * the library loads its own copy with its own fields.
 */

import { documentPanel, register } from './block-editor/panel';
import { own } from './lib/config';

const config = own() || {};
const Within = documentPanel();

if ( Within && Array.isArray( config.panels ) ) {
	register( config.panels, Within );
}
