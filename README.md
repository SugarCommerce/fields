# SugarCommerce Fields

Render, sanitise and store a form field — once, so nothing has to render
one again, in wp-admin or on the front of the site.

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
* Draw the same fields in a public form on a storefront, checked exactly as wp-admin checks them
* Take an address laid out the way its country writes one, with its postcode checked
* Give a rating in stars, keyboard first, halves if you like
* Warn before leaving a form with changes, and mark what changed -- where a form asks

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
: `text` `email` `url` `tel` `password` `hidden` `number` `percentage` `range` `textarea` `code` `wysiwyg` `code_generator` `slug` `dimension` `rating`

Date, time and colour
: `date` `time` `datetime` `color` `date_range` `time_range`

Choice
: `select` `enhanced_select` `select_multiple` `checkbox` `toggle` `radio` `checkbox_group` `button_group` `card_choice` `country` `region` `currency`

Places
: `address` `location`

Relational — one search endpoint behind all of them
: `post` `page` `user` `taxonomy` `ajax` `tags`

Media
: `image` `file` `file_url` `files` `embed` (also `video`)

Compound
: `group` `repeater` `list` `key_value` `sortable` `amount_type` `money`

Layout and display
: `heading` `separator` `message` `html` `clipboard` `custom` `tab` `accordion` `icon`

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

## Markup that arrives later

`$set->dependencies()` is everything the set's fields need on the page --
script and style handles, `code_editors` (a language per code field) and
`editor` (a visual editor is among them) -- fields inside groups and
repeaters included, and `Support\Dependencies::of( $configs )` answers the
same for configuration that is not a set. `( new Assets() )->enqueue( $needs )`
loads it. That matters to markup drawn after the page has loaded, a panel
fetched over REST, where nothing a field would enqueue while rendering
reaches the page.

Such markup is started with `window.SugarCommerceFieldsModules.init( root )`
and let go of with `window.SugarCommerceFieldsModules.destroy( root )` before
it is removed. A visual editor core did not start is started from core's own
defaults on the markup `wp_editor()` drew, and `destroy()` stops it, so the
same editor drawn again is a new editor rather than the old one's ghost.

`AmountType::posted_unit( $field, $input )` is the unit a submission chose,
when it is one the field offers, and `unit_key( $field )` where it goes: the
surface that stores the amount stores the unit beside it.

## Public forms

The same field arrays, on the front of the site: a checkout's address, a
product's options, an account's details, a review. `Form` draws them inside
a `<form>` with a nonce and a honeypot, checks a submission with the same
field set wp-admin uses -- each value sanitised by its type, `required`,
`validate`, a `maxlength`, conditions -- and hands the clean values to you.

```php
use SugarCommerce\Fields\Form;

add_action( 'init', function () {
	Form::register( 'order', [
		'email'   => [ 'type' => 'email', 'label' => __( 'Email', 'my-plugin' ), 'required' => true, 'autocomplete' => 'email' ],
		'address' => [ 'type' => 'address', 'label' => __( 'Delivery address', 'my-plugin' ), 'required' => true, 'autocomplete' => 'shipping' ],
		'terms'   => [ 'type' => 'checkbox', 'label' => __( 'I accept the terms', 'my-plugin' ), 'required' => true ],
	], [
		'submit'    => __( 'Place order', 'my-plugin' ),
		'rest'      => true,   // sent without leaving the page
		'guard'     => true,   // warn before leaving it with changes
		'redirect'  => home_url( '/thanks/' ),
		'on_submit' => function ( array $values, Form $form ) {
			$order = my_plugin_create_order( $values );

			return is_wp_error( $order ) ? $order : __( 'Thank you: your order is in.', 'my-plugin' );
		},
	] );
} );

// In a template, a block or a shortcode:
echo Form::get( 'order' )->render();
```

A public form is all or nothing. One refused field and nothing is sent or
stored: the refusals are listed at the top, each a link to its field
(`role="alert"`, focused), each field shows its own message
(`aria-invalid`, `aria-describedby`), and the form comes back with what was
typed. `on_submit` refuses with a `WP_Error`, whose data's `fields` may name
a message per field; a string it returns is the success message. With
`context` and `object_id` -- a `UserMetaContext` for an account page -- an
accepted submission is also saved there, exactly as a profile screen would
save it, and the form is drawn from what is stored.

