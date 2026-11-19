<?php
/**
 * Public Form
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.4.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields;

use SugarCommerce\Fields\Context\ArrayContext;
use SugarCommerce\Fields\Contracts\AdminOnly;
use SugarCommerce\Fields\Contracts\Context;
use SugarCommerce\Fields\Rest\FormController;
use SugarCommerce\Fields\Support\Guard;

/**
 * The same fields, on the front of the site.
 *
 * A product's options, a checkout's address, an account's details: the
 * field arrays a plugin already writes for wp-admin, drawn in a page of the
 * theme's. The markup is the engine's -- a label for every control, a
 * fieldset and legend for every group, a description and an error tied to
 * their control -- inside a `<form>` with a nonce and a honeypot, and the
 * stylesheet is a small one of its own that sets spacing, focus and the
 * error colour and leaves type and colour to the theme. Nothing of
 * wp-admin's is loaded.
 *
 * A submission is checked exactly as wp-admin checks one, because it is the
 * same field set doing it: each value sanitised by its type, `required` and
 * `validate` applied, conditions honoured. Unlike a settings page, a public
 * form is all or nothing -- one refused field and nothing is stored or
 * sent, every refusal is listed at the top with a link to its field, and
 * the form comes back with what was typed.
 *
 * The values go to `on_submit`, to the `context` (a user's meta for an
 * account form), or both. With `rest`, the form is sent without leaving the
 * page and the answer drawn into it; without a script it posts as any form
 * does. A type that only works in wp-admin -- media, a code editor, a search
 * over users -- is left off with a notice under WP_DEBUG.
 */
final class Form {

	/**
	 * The hidden input naming which form was sent.
	 */
	public const FIELD = '_sugarcommerce_form';

	/**
	 * The registered forms, by id.
	 *
	 * @var array<string, self>
	 */
	private static array $forms = [];

	/**
	 * The form's id: its element id, its input prefix, its nonce's name.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Field configuration, without the types that cannot be here.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $fields;

	/**
	 * Arguments.
	 *
	 * @var array<string, mixed>
	 */
	private array $args;

	/**
	 * The type registry.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * The last submission's outcome, once there has been one.
	 *
	 * @var array{ok: bool, values: array<string, mixed>, errors: array<string, string>, message: string}|null
	 */
	private ?array $result = null;

	/**
	 * Build a form.
	 *
	 * @param string                              $id     The form's id, unique on the page: letters, digits, `_` and `-`.
	 * @param array<string, array<string, mixed>> $fields Field configuration, as for a FieldSet.
	 * @param array<string, mixed>                $args   {
	 *     Optional.
	 *
	 *     @type callable $on_submit  Given the clean values and the form when
	 *                                every field passes. Return a WP_Error to
	 *                                refuse (its data's `fields` may name a
	 *                                message per field), a string for the
	 *                                success message, anything else to accept.
	 *     @type Context  $context    Where values are read from and saved to,
	 *                                once accepted. None: they are only given
	 *                                to on_submit.
	 *     @type int      $object_id  The object they belong to in the context.
	 *     @type string   $submit     The button's words.
	 *     @type string   $success    What to say once it is sent.
	 *     @type string   $action     Where the form posts. The page it is on.
	 *     @type string   $redirect   Where to go once it is sent, for a form
	 *                                registered before the page is drawn.
	 *     @type bool     $rest       Send it without leaving the page.
	 *     @type bool     $guard      Warn before leaving it with changes.
	 *     @type bool     $nonce      Check a nonce. Off only for a page a
	 *                                cache serves to everybody.
	 *     @type bool     $honeypot   A box only a robot fills. On.
	 *     @type string   $capability What sending it needs; empty for anyone.
	 *     @type string   $class      Classes for the form element.
	 * }
	 * @param Registry|null                       $registry The type registry.
	 */
	public function __construct( string $id, array $fields, array $args = [], ?Registry $registry = null ) {
		$this->id       = sanitize_key( $id );
		$this->registry = $registry ?? new Registry();
		$this->args     = $args + [
			'on_submit'  => null,
			'context'    => null,
			'object_id'  => 0,
			'submit'     => __( 'Submit', 'sugarcommerce' ),
			'success'    => __( 'Thank you. That has been sent.', 'sugarcommerce' ),
			'action'     => '',
			'redirect'   => '',
			'rest'       => false,
			'guard'      => false,
			'nonce'      => true,
			'honeypot'   => true,
			'capability' => '',
			'class'      => '',
		];
		$this->fields   = $this->public_fields( $fields );

		// Built once now, so a lookup a field asks for -- a slug's check, a
		// location's geocoder -- is registered on the request that asks it,
		// where the form is never drawn.
		$this->set( new ArrayContext() );
	}

