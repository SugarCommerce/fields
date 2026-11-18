# SugarCommerce Fields

Render, sanitise and store an admin form field — once, so nothing has to
render one again.

## What it does

Every library that puts a field on a screen ends up writing the same three
things: the markup, the sanitising, and the reading and writing of wherever
the value lives. Do it five times and you have five subtly different text
inputs, four of which forget `esc_attr` somewhere.

This is the one implementation. A field describes itself; the engine renders
it, coerces what comes back by type, and reads and writes it through a
context — post meta, term meta, an option, an array — so the same declaration
works wherever the screen happens to be. The screens WordPress already has
for it — a post's metabox, a term's form, a profile, quick edit and bulk
edit — are here too, each one array away.

## Features

* Render any of the field types below, escaped, with a label and description
* Sanitise on save by type, rather than a callback per field
* Put fields on a post, a term, a user profile, quick edit or bulk edit with one array
* Store to post meta, term meta, user meta, an option or a plain array
* Show a field only when another has a given value
* Search posts, users or terms from one endpoint, whatever the field type
* Refuse a value that fails a rule — required, email, url, your own callable — and say why
* Encrypt a value at rest, for an API key
* Pick a colour with the browser's own picker, paste a hex beside it, or choose a preset
* Pick an icon from Heroicons, WordPress's own, Dashicons or a theme's set in a searchable dialog

## Installation

```bash
composer require sugarcommerce/fields
```

## Quick start

```php
use SugarCommerce\Fields\FieldSet;
use SugarCommerce\Fields\Context\OptionContext;

$set = new FieldSet(
	[
		'site_name' => [ 'type' => 'text', 'label' => __( 'Site name', 'my-plugin' ) ],
		'per_page'  => [ 'type' => 'number', 'label' => __( 'Per page', 'my-plugin' ), 'min' => 1 ],
	],
	new OptionContext( 'my_plugin' ),
	'my_plugin'
);

echo $set->render();

// Each value is coerced by its own type; a key you did not declare is dropped.
$set->save( $_POST['my_plugin'] ?? [] );
```

## Field types

Text and numeric
: `text` `email` `url` `tel` `password` `hidden` `number` `percentage` `range` `textarea` `code` `wysiwyg` `code_generator`

Date, time and colour
: `date` `time` `datetime` `color` `gradient` `date_range` `time_range`

Choice
: `select` `enhanced_select` `select_multiple` `checkbox` `toggle` `radio` `checkbox_group` `button_group` `card_choice` `country` `region` `currency`

Relational — one search endpoint behind all of them
: `post` `page` `user` `taxonomy` `ajax` `tags`

Media
: `image` `file` `file_url` `gallery` `files`

Compound
: `group` `repeater` `list` `key_value` `sortable` `providers` `dimensions` `amount_type` `money` `flexible`

Layout and display
: `heading` `separator` `message` `html` `link` `clipboard` `oembed` `custom` `tab` `accordion` `icon` `nav_menu`

Purpose-built
: `license` `email_editor` `action_button`

## Beyond the quick start

Sanitising coerces; it does not refuse. `required` and `validate` do the
refusing on the server: a value that fails is left where it was, every other
field is saved, and the message is kept for the next render. Options can name
a preset instead of listing choices. Any single-line input takes a `prefix`
or a `suffix`, a password can offer a reveal, and a `maxlength` shows a count. A
`region` follows the country beside it, starts again when the country changes,
and with `'searchable' => true` has a search box over its list.

A password is never shown back, so its box saved empty keeps what is stored.
Beside a box with something stored there is a Remove button: it marks the
field, the mark is submitted with the form as `{prefix}[__clear][{key}]`
(`FieldSet::CLEAR_KEY`), and `save()` deletes the value. A typed value wins
over the mark, a required secret has no button, and `'removable' => false`
leaves it off -- which is right for a surface that sanitises a submission
into an empty store and saves it itself, where the mark has nothing to act on.