`Form::register()` on `init` is what lets the form be handled before the page
is drawn (and redirected after, so a reload does not send it twice) and what
gives it its REST route, `POST /{namespace}/forms/{id}`, for `'rest' =>
true`: the script sends the form with `wp.apiFetch`, and the answer -- `ok`,
`message`, `errors` and the `ids` of the controls they are about, a 422 when
refused -- is drawn where the server would have drawn it. The route checks
the same nonce and `capability` as a post. A form only built with `new
Form()` inside a shortcode still handles a post sent to it, but cannot
redirect or be sent over REST. `$form->handle( $input )`, `validate()`,
`values()`, `errors()` and `result()` are there for a caller with its own
flow.

Other arguments: `success`, `action`, `class`, `capability` (empty for
anyone), `nonce` (`false` only for a page a cache serves to everybody, which a
nonce would break), `honeypot` (on; a robot that fills it is told it worked
and nothing happens).

The markup is the engine's -- labels, fieldsets and legends, descriptions --
and the stylesheet is `css/fields-front.css`, not wp-admin's: spacing, a
visible focus, an error colour and a box for each choice widget, every rule
inside `:where()` so a theme's rules win without a fight, and the colours as
custom properties on the form:

```css
.sugarcommerce-form { --fk-accent: #0a7d5a; --fk-error: #b00020; --fk-radius: 0; }
```

`render()` enqueues what the form needs; call `$form->enqueue()` on
`wp_enqueue_scripts` as well to have the stylesheet in the head.
`Assets::enqueue_front( $needs )` does the same for markup of your own.

