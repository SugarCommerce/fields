<?php
/**
 * Email Editor Field Type
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Types;

use SugarCommerce\Fields\Attributes;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\Utils\Runtime;
use SugarCommerce\Fields\Contracts\AdminOnly;

/**
 * One email a plugin sends, as a collapsible panel.
 *
 * A plugin rarely has one email. It has a handful — a receipt, a renewal
 * notice, an admin alert — each with a recipient, a subject, a heading and a
 * body, and each wanting to be read and edited on its own without the others
 * in the way. A row in a settings table is the wrong shape for that: it is
 * built for one control beside one label, and an email is four controls, a
 * tag reference and two actions.
 *
 * So it renders as a `postbox` — core's own panel, the shape every metabox on
 * every edit screen uses, collapsible from its header. That is why it spans
 * the row rather than sitting in a table cell, and why it labels itself: the
 * panel heading is the field's name, and a legend repeating it would announce
 * the email twice.
 *
 * A screen with several of them is a list of emails first and an editor
 * second, so the header can say more than a name. `summary` is a line under
 * the name — when the email is sent — and is there whether the panel is
 * open or folded, which is what lets a column of folded panels be read.
 * `status` is a pill beside the name: either a fixed `label` and `tone`, or
 * `[ 'field' => 'enabled', 'on' => 'On', 'off' => 'Off' ]`, which follows
 * one of the panel's own switches and changes with it before anything is
 * saved. `meta` is a quieter word after it: who the email goes to.
 *
 * Which parts appear is configuration. `recipient` and `heading` are off by
 * default because not every email has them — an admin notice has a fixed
 * recipient, a plain-text one has no heading — and an input for something the
 * sender ignores is worse than no input at all.
 */
final class EmailEditorType extends AbstractNestedType implements AdminOnly {

	/**
	 * Config defaults.
	 *
	 * @return array<string, mixed>
	 */
	#[\Override]
	public function defaults(): array {
		return [
			'recipient' => false,
			'heading'   => false,
			'collapsed' => false,
			'summary'   => '',
			'status'    => null,
			'meta'      => '',
			'tags'      => [],
			'fields'    => [],
		];
	}

	/**
	 * The parts this email is made of.
	 *
	 * Built from ordinary types rather than hand-rolled, so every part
	 * inherits the same labelling, sanitizing and associations as any other
	 * field. Overridable wholesale by passing `fields`, for an email that
	 * needs a part this does not know about.
	 *
	 * @param Field $field The field.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function parts( Field $field ): array {
		$configured = (array) $field->get( 'fields', [] );

		if ( [] !== $configured ) {
			return $configured;
		}

		$parts = [];

		if ( (bool) $field->get( 'recipient', false ) ) {
			$parts['recipient'] = [
				'type'        => 'text',
				'label'       => __( 'Recipient', 'sugarcommerce' ),
				'placeholder' => 'name@example.com',
				'class'       => 'regular-text',
				'description' => __( 'Separate several addresses with commas.', 'sugarcommerce' ),
			];
		}

		$parts['subject'] = [
			'type'        => 'text',
			'label'       => __( 'Subject', 'sugarcommerce' ),
			'class'       => 'large-text',
			'placeholder' => __( 'The subject line the recipient sees', 'sugarcommerce' ),
			'description' => __( 'Merge tags work here as well as in the body.', 'sugarcommerce' ),
		];

		if ( (bool) $field->get( 'heading', false ) ) {
			$parts['heading'] = [
				'type'        => 'text',
				'label'       => __( 'Heading', 'sugarcommerce' ),
				'class'       => 'large-text',
				'placeholder' => __( 'Shown above the body, if the template uses one', 'sugarcommerce' ),
				'description' => __( 'Left empty, the template falls back to whatever it uses by default.', 'sugarcommerce' ),
			];
		}

		$parts['body'] = [
			'type'        => 'wysiwyg',
			'label'       => __( 'Body', 'sugarcommerce' ),
			'description' => __( 'The email itself. Add Tag inserts a merge tag where you were last typing.', 'sugarcommerce' ),
			// The body is where a tag is actually wanted, and the editor puts
			// the chooser beside Add Media, where someone writing one is
			// already looking.
			'tags'        => $field->get( 'tags', [] ),
		];

		// A panel that starts folded has an editor that starts out of sight.
		if ( (bool) $field->get( 'collapsed', false ) ) {
			$parts['body']['height'] = 260;
		}

		return $parts;
	}

	/**
	 * A copy of the field carrying its resolved parts.
	 *
	 * The parts are what `sub_fields()` reads, and rendering and sanitizing
	 * must agree on them exactly or a part is drawn and never saved.
	 *
	 * @param Field $field The field.
	 *
	 * @return Field
	 */
	private function with_parts( Field $field ): Field {
		return $field->with_config( [ 'fields' => $this->parts( $field ) ] );
	}