```php
$set = new FieldSet(
	[
		'contact'  => [ 'type' => 'email', 'label' => __( 'Contact', 'my-plugin' ), 'required' => true ],
		'notify'   => [ 'type' => 'tags', 'label' => __( 'Notify', 'my-plugin' ), 'validate' => 'email' ],
		'api_key'  => [
			'type'     => 'password',
			'label'    => __( 'API key', 'my-plugin' ),
			'reveal'   => true,
			'validate' => static fn( $value ) => str_starts_with( $value, 'sk_' ) ? true : __( 'An API key starts with sk_.', 'my-plugin' ),
		],
		'role'     => [ 'type' => 'select', 'label' => __( 'Notify role', 'my-plugin' ), 'options' => 'roles' ],
		'price'    => [ 'type' => 'number', 'label' => __( 'Price', 'my-plugin' ), 'prefix' => '$', 'step' => 0.01 ],
		'discount' => [ 'type' => 'percentage', 'label' => __( 'Discount', 'my-plugin' ) ],
		'tagline'  => [ 'type' => 'text', 'label' => __( 'Tagline', 'my-plugin' ), 'maxlength' => 80 ],
		'country'  => [ 'type' => 'country', 'label' => __( 'Country', 'my-plugin' ), 'continents' => true ],
		'region'   => [ 'type' => 'region', 'label' => __( 'State or province', 'my-plugin' ), 'country_key' => 'country' ],
		'currency' => [ 'type' => 'currency', 'label' => __( 'Currency', 'my-plugin' ) ],
	],
	new OptionContext( 'my_plugin' ),
	'my_plugin'
);

$set->save( $_POST['my_plugin'] ?? [] );

// Rendered after a save in the same request, each failing field is marked
// and shows its message; errors() has them for a notice of your own.
echo $set->render();
```

Rules are `email`, `url`, `numeric`, `integer`, `slug` and `alphanumeric`; a
callable returns `true`, a message or a `WP_Error`; on `tags` and `list` the
rule applies to each item; `validate()` checks without storing. Presets are
`roles`, `post_types`, `taxonomies`, `pages`, `timezones`, `image_sizes`,
`page_templates`, `cron_schedules`, `countries` and `currencies`, resolved on
render and filtered by `sugarcommerce_fields_preset_options` (prefixed in a
Strauss build, `Runtime::hook( 'preset_options' )`), with
`Presets::register()` for your own. Countries and regions come from
`sugarcommerce/geo`, currencies from `sugarcommerce/money` when it is
installed; a region is a select where the country has subdivisions on file and
a text input where it does not.

## Posts, terms, users, quick edit and bulk edit

The same field array goes on any of WordPress's own editing screens. Each
screen registers its meta keys (so REST and the block editor know them,
`show_in_rest` off unless a field asks), checks its nonce and capability, saves
each value through its type, and reads it back with decryption and defaults
applied.

```php
use SugarCommerce\Fields\Screens\PostFields;
use SugarCommerce\Fields\Screens\TermFields;
use SugarCommerce\Fields\Screens\UserFields;

add_action( 'init', function () {
	PostFields::register( 'product_info', [
		'title'      => __( 'Product information', 'my-plugin' ),
		'post_types' => 'product',
		'fields'     => [
			'sku'   => [ 'type' => 'text', 'label' => __( 'SKU', 'my-plugin' ) ],
			'price' => [ 'type' => 'number', 'label' => __( 'Price', 'my-plugin' ), 'min' => 0, 'step' => 0.01 ],
		],
	] );

	TermFields::register( 'download_category', [
		'_sale_amount' => [
			'type'          => 'amount_type',
			'label'         => __( 'Sale discount', 'my-plugin' ),
			'type_meta_key' => '_sale_type',
			'type_options'  => [ 'percent' => '%', 'flat' => '$' ],
		],
	] );

	UserFields::register(
		[ 'tax_exempt' => [ 'type' => 'toggle', 'label' => __( 'Tax exempt', 'my-plugin' ), 'capability' => 'manage_options' ] ],
		__( 'Tax', 'my-plugin' )
	);
} );

$sku    = PostFields::value( $post_id, 'sku' );
$amount = TermFields::value( $term_id, '_sale_amount', 0 );
$exempt = UserFields::value( $user_id, 'tax_exempt', false );
```

`QuickEditFields::register( $post_types, $fields )` and
`BulkEditFields::register( $post_types, $fields )` put the inline types on a
list table's quick and bulk edit panels. Bulk edit leaves a field alone unless
it is changed — an empty box or a "No change" choice writes nothing.

A metabox takes `prefix` (applied to every meta key), `capability` (per field
too), `context`, `priority` and `panels` for tabs; a term or quick edit field
takes a `permission_callback`; a user field takes `own_capability` for when
someone edits their own profile and `show_on_add` to keep it off the add-user
screen. A value a rule refuses is not stored, and the post, term or profile
screen says why when it reloads.
## Encrypted fields

