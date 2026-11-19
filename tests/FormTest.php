<?php
/**
 * Public forms, their REST route and the lookup routes.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Tests;

use SugarCommerce\Fields\Context\ArrayContext;
use SugarCommerce\Fields\FieldSet;
use SugarCommerce\Fields\Form;
use SugarCommerce\Fields\Registry;
use SugarCommerce\Fields\Rest\FormController;
use SugarCommerce\Fields\Rest\LookupController;
use SugarCommerce\Fields\Support\Guard;
use SugarCommerce\Fields\Support\Lookups;
use SugarCommerce\Fields\Contracts\AdminOnly;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

require_once __DIR__ . '/stubs/Assets.php';

/**
 * The same fields and the same checks as wp-admin, behind a nonce, a
 * honeypot and an all-or-nothing submission.
 */
final class FormTest extends TestCase {

	protected function setUp(): void {
		unset( $GLOBALS['pf_nonce_ok'], $GLOBALS['fk_doing_it_wrong'], $GLOBALS['fk_logged_in'], $GLOBALS['fk_user_can'] );
		$_POST = [];
		$_GET  = [];
	}

	protected function tearDown(): void {
		unset( $GLOBALS['pf_nonce_ok'], $GLOBALS['fk_logged_in'], $GLOBALS['fk_user_can'] );
		$_POST = [];
		$_GET  = [];
	}

	private function fields(): array {
		return [
			'name'  => [ 'type' => 'text', 'label' => 'Name', 'required' => true, 'maxlength' => 10 ],
			'email' => [ 'type' => 'email', 'label' => 'Email', 'required' => true ],
			'terms' => [ 'type' => 'checkbox', 'label' => 'I accept', 'required' => true ],
			'size'  => [ 'type' => 'select', 'label' => 'Size', 'options' => [ 's' => 'Small', 'l' => 'Large' ] ],
		];
	}

	private function post( array $values, array $extra = [] ): array {
		return array_merge(
			[
				Form::FIELD         => 'order',
				'order'             => $values,
				'_order_nonce'      => 'nonce',
				'_order_website'    => '',
			],
			$extra
		);
	}

	public function test_it_draws_a_form_with_a_nonce_a_honeypot_and_the_fields(): void {
		$html = ( new Form( 'order', $this->fields(), [ 'submit' => 'Buy', 'guard' => true ] ) )->render( [ 'name' => 'Ada' ] );

		$this->assertStringStartsWith( '<form class="sugarcommerce-form" id="order" method="post"', $html );
		$this->assertStringContainsString( 'name="_sugarcommerce_form" value="order"', $html );
		$this->assertStringContainsString( 'name="_order_nonce" value="nonce-sugarcommerce_form_order"', $html );
		$this->assertStringContainsString( 'name="_order_website"', $html );
		$this->assertStringContainsString( 'name="order[name]"', $html );
		$this->assertStringContainsString( 'value="Ada"', $html );
		$this->assertStringContainsString( '<label for="order_email_">Email', $html );
		$this->assertStringContainsString( 'data-fk-guard', $html );
		$this->assertStringContainsString( '>Buy</button>', $html );
		$this->assertStringNotContainsString( 'data-rest', $html, 'Only a registered form has a route.' );
	}

