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
use SugarCommerce\Fields\Traits\Form\Rendering;
use SugarCommerce\Fields\Traits\Form\Submission;
use SugarCommerce\Security\FormGuard;
use SugarCommerce\Security\RateLimit\Limiter;
use SugarCommerce\Security\Tokens\Signer;

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

	use Rendering;
	use Submission;

	/**
	 * The hidden input naming which form was sent.
	 */
	public const string FIELD = '_sugarcommerce_form';

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
	 *     @type bool     $honeypot   A box only a robot fills, and a signed
	 *                                stamp of when the form was drawn:
	 *                                sugarcommerce/security's FormGuard.
	 *                                On.
	 *     @type string   $capability What sending it needs; empty for anyone.
	 *     @type Limiter  $limiter    Counts sends per visitor: past `limit`,
	 *                                a send is refused before any field is
	 *                                checked or on_submit runs. None: no
	 *                                count.
	 *     @type int[]    $limit      Sends and seconds: `[ 5, 600 ]`.
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
			'limiter'    => null,
			'limit'      => [ 5, 600 ],
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
		return '_' . $this->id . '_' . FormGuard::TRAP;
	}

	/**
	 * The stamp's input name.
	 *
	 * @return string
	 */
	private function stamp_name(): string {
		return '_' . $this->id . FormGuard::STAMP;
	}

	/**
	 * The guard for this form's stamp, or null on a site with no usable
	 * salt to sign with -- where the trap still stands.
	 *
	 * @return FormGuard|null
	 */
	private function form_guard(): ?FormGuard {
		try {
			return new FormGuard( Signer::site( 'forms' ), $this->id );
		} catch ( \RuntimeException ) {
			return null;
		}
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
}
