<?php
/**
 * The public lookup endpoint.
 *
 * This is the file that matters. The endpoint is open to anyone, it is the only
 * part of the plugin an attacker can reach, and every test here exists because
 * getting it wrong leaks other people's shipping data.
 *
 * @package AiloOrderTracking
 */

/**
 * @covers ::ailo_track_rest_lookup
 */
class Test_Ailo_Track_Lookup extends WP_UnitTestCase {

	/**
	 * Order A — the one being looked up.
	 *
	 * @var WC_Order
	 */
	private $order_a;

	/**
	 * Order B — belongs to somebody else.
	 *
	 * @var WC_Order
	 */
	private $order_b;

	public function set_up() {
		parent::set_up();

		do_action( 'rest_api_init' );

		$this->order_a = wc_create_order();
		$this->order_a->set_billing_email( 'owner@example.com' );
		$this->order_a->set_billing_phone( '070 123 456' );
		$this->order_a->save();
		ailo_track_set_shipment( $this->order_a, 'TRACKAAA', '' );

		$this->order_b = wc_create_order();
		$this->order_b->set_billing_email( 'someone.else@example.com' );
		$this->order_b->save();
		ailo_track_set_shipment( $this->order_b, 'SECRETBBB', '' );
	}

	/**
	 * Call the endpoint.
	 *
	 * @param array<string,mixed> $params Request parameters.
	 * @return WP_REST_Response
	 */
	private function lookup( array $params ) {
		$request = new WP_REST_Request( 'POST', '/ailo-track/v1/lookup' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_do_request( $request );
	}

	/**
	 * Did the call return a shipment?
	 *
	 * @param WP_REST_Response $response Response.
	 * @return bool
	 */
	private function found( $response ) {
		$data = $response->get_data();
		return 200 === $response->get_status() && ! empty( $data['tracking'] );
	}

	public function test_route_is_registered() {
		$this->assertArrayHasKey( '/ailo-track/v1/lookup', rest_get_server()->get_routes() );
	}

	// ── by tracking number: the number is the secret ──

	public function test_correct_tracking_number_is_found() {
		$this->assertTrue(
			$this->found(
				$this->lookup(
					array(
						'mode'     => 'tracking',
						'tracking' => 'TRACKAAA',
					)
				)
			)
		);
	}

	public function test_tracking_number_is_matched_after_normalising() {
		// The customer copies it off a label with dashes, or types it in lower case.
		$this->assertTrue(
			$this->found(
				$this->lookup(
					array(
						'mode'     => 'tracking',
						'tracking' => 'track-aaa',
					)
				)
			)
		);
	}

	public function test_unknown_tracking_number_is_not_found() {
		$this->assertFalse(
			$this->found(
				$this->lookup(
					array(
						'mode'     => 'tracking',
						'tracking' => 'NOSUCHNUM',
					)
				)
			)
		);
	}

	// ── by order number: order numbers are sequential, so ownership must be proven ──

	public function test_order_lookup_without_proof_is_refused() {
		$this->assertFalse(
			$this->found(
				$this->lookup(
					array(
						'mode'     => 'order',
						'order_id' => $this->order_a->get_id(),
					)
				)
			)
		);
	}

	public function test_order_lookup_with_the_wrong_email_is_refused() {
		$this->assertFalse(
			$this->found(
				$this->lookup(
					array(
						'mode'     => 'order',
						'order_id' => $this->order_a->get_id(),
						'contact'  => 'nobody@example.com',
					)
				)
			)
		);
	}

	public function test_order_lookup_with_the_right_email_is_allowed() {
		$this->assertTrue(
			$this->found(
				$this->lookup(
					array(
						'mode'     => 'order',
						'order_id' => $this->order_a->get_id(),
						'contact'  => 'owner@example.com',
					)
				)
			)
		);
	}

	public function test_email_comparison_ignores_case() {
		$this->assertTrue(
			$this->found(
				$this->lookup(
					array(
						'mode'     => 'order',
						'order_id' => $this->order_a->get_id(),
						'contact'  => 'Owner@Example.COM',
					)
				)
			)
		);
	}

	public function test_order_lookup_with_the_right_phone_is_allowed() {
		$this->assertTrue(
			$this->found(
				$this->lookup(
					array(
						'mode'     => 'order',
						'order_id' => $this->order_a->get_id(),
						'contact'  => '070123456',
					)
				)
			)
		);
	}

	public function test_phone_comparison_ignores_spacing() {
		$this->assertTrue(
			$this->found(
				$this->lookup(
					array(
						'mode'     => 'order',
						'order_id' => $this->order_a->get_id(),
						'contact'  => '070 123 456',
					)
				)
			)
		);
	}

	public function test_order_lookup_with_the_wrong_phone_is_refused() {
		$this->assertFalse(
			$this->found(
				$this->lookup(
					array(
						'mode'     => 'order',
						'order_id' => $this->order_a->get_id(),
						'contact'  => '070999999',
					)
				)
			)
		);
	}

	/**
	 * The phone oracle.
	 *
	 * A suffix match would let someone guess a number a digit at a time: try
	 * "6", then "56", then "456", and watch which one starts succeeding. The
	 * comparison is on the full national form for exactly this reason.
	 */
	public function test_a_partial_phone_number_is_refused() {
		foreach ( array( '456', '123456', '3456' ) as $partial ) {
			$this->assertFalse(
				$this->found(
					$this->lookup(
						array(
							'mode'     => 'order',
							'order_id' => $this->order_a->get_id(),
							'contact'  => $partial,
						)
					)
				),
				"Partial phone '{$partial}' was accepted as proof of ownership."
			);
		}
	}

	/**
	 * Knowing your own details must not open anyone else's order.
	 */
	public function test_valid_contact_does_not_open_a_different_order() {
		$this->assertFalse(
			$this->found(
				$this->lookup(
					array(
						'mode'     => 'order',
						'order_id' => $this->order_b->get_id(),
						'contact'  => 'owner@example.com',
					)
				)
			)
		);
	}

	// ── what comes back ──

	/**
	 * A successful response carries the shipment and nothing else. No name, no
	 * address, no email, no phone, no order total.
	 */
	public function test_response_contains_no_personal_data() {
		$response = $this->lookup(
			array(
				'mode'     => 'tracking',
				'tracking' => 'TRACKAAA',
			)
		);
		$data     = (array) $response->get_data();

		$this->assertSame( array( 'tracking', 'carrier', 'url' ), array_keys( $data ) );

		$serialised = wp_json_encode( $data );
		foreach ( array( 'owner@example.com', '070', 'someone.else' ) as $leak ) {
			$this->assertStringNotContainsString( $leak, $serialised );
		}
	}
}