	/**
	 * Build a form and keep it, so a submission is handled before the page
	 * is drawn and its REST route exists.
	 *
	 * Call on `init`. A form only built while the page is drawn -- inside a
	 * shortcode -- still handles what was sent to it, but too late to
	 * redirect, and cannot be sent over REST.
	 *
	 * @param string                              $id     The form's id.
	 * @param array<string, array<string, mixed>> $fields Field configuration.
	 * @param array<string, mixed>                $args   As for the constructor.
	 *
	 * @return self
	 */
	public static function register( string $id, array $fields, array $args = [] ): self {
		$form = new self( $id, $fields, $args );

		self::$forms[ $form->id() ] = $form;

		static $hooked = false;

		if ( ! $hooked ) {
			$hooked = true;

			add_action( 'template_redirect', [ self::class, 'handle_early' ] );
			( new FormController() )->boot();
		}

		return $form;
	}

	/**
	 * A registered form.
	 *
	 * @param string $id The form's id.
	 *
	 * @return self|null
	 */
	public static function get( string $id ): ?self {
		return self::$forms[ sanitize_key( $id ) ] ?? null;
	}

	/**
	 * Handle a registered form that was sent, before the page is drawn, and
	 * go on to `redirect` when it is accepted -- so reloading the page that
	 * follows does not send it again.
	 *
	 * @return void
	 */
	public static function handle_early(): void {
		foreach ( self::$forms as $form ) {
			if ( ! $form->submitted() ) {
				continue;
			}

			$result = $form->handle();

			if ( $result['ok'] && '' !== (string) $form->args['redirect'] ) {
				wp_safe_redirect( add_query_arg( $form->id(), 'sent', (string) $form->args['redirect'] ) );
				exit;
			}
		}
	}

	/**
	 * The form's id.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * The fields the form draws: every one given, less the admin-only.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		return $this->fields;
	}

	/**
	 * Whether this request is this form being sent.
	 *
	 * @return bool
	 */
	public function submitted(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only which form; handle() checks the nonce.
		$sent = isset( $_POST[ self::FIELD ] ) && is_string( $_POST[ self::FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::FIELD ] ) ) : '';