	/**
	 * Render the panel.
	 *
	 * @param Field      $field      The field.
	 * @param Attributes $attributes Prepared attributes.
	 *
	 * @return string
	 */
	#[\Override]
	public function render( Field $field, Attributes $attributes ): string {
		$values    = is_array( $field->value() ) ? $field->value() : [];
		$title_id  = $field->input_id() . '__title';
		$collapsed = (bool) $field->get( 'collapsed', false );

		// Core's own accordion, the one the kit's collapsible sections use,
		// so the panel folds and looks like the sections beside it rather
		// than like a metabox that wandered in from an edit screen.
		$panel = new Attributes();
		$panel->add_class( 'privacy-settings-accordion', 'field-kit__accordion', 'field-kit__email' );

		// role="region" with a name is what core gives a metabox, and it is
		// what lets someone move between panels rather than tab through every
		// control in one. It is also what stands in for the label: there is
		// no single control here to point a <label for> at.
		$panel->set( 'role', 'region' );
		$panel->set( 'aria-labelledby', $title_id );

		// The renderer built the description association for a control this
		// field does not have, so the region takes it instead. Without this
		// the description sits below the panel referenced by nothing.
		$described = (string) $attributes->get( 'aria-describedby' );

		$panel->set_if( '' !== $described, 'aria-describedby', $described );

		$panel_id = $field->input_id() . '__panel';

		return sprintf(
			'<div%s>%s<div id="%s" class="field-kit__accordion-panel"%s>%s%s</div></div>',
			$panel->render(),
			$this->render_header( $field, $title_id, $panel_id, $collapsed, $values ),
			esc_attr( $panel_id ),
			$collapsed ? ' hidden' : '',
			$this->render_children( $this->with_parts( $field ), $values, $field->input_name() ),
			$this->render_actions( $field )
		);
	}

	/**
	 * The panel header: core's accordion trigger, chevron at the end.
	 *
	 * The kit's Accordion module binds it, as it does every trigger inside
	 * a .field-kit__accordion, so there is no toggle of this type's own.
	 *
	 * With nothing more to say than a name, the header is the name and
	 * nothing else, as it has always been.
	 *
	 * @param Field                $field     The field.
	 * @param string               $title_id  Id of the heading.
	 * @param string               $panel_id  Id of the panel the button controls.
	 * @param bool                 $collapsed Whether the panel starts closed.
	 * @param array<string, mixed> $values    What the panel's parts hold.
	 *
	 * @return string
	 */
	private function render_header( Field $field, string $title_id, string $panel_id, bool $collapsed, array $values = [] ): string {
		$name    = esc_html( '' !== $field->label() ? $field->label() : __( 'Email', 'sugarcommerce' ) );
		$status  = $this->render_status( $field, $values );
		$meta    = trim( (string) $field->get( 'meta', '' ) );
		$summary = trim( (string) $field->get( 'summary', '' ) );

		$title = $name;

		if ( '' !== $status || '' !== $meta || '' !== $summary ) {
			// Spans throughout: this is inside a <button>, which may hold
			// phrasing and nothing else.
			$title = sprintf(
				'<span class="field-kit__email-heading"><span class="field-kit__email-name">%s</span>%s%s</span>%s',
				$name,
				$status,
				'' === $meta ? '' : sprintf( '<span class="field-kit__email-meta">%s</span>', esc_html( $meta ) ),
				'' === $summary ? '' : sprintf( '<span class="field-kit__email-summary">%s</span>', esc_html( $summary ) )
			);
		}

		return sprintf(
			'<h4 class="privacy-settings-accordion-heading" id="%1$s">' .
			'<button aria-expanded="%3$s" class="privacy-settings-accordion-trigger" aria-controls="%4$s" type="button">' .
			'<span class="title">%2$s</span><span class="icon"></span></button></h4>',
			esc_attr( $title_id ),
			$title,
			$collapsed ? 'false' : 'true',
			esc_attr( $panel_id )
		);
	}

