<?php
/**
 * Public Form: Rendering
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.4.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Traits\Form;

use SugarCommerce\Fields\Attributes;
use SugarCommerce\Fields\Context\ArrayContext;
use SugarCommerce\Fields\FieldSet;
use SugarCommerce\Fields\Utils\Runtime;
use SugarCommerce\Fields\Support\Guard;

/**
 * Drawing a public form, with its notice and the summary of what was refused.
 *
 * Part of Form: only the class uses it.
 */
trait Rendering {

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
		$attributes->set_if( (bool) $this->args['rest'] && isset( self::$forms[ $this->id ] ), 'data-rest', rest_url( Runtime::rest_namespace() . '/forms/' . $this->id ) );
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
}