	public function test_a_good_submission_goes_to_on_submit_clean(): void {
		$got  = null;
		$form = new Form(
			'order',
			$this->fields(),
			[
				'on_submit' => function ( array $values ) use ( &$got ) {
					$got = $values;

					return 'Thanks, ' . $values['name'] . '.';
				},
			]
		);

		$result = $form->handle( $this->post( [ 'name' => '  Ada <b>L</b> ', 'email' => 'ada@example.com', 'terms' => '1', 'size' => 'x' ] ) );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'Thanks, Ada L.', $result['message'] );
		$this->assertSame( [ 'name' => 'Ada L', 'email' => 'ada@example.com', 'terms' => 1 ], $got, 'An option not offered is no value.' );
	}

	public function test_one_refusal_and_nothing_is_sent_or_stored(): void {
		$called  = false;
		$context = new ArrayContext( [ 'name' => 'Old' ] );
		$form    = new Form(
			'order',
			$this->fields(),
			[
				'context'   => $context,
				'on_submit' => function () use ( &$called ) {
					$called = true;
				},
			]
		);

		$result = $form->handle( $this->post( [ 'name' => 'Grace', 'email' => 'not-an-email', 'terms' => '' ] ) );

		$this->assertFalse( $result['ok'] );
		$this->assertFalse( $called );
		$this->assertSame( 'Old', $context->values()['name'] );
		$this->assertSame( [ 'email', 'terms' ], array_keys( $result['errors'] ) );
		$this->assertSame( 'I accept is required.', $result['errors']['terms'] );
		$this->assertSame( '2 fields need changing before this can be sent:', $result['message'] );
		$this->assertSame( 'Grace', $result['values']['name'], 'What was typed comes back.' );
	}

	public function test_the_hard_limit_holds_on_the_server(): void {
		$result = ( new Form( 'order', $this->fields() ) )->handle( $this->post( [ 'name' => 'Ada Lovelace!', 'email' => 'a@example.com', 'terms' => '1' ] ) );

		$this->assertSame( [ 'name' => 'Name can be at most 10 characters long.' ], $result['errors'] );
	}

	public function test_an_accepted_submission_is_saved_to_the_context(): void {
		$context = new ArrayContext();
		$result  = ( new Form( 'order', $this->fields(), [ 'context' => $context ] ) )->handle( $this->post( [ 'name' => 'Ada', 'email' => 'a@example.com', 'terms' => '1', 'size' => 'l' ] ) );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( [ 'name' => 'Ada', 'email' => 'a@example.com', 'terms' => 1, 'size' => 'l' ], $context->values() );
	}

	public function test_on_submit_can_refuse_with_messages_per_field(): void {
		$form = new Form(
			'order',
			$this->fields(),
			[ 'on_submit' => static fn() => new WP_Error( 'taken', 'That did not work.', [ 'fields' => [ 'email' => 'That email has an account.' ] ] ) ]
		);

		$result = $form->handle( $this->post( [ 'name' => 'Ada', 'email' => 'a@example.com', 'terms' => '1' ] ) );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'That did not work.', $result['message'] );
		$this->assertSame( [ 'email' => 'That email has an account.' ], $result['errors'] );
	}

	public function test_a_bad_nonce_is_refused_before_anything_runs(): void {
		$GLOBALS['pf_nonce_ok'] = false;
		$called                 = false;
		$result                 = ( new Form( 'order', $this->fields(), [ 'on_submit' => function () use ( &$called ) { $called = true; } ] ) )->handle( $this->post( [ 'name' => 'Ada', 'email' => 'a@example.com', 'terms' => '1' ] ) );

		$this->assertFalse( $result['ok'] );
		$this->assertFalse( $called );
		$this->assertSame( 'This form has expired. Please send it again.', $result['message'] );

		$this->assertTrue( ( new Form( 'order', $this->fields(), [ 'nonce' => false ] ) )->handle( $this->post( [ 'name' => 'Ada', 'email' => 'a@example.com', 'terms' => '1' ] ) )['ok'] );
	}

	public function test_the_capability_is_checked(): void {
		$GLOBALS['fk_user_can'] = [ 'read' => false ];

		$result = ( new Form( 'order', $this->fields(), [ 'capability' => 'read' ] ) )->handle( $this->post( [ 'name' => 'Ada', 'email' => 'a@example.com', 'terms' => '1' ] ) );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'You are not allowed to send this form.', $result['message'] );
	}

	public function test_a_robot_is_told_it_worked_and_nothing_happens(): void {
		$called = false;
		$result = ( new Form( 'order', $this->fields(), [ 'on_submit' => function () use ( &$called ) { $called = true; } ] ) )
			->handle( $this->post( [ 'name' => 'Ada', 'email' => 'a@example.com', 'terms' => '1' ], [ '_order_website' => 'http://spam.test' ] ) );

		$this->assertTrue( $result['ok'] );
		$this->assertFalse( $called );
		$this->assertSame( [], $result['values'] );
	}

	public function test_a_refused_form_is_drawn_with_the_list_and_what_was_typed(): void {
		$_POST = $this->post( [ 'name' => 'Grace', 'email' => '' ] );
		$html  = ( new Form( 'order', $this->fields() ) )->render();

		$this->assertStringContainsString( 'sugarcommerce-form__notice--error" role="alert"', $html );
		$this->assertStringContainsString( '<li><a href="#order_email_" data-field="email">Email is required.</a></li>', $html );
		$this->assertStringContainsString( 'value="Grace"', $html );
		$this->assertStringContainsString( 'aria-invalid="true"', $html );
		$this->assertStringContainsString( 'id="order_email___error"', $html );
	}

	public function test_an_accepted_form_says_so_and_starts_again(): void {
		$_POST = $this->post( [ 'name' => 'Grace', 'email' => 'g@example.com', 'terms' => '1' ] );
		$html  = ( new Form( 'order', $this->fields(), [ 'success' => 'Sent.' ] ) )->render();

		$this->assertStringContainsString( 'sugarcommerce-form__notice--success" role="status" tabindex="-1"><p>Sent.</p>', $html );
		$this->assertStringNotContainsString( 'value="Grace"', $html );

		$_POST = [];
		$_GET  = [ 'order' => 'sent' ];

		$this->assertStringContainsString( '<p>Sent.</p>', ( new Form( 'order', $this->fields(), [ 'success' => 'Sent.' ] ) )->render(), 'After the redirect.' );
	}

	public function test_another_forms_submission_is_not_this_ones(): void {
		$_POST = array_merge( $this->post( [ 'name' => 'Grace' ] ), [ Form::FIELD => 'other' ] );

		$form = new Form( 'order', $this->fields() );

		$this->assertFalse( $form->submitted() );
		$this->assertStringNotContainsString( 'notice--error', $form->render() );
		$this->assertNull( $form->result() );
	}

	public function test_admin_only_types_are_left_off_and_said_so(): void {
		$GLOBALS['fk_doing_it_wrong'] = [];

		$form = new Form(
			'order',
			[
				'name'  => [ 'type' => 'text', 'label' => 'Name' ],
				'photo' => [ 'type' => 'image', 'label' => 'Photo' ],
				'rows'  => [
					'type'   => 'repeater',
					'label'  => 'Rows',
					'fields' => [
						'who'  => [ 'type' => 'user', 'label' => 'Who' ],
						'note' => [ 'type' => 'text', 'label' => 'Note' ],
					],
				],
			]
		);

		$this->assertSame( [ 'name', 'rows' ], array_keys( $form->fields() ) );
		$this->assertSame( [ 'note' ], array_keys( $form->fields()['rows']['fields'] ) );
		$this->assertCount( 2, $GLOBALS['fk_doing_it_wrong'] );
	}

	public function test_the_admin_only_types_are_these(): void {
		$registry = new Registry();
		$admin    = array_values( array_filter( $registry->ids(), static fn( string $id ): bool => $registry->get( $id ) instanceof AdminOnly ) );

		$this->assertSame(
			[ 'action_button', 'ajax', 'code', 'email_editor', 'file', 'file_url', 'files', 'flexible', 'gallery', 'icon', 'image', 'license', 'page', 'post', 'taxonomy', 'user', 'wysiwyg' ],
			$admin
		);
	}

	public function test_a_registered_form_is_sent_over_rest(): void {
		$GLOBALS['fk_routes'] = [];

		$form = Form::register( 'rest_order', $this->fields(), [ 'rest' => true ] );

		( new FormController() )->register_routes();

		$this->assertSame( $form, Form::get( 'rest_order' ) );
		$this->assertStringContainsString( 'data-rest="https://example.test/wp-json/sugarcommerce-fields/v1/forms/rest_order"', $form->render() );

		$request = new WP_REST_Request( 'POST', '/forms/rest_order' );
		$request->set_param( 'id', 'rest_order' );
		$request->set_body_params( [ Form::FIELD => 'rest_order', 'rest_order' => [ 'name' => 'Ada', 'email' => 'bad' ], '_rest_order_nonce' => 'n' ] );

		$response = ( new FormController() )->submit( $request );
		$data     = $response->get_data();

		$this->assertSame( 422, $response->get_status() );
		$this->assertFalse( $data['ok'] );
		$this->assertSame( 'rest_order_email_', $data['ids']->email );
		$this->assertObjectHasProperty( 'terms', $data['errors'] );

		$request->set_body_params( [ Form::FIELD => 'rest_order', 'rest_order' => [ 'name' => 'Ada', 'email' => 'a@example.com', 'terms' => '1' ], '_rest_order_nonce' => 'n' ] );

		$response = ( new FormController() )->submit( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Ada', Form::get( 'rest_order' )->values()['name'] );

		$missing = new WP_REST_Request( 'POST', '/forms/nope' );
		$missing->set_param( 'id', 'nope' );

		$this->assertSame( 404, ( new FormController() )->submit( $missing )->get_status() );
	}

	public function test_a_lookup_answers_whoever_its_field_allows(): void {
		Lookups::shared()->register( 'open_lookup', static fn( string $q ): array => [ 'echo' => $q ], '' );
		Lookups::shared()->register( 'closed_lookup', static fn( string $q ): array => [ 'echo' => $q ], 'manage_options' );
		Lookups::shared()->register( 'failing_lookup', static fn(): WP_Error => new WP_Error( 'nope', 'Nope.' ), '' );

		$controller = new LookupController();
		$ask        = static function ( string $name, string $q = 'x' ): WP_REST_Request {
			$request = new WP_REST_Request( 'GET', '/lookup' );
			$request->set_param( 'name', $name );
			$request->set_param( 'q', $q );

			return $request;
		};

		$GLOBALS['fk_logged_in'] = false;

		$this->assertTrue( $controller->check_lookup( $ask( 'open_lookup' ) ) );
		$this->assertSame( 401, $controller->check_lookup( $ask( 'closed_lookup' ) )->get_error_data()['status'] );
		$this->assertSame( 401, $controller->check_lookup( $ask( 'no_such_lookup' ) )->get_error_data()['status'], 'Unknown answers as forbidden to a stranger.' );

		$GLOBALS['fk_logged_in'] = true;
		$GLOBALS['fk_user_can']  = [ 'manage_options' => false ];

		$this->assertInstanceOf( WP_Error::class, $controller->check_lookup( $ask( 'closed_lookup' ) ) );
		$this->assertSame( 404, $controller->check_lookup( $ask( 'no_such_lookup' ) )->get_error_data()['status'] );

		$GLOBALS['fk_user_can'] = [ 'manage_options' => true ];

		$this->assertTrue( $controller->check_lookup( $ask( 'closed_lookup' ) ) );
		$this->assertSame( [ 'echo' => 'hi' ], $controller->lookup( $ask( 'closed_lookup', 'hi' ) )->get_data() );
		$this->assertSame( 422, $controller->lookup( $ask( 'failing_lookup' ) )->get_error_data()['status'] );
	}

	public function test_the_address_and_embed_routes_answer_anyone(): void {
		$controller = new LookupController();

		$request = new WP_REST_Request( 'GET', '/address/us' );
		$request->set_param( 'country', 'us' );

		$response = $controller->address( $request );

		$this->assertSame( 'US', $response->get_data()['country'] );
		$this->assertSame( 'public, max-age=86400', $response->get_headers()['Cache-Control'] );

		$embed = new WP_REST_Request( 'GET', '/embed' );
		$embed->set_param( 'url', 'https://vimeo.com/76979871' );
		$embed->set_param( 'platforms', [ 'youtube' ] );
		$embed->set_param( 'title', '' );

		$this->assertSame( 'sugarcommerce_fields_not_embeddable', $controller->embed( $embed )->get_error_code() );

		$embed->set_param( 'platforms', [] );

		$this->assertSame( 'vimeo', $controller->embed( $embed )->get_data()['platform'] );
	}

	public function test_the_guard_is_asked_for_by_selector(): void {
		$GLOBALS['fk_registered'] = [];

		Guard::enable( '.my-settings form' );
		Guard::enable( '  ' );

		$inline = implode( '', $GLOBALS['fk_registered']['inline']['sugarcommerce-fields'] ?? [] );

		$this->assertStringContainsString( '.guard=(window.SugarCommerceFields["sugarcommerce-fields"].guard||[]).concat([".my-settings form"]);', $inline );
		$this->assertSame( 1, substr_count( $inline, '.guard=' ) );
	}

	/**
	 * A field whose condition is not met is no value, whatever was sent.
	 */
	public function test_a_hidden_conditional_field_sends_nothing(): void {
		$set = new FieldSet(
			[
				'format'  => [ 'type' => 'radio', 'label' => 'Format', 'options' => [ 'digital' => 'D', 'physical' => 'P' ] ],
				'address' => [ 'type' => 'text', 'label' => 'Address', 'show_when' => [ 'format' => 'physical' ] ],
			],
			new ArrayContext(),
			'c'
		);

		$this->assertSame( [ 'format' => 'digital' ], $set->sanitize( [ 'format' => 'digital', 'address' => '1 Main St' ] ) );
		$this->assertSame( [ 'format' => 'physical', 'address' => '1 Main St' ], $set->sanitize( [ 'format' => 'physical', 'address' => '1 Main St' ] ) );
	}
}