`'encrypted' => true` on any field -- a string, a number, a group, a repeater --
stores it encrypted, in an option or in post, term or user meta. The value is
written as `fkenc:j:` and the payload of `sugarcommerce/security`'s
`Encryption\Cipher` (AES-256-GCM, so a value altered in the database does not
decrypt); the key is the site's, `WP_ENCRYPTION_KEY` or one made from the salts
in `wp-config.php`. Encryption is the security package's: this one keeps only
the `fkenc:` marker. A value that will not decrypt reads as empty and is never
deleted by a blank save, and a field marked encrypted where encryption is not
possible refuses to save rather than store plaintext.

Values written before the move, under the key this package used to make from
`LOGGED_IN_KEY`, `LOGGED_IN_SALT`, `AUTH_KEY` and `SECURE_AUTH_KEY`, still
read exactly as they did (`tests/fixtures/fkenc` holds some, made by the old
code); saving one writes it under the site's key.

## Colour

The `color` field is the browser's own colour input -- the operating
system's picker, eyedropper and all -- drawn as a chip inside a box holding
the hex. Either moves the other: pick a colour and the hex follows, paste
`#E8A5C4` or `e8a5c4` and the chip follows. Clear empties both. `presets`
adds a row of swatches, as a list or with names:

```php
'tint' => [
	'type'    => 'color',
	'label'   => __( 'Colour', 'my-plugin' ),
	'presets' => [ '#e8a5c4' => __( 'Rose', 'my-plugin' ), '#3858e9' => __( 'Blue', 'my-plugin' ), '#1e1e1e' ],
],
```

The hex box is the field: it has the name and the label, so the form works
with the script off. What is stored is `#rrggbb`, lowercase, or empty --
`Color::hex()` is the rule, and any form of a hex is read. `'picker' =>
'wordpress'` gives core's Iris picker instead, for its look or its alpha
channel; `alpha` alone implies it, and only then is an `rgba()` kept. Only a
field that asks for Iris loads it.

## Icons

The `icon` field is a button showing the chosen icon and its name. It opens
a dialog -- search box, a tab per set, a grid of icons -- that asks the
`/icons` endpoint for a page at a time, so a set of thousands costs nothing
until somebody opens it. Arrow keys move through the grid, Enter chooses,
Escape closes (and only the dialog, inside a flyout panel), and focus goes
back to the button. `sets` limits the field to some of them:

```php
'badge' => [
	'type'        => 'icon',
	'label'       => __( 'Badge', 'my-plugin' ),
	'sets'        => [ 'heroicons-outline', 'heroicons-solid' ],
	'placeholder' => __( 'No badge', 'my-plugin' ),
],
```

An icon is stored as `set:name` -- `heroicons-outline:shopping-cart`,
`dashicons:cart` -- at most 48 characters for any built-in, and is held to
the registry when saved: an unknown set or name, or a set the field does not
offer, is stored as empty. The old form, a whole Dashicons class
(`dashicons-cart`), is read as `dashicons:cart`.

Drawing one, anywhere -- a template, an email, a REST response:

```php
use SugarCommerce\Fields\Icons\Icons;

echo Icons::svg( $value, [ 'size' => 24, 'class' => 'shop-icon' ] ); // aria-hidden, currentColor
echo Icons::svg( $value, [ 'label' => __( 'Basket', 'my-plugin' ) ] );  // role="img" with a name
Icons::exists( $value );    // a real icon?
Icons::label( $value );     // "Shopping cart"
Icons::sanitize( $value );  // `set:name`, or ''
```

### Taking an icon in a library

A library whose arguments take an `icon` -- a report tile, a table's empty
state, a flyout's menu item -- draws it with `Icons::render()`, which reads
both forms a caller may pass:

```php
echo Icons::render( 'heroicons-outline:chart-bar', [ 'class' => 'my-tile__icon' ] );
// <svg width="20" height="20" class="sugarcommerce-icon my-tile__icon" aria-hidden="true" …>

echo Icons::render( 'chart-bar', [ 'class' => 'my-tile__icon' ] ); // or 'dashicons-chart-bar'
// <span class="dashicons dashicons-chart-bar sugarcommerce-icon my-tile__icon" aria-hidden="true"></span>
```

A `set:name` value is inline SVG in `currentColor`; a bare Dashicons name, the
form libraries took before there were sets, is the font's span exactly as it
was (with the `dashicons` style enqueued). Either way the element has the
`sugarcommerce-icon` class (`Icons::ICON_CLASS`, the same in every prefixed
copy), so one rule sizes both:

```css
.my-tile__icon.sugarcommerce-icon { width: 24px; height: 24px; font-size: 24px; }
```

An SVG with no `size` is 20 by 20 -- a Dashicon's box -- set as attributes, so
the stylesheet still wins. `label` makes it `role="img"` with that name;
otherwise it is `aria-hidden`. A value that is neither form, or names an icon
no set has, draws nothing. `Icons::is_set_value()` tells the two forms apart,
for a library that also accepts a URL or an emoji.

### The engine's own icons

The controls the engine draws itself -- a tooltip's "?", the "×" that clears a
value or closes a dialog, a drag handle, a row's move and bin buttons, the
password eye, a licence's state, a link field's mark, a gallery file's kind --
are Heroicons outline, named once in `Glyphs::DEFAULTS` and drawn through
`Icons::render()`, so the stylesheet does not depend on Dashicons. The script
is handed every glyph already drawn (`Glyphs::markup()`), so no path is
written twice.

```php
use SugarCommerce\Fields\Icons\Glyphs;

echo Glyphs::get( 'close' );                  // heroicons-outline:x-mark, 20 by 20
echo Glyphs::get( 'drag', [ 'size' => 16 ] ); // any Icons::render() argument
```

### The sets that ship

| Set id | What | Version | Licence | Icons |
| --- | --- | --- | --- | --- |
| `heroicons-outline` | Heroicons, 24px outline | 2.2.0 | MIT, Tailwind Labs | 324 |
| `heroicons-solid` | Heroicons, 24px solid | 2.2.0 | MIT, Tailwind Labs | 324 |
| `wordpress` | The block editor's `@wordpress/icons` | 20.0.0 | GPL-2.0-or-later | 344 |
| `dashicons` | Dashicons, read from core's own `wp-includes/fonts/dashicons.svg` | core's | GPL-2.0-or-later | 342 |

Heroicons come from the v2.2.0 release with its MIT licence beside them in
`assets/icons/heroicons/LICENSE`; the WordPress icons from the
`@wordpress/icons` 20.0.0 package with Gutenberg's licence in
`assets/icons/wordpress/LICENSE.md`. Every file was written through the same
cleaning an icon gets before it is printed, by `bin/build-icons.php`, which is
also how a new release is brought in. Dashicons are not copied: a Dashicon is
read from core's sprite as SVG, so it draws on a storefront with no icon font
loaded.

### Registering a set

From a theme, or any code that does not know which plugin's copy it is
talking to, use the filter. Every copy reads it, so the set appears in every
plugin's picker:

```php
add_filter( 'sugarcommerce_icon_sets', function ( array $sets ): array {
	$sets['my-theme'] = [
		'label'    => __( 'My theme', 'my-theme' ),
		'source'   => get_theme_file_path( 'icons' ), // one .svg per icon, named for it
		'license'  => 'CC BY 4.0 · Jane Doe',
		'style'    => 'outline',
		'keywords' => [ 'basket' => [ 'cart', 'bag', 'shop' ] ],
	];

	return $sets;
} );
```

From a plugin that has the class, `Icons::register( 'my-set', [ ... ] )`
does the same for its own copy, and `Icons::unregister( 'dashicons' )` takes
one away. `source` may be a callable returning `name => svg markup`;
`labels` names icons whose file names do not read well; `capability`
(default `edit_posts`) is what browsing the set over REST needs; `version`
should change when icons are edited in place. An icon font with no SVGs is a
set too: give `prefix` (`'fa fa-'`), `names` and the `stylesheet` handle.

Every icon is somebody else's markup printed into an admin screen, so
`Svg::sanitize()` parses it as XML (no network, no entities, no DOCTYPE) and
rebuilds it from an allow-list of shapes and presentation attributes: scripts,
event handlers, `<style>`, `<foreignObject>`, links, images, animation and
text are dropped, and a `href` or `url()` may only point inside the icon.

