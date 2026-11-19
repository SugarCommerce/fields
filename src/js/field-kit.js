/**
 * SugarCommerce Fields: every field type's control.
 *
 * Registered as `{prefix}` (Assets::register()), loaded wherever a field
 * set is drawn -- a settings page, a metabox, a term or profile screen,
 * quick and bulk edit, a panel, a public form -- and on a public form with
 * nothing of wp-admin's. Publishes `window.SugarCommerceFieldsModules`
 * while it runs (lib/kit.js), then starts every module on the document
 * when it is ready and once more when the page has loaded.
 *
 * Two conventions run through it.
 *
 * Configuration is resolved per build, from the id WordPress stamps on the
 * script element, not from a bare global (lib/config.js).
 *
 * Every control that can be dragged can also be operated from the keyboard.
 * That is not a fallback for when the script fails — a drag-only control
 * cannot be reordered without a mouse at all, and it is the half these
 * widgets usually ship without.
 */

import domReady from '@wordpress/dom-ready';

import Accordion from './fields/accordion';
import ActionButton from './fields/action-button';
import Address from './fields/address';
import Clipboard from './fields/clipboard';
import CodeEditor from './fields/code-editor';
import CodeGenerator from './fields/code-generator';
import ColorField from './fields/color-field';
import ColorPicker from './fields/color-picker';
import Combobox from './fields/combobox';
import Conditions from './fields/conditions';
import Counter from './fields/counter';
import EmailPanel from './fields/email-panel';
import Embed from './fields/embed';
import Gallery from './fields/gallery';
import IconPicker from './fields/icon-picker';
import Location from './fields/location';
import Media from './fields/media';
import Money from './fields/money';
import PanelTabs from './fields/panel-tabs';
import PasswordRemove from './fields/password-remove';
import PasswordReveal from './fields/password-reveal';
import Range from './fields/range';
import Region from './fields/region';
import Reorder from './fields/reorder';
import Repeater from './fields/repeater';
import SettingsBar from './fields/settings-bar';
import Slug from './fields/slug';
import TagModal from './fields/tag-modal';
import Tags from './fields/tags';
import Toggle from './fields/toggle';
import Tooltip from './fields/tooltip';
import Wysiwyg from './fields/wysiwyg';
import FrontForm from './forms/front-form';
import Guard from './forms/guard';
import { config, icon, t } from './lib/config';
import { announce, bound, sibling, swapIcon } from './lib/dom';
import { kit } from './lib/kit';
import { destroy, init } from './lib/lifecycle';
import { request } from './lib/request';

// In the order they have always been published: init() starts them in an
// order of its own (lib/lifecycle.js), and destroy() asks them in this one.
Object.assign( kit, {
	Conditions,
	Range,
	Toggle,
	Clipboard,
	CodeGenerator,
	announce,
	bound,
	sibling,
	t,
	icon,
	swapIcon,
	config,
	request,
	Combobox,
	Reorder,
	CodeEditor,
	Wysiwyg,
	ColorPicker,
	ColorField,
	IconPicker,
	PanelTabs,
	TagModal,
	EmailPanel,
	Tooltip,
	ActionButton,
	Repeater,
	Media,
	Gallery,
	Tags,
	Money,
	Region,
	PasswordReveal,
	PasswordRemove,
	Counter,
	Accordion,
	SettingsBar,
	Slug,
	Location,
	Embed,
	Address,
	Guard,
	FrontForm,
	init,
	destroy,
} );

window.SugarCommerceFieldsModules = kit;

domReady( () => init( document ) );

// Run once more when everything has loaded. Each module marks what it has
// bound, so this rebinds nothing — it only picks up a control whose
// library was not there the first time. Belt and braces: the failure mode
// when a jQuery plugin has not loaded yet is that the field silently
// stays a plain input, with nothing in the console to explain it.
window.addEventListener( 'load', () => init( document ) );