		return $sent === $this->id;
	}

	/**
	 * Check, sanitise and accept a submission.
	 *
	 * @param array<string, mixed>|null $input The submission, still slashed:
	 *                                         `$_POST` when null. The fields'
	 *                                         values are under the form's id.
	 *
	 * @return array{ok: bool, values: array<string, mixed>, errors: array<string, string>, message: string}
	 */
	public function handle( ?array $input = null ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified below, before anything is used.
		$input  = $input ?? $_POST;
		$posted = is_array( $input[ $this->id ] ?? null ) ? $input[ $this->id ] : [];
		$set    = $this->set( $this->context() );
		$object = $this->object_id();

		$this->result = [
			'ok'      => false,
			'values'  => $set->sanitize( $posted, $object ),
			'errors'  => [],
			'message' => '',
		];

		if ( $this->args['nonce'] && ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) ( $input[ $this->nonce_name() ] ?? '' ) ) ), $this->nonce_action() ) ) {
			$this->result['message'] = __( 'This form has expired. Please send it again.', 'sugarcommerce' );

			return $this->result;
		}

		$capability = (string) $this->args['capability'];

		if ( '' !== $capability && ! current_user_can( $capability ) ) {
			$this->result['message'] = __( 'You are not allowed to send this form.', 'sugarcommerce' );

			return $this->result;
		}

		// Somebody who filled in the box nobody can see is told it worked,
		// and nothing is done: a robot that is refused learns to try again.
		if ( $this->args['honeypot'] && '' !== trim( (string) ( is_scalar( $input[ $this->honeypot_name() ] ?? null ) ? $input[ $this->honeypot_name() ] : '' ) ) ) {
			$this->result['ok']      = true;
			$this->result['values']  = [];
			$this->result['message'] = (string) $this->args['success'];

			return $this->result;
		}

		$errors = $set->validate( $posted, $object );

		if ( [] !== $errors ) {
			$this->result['errors']  = $errors;
			$this->result['message'] = $this->summary( count( $errors ) );

			return $this->result;
		}

		$answer = is_callable( $this->args['on_submit'] ) ? ( $this->args['on_submit'] )( $this->result['values'], $this ) : true;

		if ( is_wp_error( $answer ) ) {
			$data = $answer->get_error_data();

			$this->result['errors']  = is_array( $data ) && is_array( $data['fields'] ?? null ) ? array_map( 'strval', $data['fields'] ) : [];
			$this->result['message'] = $answer->get_error_message();

			return $this->result;
		}

		if ( null !== $this->args['context'] ) {
			$set->save( $posted, $object );
		}

		$this->result['ok']      = true;
		$this->result['message'] = is_string( $answer ) && '' !== $answer ? $answer : (string) $this->args['success'];

		return $this->result;
	}

	/**
	 * Check a submission without accepting it.
	 *
	 * @param array<string, mixed> $values The fields' values, still slashed.
	 *
	 * @return array<string, string> A message per refused field.
	 */
	public function validate( array $values ): array {
		return $this->set( $this->context() )->validate( $values, $this->object_id() );
	}

	/**
	 * The last submission's outcome, or null before one.
	 *
	 * @return array{ok: bool, values: array<string, mixed>, errors: array<string, string>, message: string}|null
	 */
	public function result(): ?array {
		return $this->result;
	}

	/**
	 * The values of the last submission, clean, whether or not it was
	 * accepted.
	 *
	 * @return array<string, mixed>
	 */
	public function values(): array {
		return $this->result['values'] ?? [];
	}

	/**
	 * The refusals of the last submission, by field.
	 *
	 * @return array<string, string>
	 */
	public function errors(): array {
		return $this->result['errors'] ?? [];
	}

	/**
	 * Draw the form.
	 *
	 * A form that was just sent, and not yet handled, is handled first. A
	 * refused one is drawn with what was typed, the refusals listed above
	 * it and each beside its field; an accepted one says so and is drawn
	 * empty again, or with what its context now holds.
	 *
	 * @param array<string, mixed> $values Values to start with, by field.
	 *                                     Ignored where a context holds them.
	 *
	 * @return string
	 */
	public function render( array $values = [] ): string {
		if ( null === $this->result && $this->submitted() ) {
			$this->handle();
		}

		$this->enqueue();

		$result  = $this->result;
		$refused = null !== $result && ! $result['ok'];
		$context = $refused ? new ArrayContext( $result['values'] ) : ( $this->args['context'] ?? new ArrayContext( null === $result ? $values : [] ) );
		$set     = $this->set( $context );

		$attributes = new Attributes();
		$attributes->set( 'id', $this->id );
		$attributes->add_class( 'sugarcommerce-form', (string) $this->args['class'] );
		$attributes->set( 'method', 'post' );
		$attributes->set( 'action', '' === (string) $this->args['action'] ? '' : esc_url( (string) $this->args['action'] ) );
		$attributes->set( 'data-sugarcommerce-form', $this->id );
		$attributes->set_if( (bool) $this->args['rest'] && isset( self::$forms[ $this->id ] ), 'data-rest', rest_url( Utils\Runtime::rest_namespace() . '/forms/' . $this->id ) );
		$attributes->set_if( (bool) $this->args['guard'], Guard::ATTRIBUTE, true );
		$attributes->set_if( null !== $this->args['context'], 'data-keep', true );

		$hidden = sprintf( '<input type="hidden" name="%s" value="%s" />', esc_attr( self::FIELD ), esc_attr( $this->id ) );

		if ( $this->args['nonce'] ) {
			$hidden .= wp_nonce_field( $this->nonce_action(), $this->nonce_name(), false, false );
		}

		if ( $this->args['honeypot'] ) {
			$hidden .= sprintf(
				'<div class="sugarcommerce-form__trap" aria-hidden="true"><label for="%1$s">%2$s</label><input type="text" id="%1$s" name="%3$s" value="" tabindex="-1" autocomplete="off" /></div>',
				esc_attr( $this->id . '__trap' ),
				esc_html__( 'Leave this empty', 'sugarcommerce' ),
				esc_attr( $this->honeypot_name() )
			);
		}

		return sprintf(
			'<form%s>%s%s<div class="sugarcommerce-form__fields">%s</div><div class="sugarcommerce-form__actions"><button type="submit" class="sugarcommerce-form__submit">%s</button></div></form>',
			$attributes->render(),
			$this->notice( $result, $set ),
			$hidden,
			$set->render( $this->object_id(), $refused ? $result['errors'] : [] ),
			esc_html( (string) $this->args['submit'] )
		);
	}

	/**
	 * The message at the top: sent, refused with the refusals listed, or an
	 * empty live region the script writes into.
	 *
	 * @param array<string, mixed>|null $result The outcome, if any.
	 * @param FieldSet                  $set    The fields, for their ids.
	 *
	 * @return string
	 */
	private function notice( ?array $result, FieldSet $set ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only says a redirect happened.
		$sent = isset( $_GET[ $this->id ] ) && 'sent' === $_GET[ $this->id ];

		if ( null === $result && ! $sent ) {
			return '<div class="sugarcommerce-form__notice" role="status" tabindex="-1" hidden></div>';
		}

		if ( null === $result || $result['ok'] ) {
			return sprintf(
				'<div class="sugarcommerce-form__notice sugarcommerce-form__notice--success" role="status" tabindex="-1"><p>%s</p></div>',
				esc_html( null === $result ? (string) $this->args['success'] : $result['message'] )
			);
		}

		$items = '';

		foreach ( $result['errors'] as $key => $message ) {
			$field  = $set->field( (string) $key );
			$items .= sprintf(
				'<li><a href="#%s" data-field="%s">%s</a></li>',
				esc_attr( null === $field ? $this->id : $field->input_id() ),
				esc_attr( (string) $key ),
				esc_html( $message )
			);
		}

		return sprintf(
			'<div class="sugarcommerce-form__notice sugarcommerce-form__notice--error" role="alert" tabindex="-1"><p>%s</p>%s</div>',
			esc_html( $result['message'] ),
			'' === $items ? '' : '<ul>' . $items . '</ul>'
		);
	}

	/**
	 * The line above a list of refusals.
	 *
	 * @param int $count How many fields were refused.
	 *
	 * @return string
	 */
	private function summary( int $count ): string {
		return sprintf(
			/* translators: %d: how many fields need changing */
			_n( '%d field needs changing before this can be sent:', '%d fields need changing before this can be sent:', $count, 'sugarcommerce' ),
			$count
		);
	}

	/**
	 * What the fields need on the page, from the front-end stylesheet down.
	 *
	 * @return array{scripts: string[], styles: string[], code_editors: string[], editor: bool}
	 */
	public function dependencies(): array {
		return $this->set( new ArrayContext() )->dependencies();
	}

	/**
	 * Load the front-end stylesheet and the script.
	 *
	 * Called by render(), which is soon enough for a page that prints its
	 * late styles; call it on `wp_enqueue_scripts` as well to have them in
	 * the head.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( function_exists( 'wp_enqueue_script' ) ) {
			( new Assets() )->enqueue_front( $this->dependencies() );
		}
	}

	/**
	 * The field set doing the work, over a context.
	 *
	 * @param Context $context Where values are read from.
	 *
	 * @return FieldSet
	 */
	private function set( Context $context ): FieldSet {
		return new FieldSet( $this->fields, $context, $this->id, $this->registry );
	}

	/**
	 * The context submissions are checked against.
	 *
	 * @return Context
	 */
	private function context(): Context {
		return $this->args['context'] instanceof Context ? $this->args['context'] : new ArrayContext();
	}

	/**
	 * The object the context's values belong to.
	 *
	 * @return int|string
	 */
	private function object_id(): int|string {
		$id = $this->args['object_id'];

		return is_int( $id ) || is_string( $id ) ? $id : 0;
	}

	/**
	 * The nonce's action.
	 *
	 * @return string
	 */
	private function nonce_action(): string {
		return 'sugarcommerce_form_' . $this->id;
	}

	/**
	 * The nonce's input name.
	 *
	 * @return string
	 */
	private function nonce_name(): string {
		return '_' . $this->id . '_nonce';
	}

	/**
	 * The honeypot's input name: one a person's browser will not fill.
	 *
	 * @return string
	 */
	private function honeypot_name(): string {
		return '_' . $this->id . '_website';
	}

	/**
	 * Every field given, less those of a type that only works in wp-admin.
	 *
	 * Nested ones too: a repeater whose rows hold an image picker loses the
	 * image picker, not the repeater.
	 *
	 * @param array<string, array<string, mixed>> $fields Field configuration.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function public_fields( array $fields ): array {
		$kept = [];

		foreach ( $fields as $key => $config ) {
			$type = (string) ( $config['type'] ?? 'text' );

			if ( $this->registry->has( $type ) && $this->registry->get( $type ) instanceof AdminOnly ) {
				// Core's own notice, which says nothing unless WP_DEBUG is on.
				if ( function_exists( '_doing_it_wrong' ) ) {
					_doing_it_wrong(
						__METHOD__,
						esc_html(
							sprintf(
								/* translators: 1: field key, 2: field type */
								__( 'The field "%1$s" is a %2$s, which only works in wp-admin, so it is left off the form.', 'sugarcommerce' ),
								(string) $key,
								$type
							)
						),
						'1.4.0'
					);
				}

				continue;
			}

			foreach ( [ 'fields', 'sub_fields' ] as $nested ) {
				if ( isset( $config[ $nested ] ) && is_array( $config[ $nested ] ) ) {
					$config[ $nested ] = $this->public_fields( $config[ $nested ] );
				}
			}

			$kept[ (string) $key ] = $config;
		}

		return $kept;
	}

	/**
	 * The REST answer for a submission.
	 *
	 * @param array<string, mixed> $input The submission, still slashed.
	 *
	 * @return array<string, mixed>
	 */
	public function respond( array $input ): array {
		$result = $this->handle( $input );
		$set    = $this->set( new ArrayContext( $result['values'] ) );
		$ids    = [];

		foreach ( array_keys( $result['errors'] ) as $key ) {
			$field = $set->field( (string) $key );

			$ids[ $key ] = null === $field ? '' : $field->input_id();
		}

		return [
			'ok'      => $result['ok'],
			'message' => $result['message'],
			'errors'  => (object) $result['errors'],
			'ids'     => (object) $ids,
		];
	}
}