Every type works on the front end except the ones that lean on wp-admin,
which say so with the `Contracts\AdminOnly` contract and are left off a
public form with a `_doing_it_wrong()` notice -- nested in a repeater too:
`image`, `file`, `file_url`, `files` (the media library), `code` and
`wysiwyg` and `email_editor` (core's editors), `post`, `page`, `user`,
`taxonomy`, `ajax` (searches that need a login), `icon`, `license` and
`action_button`. A `region` on a public form asks the open address route for
its list, so it works with nobody logged in.

## Address

One field for a postal address that is laid out the way its country writes
one. Country first, because it decides the rest: which boxes there are,
what they are called (a State and a ZIP code, a County and a Postcode, a
Prefecture), which of them an address there must have, whether the region is
chosen from a list or typed, which boxes share a line, and what a postcode
there looks like -- all of it `sugarcommerce/geo`'s `Addresses`,
`AddressFormats` and `Regions`, made from the data Google publishes for
Chrome's own address forms.

```php
'ship_to' => [
	'type'            => 'address',
	'label'           => __( 'Shipping address', 'my-plugin' ),
	'required'        => true,
	'default_country' => 'GB',
	'countries'       => [ 'GB', 'IE', 'FR', 'DE' ], // all of them when left out
	'parts'           => [ 'name', 'company' ],      // as well as the address
	'line2'           => true,
	'autocomplete'    => 'shipping',                 // shipping address-line1 and so on
],
```

Changing the country moves the boxes, renames them, marks the ones it
requires, swaps the region list and starts the region again; the page asks
`GET /{namespace}/address/{country}`, which is public and cached for a day,
so it works at a checkout. The postcode is checked against the country's
pattern as it is left and again on the server, and a refusal shows an
example ("ZIP code is not in the form used in United States, such as
95014."). A country geo has no pattern for is not refused.

Stored as one array, `line1`, `line2`, `city`, `state`, `postcode`, `country`
(and `name` and `company` where asked for), the shape `Addresses::format()`
takes. `state` is a region code where the country has a list; a postcode is
written the country's way (`ec1y 8sy` is `EC1Y 8SY`); a part the country does
not have is dropped, so a state typed for Germany is not kept. An address
that has been started has to be finished for its country, whether or not the
field is required; `required` makes an empty one a refusal too.

```php
AddressType::format( $address, true ); // [ '1 Main St', 'Austin, TX 78701', 'United States' ]
AddressType::layout( 'JP' );           // lines, labels, required parts, regions, postcode pattern
```

## Rating

Stars, from a group of radio buttons: Tab reaches the group, the arrow keys
move along it and choose, and a screen reader hears "4 out of 5". The strip
of stars is painted by the stylesheet alone, filled to the chosen value or
to the one under the pointer, so nothing waits for a script.

```php
'score'   => [ 'type' => 'rating', 'label' => __( 'Your rating', 'my-plugin' ), 'required' => true ],
'quality' => [ 'type' => 'rating', 'label' => __( 'Quality', 'my-plugin' ), 'max' => 10, 'half' => true ],
'average' => [ 'type' => 'rating', 'label' => __( 'Average', 'my-plugin' ), 'display' => true ], // read-only
```

`max` is one to ten (five by default), `half` allows halves, and `display`
(or `readonly`) draws the stars with nothing to change, keeping the value in
the form. What is stored is the number -- held between one step and `max`,
rounded to the step -- and no rating is stored as nothing, so `required`
means somebody chose. Anywhere else -- a product page, a review list:

```php
echo RatingType::stars( 4.3 );                                    // role="img", "4.3 out of 5 stars"
echo RatingType::stars( $average, 10, [ 'size' => 16, 'class' => 'review-stars' ] );
```

`--fk-rating-size`, `--fk-rating-fill` and `--fk-rating-empty` restyle them.

## Smaller types

**`dimension`** -- a number and its unit, stored together as `[ 'value' =>
2.5, 'unit' => 'kg' ]`. `units` is a list, a map of value to label, or a set:
`length` (px em rem % vw vh, the default), `size` (cm mm m in ft) or
`weight` (kg g lb oz); `unit_default`, `min`, `max`, `step`. One unit draws as
a suffix. `DimensionType::format( $value )` gives `12px` or `2.5 kg`.

**`slug`** -- cleaned as WordPress cleans a post name, as it is typed and
when it is saved. `source` names a field to make it from, which it follows
until somebody edits it; `prefix` shows where it sits (`example.com/shop/`).
Whether a slug is free is yours to say: `unique_callback` is given the slug
and returns true, false, or a free one to suggest. The field asks it as
somebody types, through `GET /{namespace}/lookup` (who may ask:
`lookup_capability`, `edit_posts` by default, empty for a public form), and
the save asks again. The slug the field already holds is never checked
against itself.

```php
'handle' => [
	'type'            => 'slug',
	'label'           => __( 'Address', 'my-plugin' ),
	'source'          => 'title',
	'prefix'          => home_url( '/shop/' ),
	'unique_callback' => fn( string $slug ) => my_plugin_slug_taken( $slug ) ? $slug . '-2' : true,
],
```

**`location`** -- an address, its latitude and longitude, and a map, stored
as `[ 'address', 'lat', 'lng' ]`. `geocoder` is a `sugarcommerce/geocoding`
Geocoder (anything with `locate()`), or a callable returning `[ 'lat', 'lng',
'address' ]` or a `WP_Error`; with one, the address has a Find button (and
Enter finds). Without, the numbers are typed. The map is OpenStreetMap's
embed -- an iframe with a marker that needs no key, no script and no account
-- and follows the numbers; allow `https://www.openstreetmap.org` in a
content security policy's `frame-src`, or set `map => false`. Each Find may
cost money, so `lookup_capability` is `edit_posts` unless a public form says
`''`.

```php
'store' => [ 'type' => 'location', 'label' => __( 'Shop', 'my-plugin' ), 'geocoder' => new MapsCo( $key ) ],
```

**`embed`** (alias `video`) -- a link to a video or a track and the player it
becomes, from `sugarcommerce/embeds`: exact hosts, a fresh `src` built from
the id, never the pasted link. The preview follows as somebody types (`GET
/{namespace}/embed`, public, no request made); a link no player can be made
from is refused on save, naming what would be taken. `platforms` limits it
(`[ 'youtube', 'vimeo' ]`), `preview => false` leaves the player off. The
package is suggested, not required: a plugin with an embed field requires
`sugarcommerce/embeds` itself; without it the field is a plain link.

**Character counts** -- every text input and textarea counts against its
`maxlength`, which is now refused on the server too ("Title can be at most 60
characters long."), not only stopped by the browser. `soft_limit` is a
length to aim for: counted against, warned past (`5 / 60 (up to 160)` with
both), never refused.

```php
'meta_description' => [ 'type' => 'textarea', 'label' => __( 'Meta description', 'my-plugin' ), 'soft_limit' => 155, 'maxlength' => 320 ],
```

A required `checkbox` or `toggle` now has to be ticked on the server as in
the browser: an unticked box is stored as 0, which is a value, and was let
through.

## Unsaved changes

Off unless a form asks. A guarded form notes what it would submit when it is
drawn; a field whose value is no longer that is marked -- a bar beside it and
"Changed" in its label -- and while any is, leaving the page asks first.
Changing a field back takes the mark away; sending the form ends it.

```php
// A form of your own:
printf( '<form method="post" %s>', esc_attr( Guard::ATTRIBUTE ) ); // data-fk-guard

// A public form:
Form::register( 'account', $fields, [ 'guard' => true ] );

// A form another library draws -- a settings page, a flyout panel:
Guard::enable( '.my-plugin-settings form' );
Guard::enable( '.wp-flyout form' );
```

In a flyouts panel, closing it is the way out inside the page, and the panel
already asks before it throws changes away; the guard adds the marks and the
warning on leaving the page, and starts again when the panel saves (it
listens for `wpflyout:dirty`). Code of your own about to remove a guarded
form asks `SugarCommerceFieldsModules.Guard.confirm( element )` first, and a
form fires `field-kit:dirty` with `{ dirty, count }` as it changes.

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
`SugarCommerce\Text\Display\Color::hex()` (from `sugarcommerce/text`, the
family's one colour helper) is the rule, and any form of a hex is read. `'picker' =>
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
password eye, a licence's state, a file's kind --
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
| `wordpress` | Core's own icon library, read from WP 7.1's Icons API (`WP_Icons_Registry`, the `core` collection) | core's | GPL-2.0-or-later | 88 |
| `dashicons` | Dashicons, read from core's own `wp-includes/fonts/dashicons.svg` | core's | GPL-2.0-or-later | 342 |

Heroicons come from the v2.2.0 release with its MIT licence beside them in
`assets/icons/heroicons/LICENSE`. Every file was written through the same
cleaning an icon gets before it is printed, by `bin/build-icons.php`, which is
also how a new release is brought in. Heroicons are not registered with core's
Icons API: its kses allow-list has no `stroke-*` attributes, so an outline icon
would lose its lines and fill solid.

Neither WordPress set is copied. The `wordpress` set is whatever core's
`core` icon collection holds (`CoreIcons::icons()`), as core sanitised it, drawn
in `currentColor` like the rest. A Dashicon is read from core's sprite as SVG,
so it draws on a storefront with no icon font loaded.

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
| `Assets`' own path and URL guessing, `new Assets( $path, $url )` | `SugarCommerce\Plugin\Assets` (required), the family's one asset resolver; `new Assets()` keeps the handles and configuration, and the `$path` and `$url` arguments are gone (nothing passed them) |
| field types `providers`, `flexible`, `gradient`, `dimensions`, `link`, `oembed`, `nav_menu`, `gallery` (and the JS modules `Providers`, `Gallery`, `Oembed`) | removed: nothing used them (SugarCart is Stripe-only, so no gateway list). Use `repeater` for rows, `files` for several attachments, `color`, `url`, `select` with your own options, or a `custom` field |
| `Glyphs` key `link` | removed with the `link` type |
| icon set `wordpress`: 344 bundled `@wordpress/icons` 20.0.0 files, `Icons::WORDPRESS_ICONS_VERSION` | core's own icon library (88 icons) from WP 7.1's Icons API. A stored `wordpress:name` outside core's set no longer validates or draws; `cart`, `star-filled` and the like are in it, drawn as core draws them |
| `SugarCommerce\Fields\Support\Color` (`hex`, `rgb`, `contrast`, `ratio`, `readable`) | `SugarCommerce\Text\Display\Color` (required), same methods and WCAG rule; `##fff` with two hashes is no longer read as a colour |
| `vendor/arraypress/wp-field-kit/bin/verify-docs.php` | `vendor/sugarcommerce/fields/bin/verify-docs.php`; `--calls=` also takes `PostFields::register` and the like |

Kept as they were, because renaming them would only churn every stylesheet
and script that targets them: the `field-kit__*` CSS classes, the
`assets/css/field-kit.css` and `assets/js/field-kit.js` file names, the quick
and bulk edit column `field-kit-inline`, and bulk edit's `__field_kit_no_change__`
and `__field_kit_clear__` sentinels. A site that filtered the old names, or
targeted the old icon class, needs the new one.

A Strauss prefix that itself starts `SugarCommerce\` keeps all its segments --
`SugarCommerce\Playground\Vendor\` gives
`sugarcommerce-playground-vendor-sugarcommerce-fields/v1` -- so a plugin of the
family is never taken for an unprefixed copy.

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later

The script's REST requests (searches, icon lists, action buttons, regions) go
through `wp.apiFetch`, a dependency of the script, so a screen left open past
its nonce's life renews the nonce and tries again instead of answering 403.

## License

GPL-2.0-or-later