`GET /wp-json/sugarcommerce-fields/v1/icons?set=heroicons-outline&search=cart&page=1&per_page=96`
(the namespace is this build's: a Strauss-prefixed copy has its own) needs a
login and the set's capability, answers `{ set, total, page, pages, icons }`,
best match first, with an ETag and `Cache-Control: private, max-age=86400`.

## Moving from arraypress/wp-*

`arraypress/wp-field-kit` is this package, and `arraypress/wp-register-fields`
— the post, term, user, quick edit and bulk edit screens built on it — is
`SugarCommerce\Fields\Screens`. There is one field engine; the screens add no
types, renderers or sanitisers of their own. `ArrayPress\FieldKit\X` is now
`SugarCommerce\Fields\X`, `ArrayPress\RegisterFields\X` is
`SugarCommerce\Fields\Screens\X`, and every class, method, field type,
configuration key and stored value keeps its name. Text domain `arraypress` is
now `sugarcommerce`.

| Old | New |
|---|---|
| `ArrayPress\FieldKit\*` | `SugarCommerce\Fields\*` |
| `ArrayPress\RegisterFields\{PostFields,TermFields,UserFields,QuickEditFields,BulkEditFields,Errors}` | `SugarCommerce\Fields\Screens\*` |
| `register_post_fields( $id, $config )` | `PostFields::register( $id, $config )` |
| `get_post_field_value( $post_id, $key, $metabox_id )` | `PostFields::value( $post_id, $key, $metabox_id )` |
| `get_post_fields( $metabox_id )` | `PostFields::get_metabox_fields( $metabox_id )` |
| `get_post_field_config( $metabox_id, $key )` | `PostFields::get_field_config( $metabox_id, $key )` |
| `get_all_post_field_groups()` | `PostFields::get_all_metaboxes()` |
| `register_term_fields( $taxonomies, $fields )` | `TermFields::register( $taxonomies, $fields )` |
| `get_term_field_value( $term_id, $key, $fallback )` | `TermFields::value( $term_id, $key, $fallback )` |
| `get_term_fields( $taxonomy )` | `TermFields::get_instance( $taxonomy )` |
| `register_user_fields( $fields, $title )` | `UserFields::register( $fields, $title )` |
| `get_user_field_value( $user_id, $key, $fallback )` | `UserFields::value( $user_id, $key, $fallback )` |
| `register_quick_edit_fields( $post_types, $fields )` | `QuickEditFields::register( $post_types, $fields )` (returns the instances) |
| `register_bulk_edit_fields( $post_types, $fields )` | `BulkEditFields::register( $post_types, $fields )` (returns the instances) |
| `arraypress/wp-countries`, `arraypress/wp-money` | `sugarcommerce/geo` (required), `sugarcommerce/money` (suggested) |
| filter `arraypress_icon_sets` | `sugarcommerce_icon_sets` (shared by every copy, as before) |
| class `arraypress-icon` (`Icons::ICON_CLASS`) | `sugarcommerce-icon` |
| filter `field_kit_preset_options` | `sugarcommerce_fields_preset_options` (`Runtime::hook( 'preset_options' )`) |
| REST `field-kit/v1/search`, `/action`, `/icons` | `sugarcommerce-fields/v1/…` (`Runtime::rest_namespace()`) |
| script and style handle `field-kit` | `sugarcommerce-fields` (`Runtime::handle()`); prefixed builds `{prefix}-sugarcommerce-fields` |
| transient and key prefix `field_kit_` (save errors) | `sugarcommerce_fields_` (`Runtime::key()`) |
| REST error codes `field_kit_forbidden`, `_unknown_source`, `_unknown_action`, `_unknown_icon_set` | `sugarcommerce_fields_…` |
| `window.ArrayPressFieldKit`, `window.ArrayPressFieldKitModules` | `window.SugarCommerceFields`, `window.SugarCommerceFieldsModules` |
| `EncryptedContext`'s own AES-256-GCM and key | `sugarcommerce/security`'s `Encryption\Cipher` (required); same `fkenc:` values, new ones under the site key |
| `vendor/arraypress/wp-field-kit/bin/verify-docs.php` | `vendor/sugarcommerce/fields/bin/verify-docs.php`; `--calls=` also takes `PostFields::register` and the like |

Kept as they were, because renaming them would only churn every stylesheet
and script that targets them: the `field-kit__*` CSS classes, the
`assets/css/field-kit.css` and `assets/js/field-kit.js` file names, the quick
and bulk edit column `field-kit-inline`, and bulk edit's `__field_kit_no_change__`
and `__field_kit_clear__` sentinels. A site that filtered the old names, or
targeted the old icon class, needs the new one.

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later

The script's REST requests (searches, icon lists, oEmbed previews, action
buttons, regions) go through `wp.apiFetch`, a dependency of the script, so a
screen left open past its nonce's life renews the nonce and tries again
instead of answering 403.

## License

GPL-2.0-or-later