	/**
	 * The pill beside the name.
	 *
	 * Drawn with the settings cards' own class, so "On" beside an email and
	 * "Connected" beside a card are the same mark.
	 *
	 * One that follows a switch is drawn from what the switch holds now and
	 * carries both of its labels, so the script can change the word the
	 * moment the switch is thrown: a pill that still says On beside a
	 * switch that says off is worse than no pill.
	 *
	 * @param Field                $field  The field.
	 * @param array<string, mixed> $values What the panel's parts hold.
	 *
	 * @return string
	 */
	private function render_status( Field $field, array $values ): string {
		$status = $field->get( 'status' );

		if ( $status instanceof \Closure ) {
			$status = $status();
		}

		if ( is_string( $status ) ) {
			$status = [ 'label' => $status ];
		}

		if ( ! is_array( $status ) ) {
			return '';
		}

		$follows = sanitize_key( (string) ( $status['field'] ?? '' ) );

		if ( '' === $follows ) {
			$label = (string) ( $status['label'] ?? '' );
			$tone  = (string) ( $status['tone'] ?? '' );

			return '' === $label ? '' : sprintf(
				'<span class="field-kit__settings-card-status field-kit__settings-card-status--%s">%s</span>',
				esc_attr( in_array( $tone, [ 'success', 'warning', 'error', 'info', 'neutral' ], true ) ? $tone : 'neutral' ),
				esc_html( $label )
			);
		}

		// What the switch holds, or what it starts as when it has never
		// been saved: the same answer the switch itself is drawn from.
		$parts = $this->parts( $field );
		$held  = $values[ $follows ] ?? null;
		$held  = null === $held || '' === $held ? ( $parts[ $follows ]['default'] ?? null ) : $held;
		$is_on = in_array( $held, [ 1, '1', true, 'yes', 'on' ], true );
		$on    = (string) ( $status['on'] ?? __( 'On', 'sugarcommerce' ) );
		$off   = (string) ( $status['off'] ?? __( 'Off', 'sugarcommerce' ) );

		return sprintf(
			'<span class="field-kit__settings-card-status field-kit__settings-card-status--%1$s" data-follows="%2$s" data-on="%3$s" data-off="%4$s">%5$s</span>',
			$is_on ? 'success' : 'neutral',
			esc_attr( $follows ),
			esc_attr( $on ),
			esc_attr( $off ),
			esc_html( $is_on ? $on : $off )
		);
	}

	/**
	 * The preview and test-send buttons.
	 *
	 * @param Field $field The field.
	 *
	 * @return string
	 */
	private function render_actions( Field $field ): string {
		$names   = (array) $field->get( 'action_names', [] );
		$buttons = '';

		foreach (
			[
				'preview' => __( 'Preview', 'sugarcommerce' ),
				'test'    => __( 'Send a Test', 'sugarcommerce' ),
			] as $action => $label
		) {
			// A button for a handler nobody registered posts to a 404, which
			// looks exactly like a button that does nothing.
			if ( '' === (string) ( $names[ $action ] ?? '' ) ) {
				continue;
			}

			$button = new Attributes();
			$button->set( 'type', 'button' );
			$button->add_class( 'button', 'field-kit__email-action' );
			$button->set( 'data-action', (string) $names[ $action ] );
			$button->set( 'data-endpoint', rest_url( Runtime::rest_namespace() . '/action' ) );
			$button->set( 'data-nonce', wp_create_nonce( 'wp_rest' ) );
			$button->set( 'data-field', $field->key() );

			$buttons .= sprintf( '<button%s>%s</button>', $button->render(), esc_html( $label ) );
		}

		if ( '' === $buttons ) {
			return '';
		}

		// The preview region is here from the start, hidden. The script fills
		// it with whatever a handler returns as data.html and shows it; a
		// region it has to find cannot be one nothing rendered, which is
		// what left a Preview button reporting "done" with nothing to see.
		return sprintf(
			'<p class="field-kit__email-actions">%s<span class="spinner"></span>' .
			'<span class="field-kit__email-status" aria-live="polite"></span></p>' .
			'<div class="field-kit__action-preview" hidden></div>',
			$buttons
		);
	}

	/**
	 * Coerce a submitted value.
	 *
	 * @param mixed $value Raw submitted value.
	 * @param Field $field The field.
	 *
	 * @return array<string, mixed>
	 */
	#[\Override]
	public function sanitize( mixed $value, Field $field ): array {
		return $this->sanitize_children( $this->with_parts( $field ), $value );
	}

	/**
	 * The panel heading is the field's name.
	 *
	 * A label or a legend repeating it would announce the email twice.
	 *
	 * @return bool
	 */
	#[\Override]
	public function is_self_labelling(): bool {
		return true;
	}

	/**
	 * Not a fieldset: the panel is a labelled region already.
	 *
	 * @return bool
	 */
	#[\Override]
	public function is_grouped(): bool {
		return false;
	}

	/**
	 * A panel, not a control beside a label.
	 *
	 * @return bool
	 */
	#[\Override]
	public function spans_row(): bool {
		return true;
	}

	/**
	 * What it needs: the body is a visual editor with Add Media beside it.
	 *
	 * @return array{scripts: string[], styles: string[], editor: bool}
	 * @since 1.6.0
	 */
	#[\Override]
	public function dependencies(): array {
		return [
			'scripts' => [ 'media-upload', 'media-views' ],
			'styles'  => [],
			'editor'  => true,
		];
	}

	/**
	 * The configuration keys this type reads.
	 *
	 * @return string[]
	 */
	#[\Override]
	public function config_keys(): array {
		return array_merge(
			parent::config_keys(),
			[ 'action_names', 'collapsed', 'heading', 'meta', 'recipient', 'status', 'summary', 'tags' ]
		);
	}
}
