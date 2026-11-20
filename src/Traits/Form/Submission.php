<?php
/**
 * Public Form: Submission
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.4.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Traits\Form;

use SugarCommerce\Fields\Context\ArrayContext;
use SugarCommerce\Security\FormGuard;
use SugarCommerce\Security\RateLimit\Limiter;

/**
 * Taking a public form's submission: checked, refused or stored, over a post or REST.
 *
 * Part of Form: only the class uses it.
 */
trait Submission {

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
		if ( $this->args['honeypot'] && FormGuard::trapped( self::posted( $input, $this->honeypot_name() ) ) ) {
			$this->result['ok']      = true;
			$this->result['values']  = [];
			$this->result['message'] = (string) $this->args['success'];

			return $this->result;
		}

		// Sent seconds after the page was drawn is a script; a day after, a
		// replayed page; with no stamp, not a page this site drew. A person
		// who was simply quick sends it again.
		$guard = $this->args['honeypot'] ? $this->form_guard() : null;

		if ( null !== $guard && ! $guard->human( self::posted( $input, $this->stamp_name() ) ) ) {
			$this->result['message'] = __( 'That was sent too soon after the page opened, or from a page left open too long. Please send it again.', 'sugarcommerce' );

			return $this->result;
		}

		// Counted only for a send that got this far: an expired page or a
		// robot's is not a visitor's try. The visitor is their address, as
		// the limiter resolves it.
		if ( $this->args['limiter'] instanceof Limiter ) {
			[ $count, $window ] = array_map( 'intval', array_values( (array) $this->args['limit'] ) + [ 5, 600 ] );

			if ( $this->args['limiter']->for( 'form_' . $this->id, '' )->hit( $count, $window )->denied() ) {
				$this->result['message'] = __( 'That has been sent too many times. Please wait a few minutes and try again.', 'sugarcommerce' );

				return $this->result;
			}
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

	/**
	 * One posted value as a string: '' when it is missing or not a string.
	 *
	 * @param array<string, mixed> $input The submission, still slashed.
	 * @param string               $name  The input's name.
	 *
	 * @return string
	 */
	private static function posted( array $input, string $name ): string {
		return is_scalar( $input[ $name ] ?? null ) ? wp_unslash( (string) $input[ $name ] ) : '';
	}
}
