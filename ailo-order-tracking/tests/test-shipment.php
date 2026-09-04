<?php
/**
 * Writing shipment data onto an order.
 *
 * @package AiloOrderTracking
 */

/**
 * @covers ::ailo_track_normalize_number
 * @covers ::ailo_track_set_shipment
 */
class Test_Ailo_Track_Shipment extends WP_UnitTestCase {

	/**
	 * Fired signals, collected by the listener.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private $fired = array();

	public function set_up() {
		parent::set_up();
		$this->fired = array();
		add_action(
			'ailo_track_shipment_saved',
			function ( $order_id, $number, $carrier, $prev_number, $prev_carrier ) {
				$this->fired[] = compact( 'order_id', 'number', 'carrier', 'prev_number', 'prev_carrier' );
			},
			10,
			5
		);
	}

	/**
	 * A fresh order to hang tests off.
	 *
	 * @return WC_Order
	 */
	private function make_order() {
		$order = wc_create_order();
		$order->save();
		return $order;
	}

	public function test_number_is_normalised() {
		// Carriers print numbers with spaces and dashes and customers copy them
		// inconsistently, so both sides are reduced to the same shape.
		$this->assertSame( 'ABC123456789', ailo_track_normalize_number( 'abc-123 456/789' ) );
		$this->assertSame( 'ABC123', ailo_track_normalize_number( '  abc/123  ' ) );
		$this->assertSame( '', ailo_track_normalize_number( '---' ) );
	}

	public function test_number_is_capped_at_64_characters() {
		$this->assertSame( 64, strlen( ailo_track_normalize_number( str_repeat( 'A', 200 ) ) ) );
	}

	public function test_shipment_is_stored_normalised() {
		$order = $this->make_order();
		ailo_track_set_shipment( $order, 'abc-123 456/789', '' );

		$this->assertSame( 'ABC123456789', ailo_track_get_meta( $order->get_id(), AILO_TRACK_META_NUMBER ) );
	}

	public function test_signal_fires_once_on_a_new_shipment() {
		$order = $this->make_order();
		ailo_track_set_shipment( $order, 'ABC111', '' );

		$this->assertCount( 1, $this->fired );
		$this->assertSame( 'ABC111', $this->fired[0]['number'] );
		$this->assertSame( '', $this->fired[0]['prev_number'] );
	}

	/**
	 * WooCommerce runs both woocommerce_process_shop_order_meta and
	 * save_post_shop_order for a single save of one order. An action that fired
	 * unconditionally would tell an add-on twice that a shipment exists, and the
	 * customer would get two emails.
	 */
	public function test_signal_is_silent_when_nothing_changed() {
		$order = $this->make_order();
		ailo_track_set_shipment( $order, 'ABC111', '' );
		$this->fired = array();

		ailo_track_set_shipment( $order, 'ABC111', '' );

		$this->assertCount( 0, $this->fired );
	}

	public function test_signal_fires_on_change_and_carries_the_previous_value() {
		$order = $this->make_order();
		ailo_track_set_shipment( $order, 'ABC111', '' );
		$this->fired = array();

		ailo_track_set_shipment( $order, 'XYZ999', '' );

		$this->assertCount( 1, $this->fired );
		$this->assertSame( 'XYZ999', $this->fired[0]['number'] );
		$this->assertSame( 'ABC111', $this->fired[0]['prev_number'] );
	}

	public function test_empty_number_clears_the_shipment() {
		$order = $this->make_order();
		ailo_track_set_shipment( $order, 'ABC111', '' );
		ailo_track_set_shipment( $order, '', '' );

		$this->assertSame( '', ailo_track_get_meta( $order->get_id(), AILO_TRACK_META_NUMBER ) );
	}

	public function test_missing_order_is_refused_rather_than_fatal() {
		$this->assertFalse( ailo_track_set_shipment( 999999, 'ABC111', '' ) );
	}
}
