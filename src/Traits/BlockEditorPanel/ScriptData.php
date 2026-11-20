<?php
/**
 * Block Editor Panel: ScriptData
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.7.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Traits\BlockEditorPanel;

use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\Types\ColorType;
use SugarCommerce\Fields\Utils\Runtime;
use SugarCommerce\Plugin\Assets as PackageAssets;

/**
 * What the block editor's sidebar script is given: each field described as a control it can draw.
 *
 * Part of BlockEditorPanel: only the class uses it.
 */
trait ScriptData {

	/**
	 * Load the sidebar's script on the block editor for this metabox's post
	 * types, and hand it the fields.
	 *
	 * One script for every metabox in the build, each metabox adding its own
	 * entry to the build's registry before it runs -- under a handle derived
	 * from the namespace, so a second plugin bundling a prefixed copy of this
	 * library loads its own copy with its own fields.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! is_object( $screen ) || 'post' !== ( $screen->base ?? '' ) || ! self::on_screen() ) {
			return;
		}

		$post_type = (string) ( $screen->post_type ?? '' );

		if ( ! in_array( $post_type, (array) $this->config['post_types'], true ) ) {
			return;
		}

		$data = $this->data( $post_type );

		if ( [] === $data['fields'] ) {
			return;
		}

		$handle = Runtime::handle( 'block-editor' );

		if ( ! wp_script_is( $handle, 'registered' ) ) {
			// The build (assets/build/block-editor.js, from assets/js), whose
			// manifest names what it imports: wp-components, wp-data,
			// wp-editor, wp-element and wp-plugins. Every one a classic script
			// in WordPress 7.1: wp-plugins is not a script module, and
			// PluginDocumentSettingPanel is the editor's now -- edit-post's is
			// a deprecated copy that warns.
			PackageAssets::register_built_script( $handle, __FILE__, 'block-editor' );
		}

		wp_add_inline_script(
			$handle,
			sprintf(
				'window.SugarCommerceFields=window.SugarCommerceFields||{};'
				. '(window.SugarCommerceFields[%1$s]=window.SugarCommerceFields[%1$s]||{panels:[]}).panels.push(%2$s);',
				wp_json_encode( $handle ),
				wp_json_encode( $data )
			),
			'before'
		);

		wp_enqueue_script( $handle );
	}

	/**
	 * What the script is handed for one post type.
	 *
	 * Labels, help and options are resolved here, translated and with
	 * presets expanded, so the script has nothing to look up and nothing to
	 * translate. Values are not: they are the editor's, read from its store.
	 *
	 * @param string $post_type The post type being edited.
	 *
	 * @return array{plugin: string, name: string, title: string, fields: array<int, array<string, mixed>>}
	 */
	public function data( string $post_type ): array {
		$slug   = $this->slug( $this->id );
		$fields = [];

		// The same question the metabox asks of each field, though the
		// answer is settled: a sidebar field asks for nothing more.
		$keys = current_user_can( self::CAPABILITY ) ? $this->keys_for( $post_type ) : [];

		foreach ( $keys as $key ) {
			$config   = (array) $this->fields[ $key ];
			$resolved = $this->registry->get( (string) ( $config['type'] ?? 'text' ) );
			$field    = new Field( $key, $resolved, array_merge( $resolved->defaults(), $config ), null );

			$fields[] = $this->describe( $field, self::CONTROLS[ $resolved->id() ] ?? 'text' );
		}

		return [
			// registerPlugin() takes lower case, digits and hyphens, and two
			// builds must not register the same name.
			'plugin' => $this->slug( Runtime::handle( 'block-editor' ) ) . '-' . $slug,
			'name'   => $slug,
			'title'  => (string) $this->config['title'],
			'fields' => $fields,
		];
	}

	/**
	 * One field, as the script draws it.
	 *
	 * @param Field  $field   The field.
	 * @param string $control The control it becomes.
	 *
	 * @return array<string, mixed>
	 */
	private function describe( Field $field, string $control ): array {
		$entry = [
			'key'         => $field->key(),
			'control'     => $control,
			'label'       => $field->label(),
			// The sidebar's help is text; markup a description carries in a
			// metabox would arrive as visible tags.
			'help'        => wp_strip_all_tags( $field->description() ),
			'placeholder' => $field->placeholder(),
			'conditions'  => $this->conditions( $field->config() ),
		];

		switch ( $control ) {
			case 'number':
			case 'range':
				$schema = $field->type()->schema( $field );

				$entry['integer'] = 'integer' === ( $schema['type'] ?? '' );
				$entry['min']     = $field->get( 'min' );
				$entry['max']     = $field->get( 'max' );
				$entry['step']    = $field->get( 'step' );
				break;

			case 'select':
				$entry['options'] = $this->options( $field->options(), true );

				// The same empty option the metabox's select draws, and for
				// the same reason: without it the first option looks chosen.
				$entry['emptyLabel'] = false === $field->get( 'allow_empty', true )
					? null
					: (string) $field->get( 'empty_label', $field->placeholder() ?: __( '— Select —', 'sugarcommerce' ) );
				break;

			case 'radio':
			case 'button_group':
				$entry['options'] = $this->options( $field->options(), false );
				break;

			case 'checkbox':
			case 'toggle':
				$entry['checkboxLabel'] = (string) $field->get( 'checkbox_label', '' );
				break;

			case 'color':
				$entry['presets'] = [];

				foreach ( ColorType::presets( $field->get( 'presets', $field->get( 'palette', [] ) ) ) as $hex => $name ) {
					$entry['presets'][] = [
						'color' => (string) $hex,
						'name'  => '' === (string) $name ? (string) $hex : (string) $name,
					];
				}
				break;

			default:
				$entry['maxLength'] = (int) $field->get( 'maxlength', 0 );
		}

		return $entry;
	}

	/**
	 * Options as a list the script can keep in order.
	 *
	 * A JSON object is not a list: numeric keys come back sorted, so options
	 * keyed 10, 2, 1 would be drawn 1, 2, 10.
	 *
	 * @param array<int|string, mixed> $options Value => label, or a group's label => its options.
	 * @param bool                     $groups  Whether groups are kept, as a select's optgroups, or flattened.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function options( array $options, bool $groups ): array {
		$list = [];

		foreach ( $options as $value => $label ) {
			if ( is_array( $label ) ) {
				$inner = $this->options( $label, false );

				if ( $groups ) {
					$list[] = [
						'label'   => (string) $value,
						'options' => $inner,
					];
				} else {
					$list = array_merge( $list, $inner );
				}

				continue;
			}

			$list[] = [
				'value' => (string) $value,
				'label' => (string) $label,
			];
		}

		return $list;
	}

	/**
	 * A name registerPlugin() and a panel will accept.
	 *
	 * @param string $name Any identifier.
	 *
	 * @return string
	 */
	private function slug( string $name ): string {
		$slug = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $name ) ), '-' );

		return preg_match( '/^[a-z]/', $slug ) ? $slug : 'fields-' . $slug;
	}
}
