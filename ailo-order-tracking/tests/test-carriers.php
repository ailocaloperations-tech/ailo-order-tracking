<?php
/**
 * Carriers and the URLs built from them.
 *
 * A carrier URL ends up in an href on a public page, so everything here is
 * about what must never reach it.
 *
 * @package AiloOrderTracking
 */

/**
 * @covers ::ailo_track_get_carriers
 * @covers ::ailo_track_valid_url_template
 * @covers ::ailo_track_build_url
 */
class Test_Ailo_Track_Carriers extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		update_option(
			AILO_TRACK_OPTION_CARRIERS,
			array(
				'post' => array(
					'label' => 'National Post',
					'url'   => 'https://post.example/track?n={tracking}',
				),
			)
		);
	}

	public function test_stored_carriers_are_returned() {
		$carriers = ailo_track_get_carriers();
		$this->assertArrayHasKey( 'post', $carriers );
		$this->assertSame( 'National Post', $carriers['post']['label'] );
	}

	public function test_url_is_built_with_the_number_encoded() {
		$this->assertSame(
			'https://post.example/track?n=ABC%20123',
			ailo_track_build_url( 'post', 'ABC 123' )
		);
	}

	public function test_unknown_carrier_produces_no_url() {
		$this->assertSame( '', ailo_track_build_url( 'nosuchcarrier', 'ABC123' ) );
	}

	/**
	 * The scheme check is the whole defence against a carrier link becoming an
	 * XSS vector, so it is asserted directly rather than only through build_url.
	 */
	public function test_only_http_and_https_templates_are_valid() {
		$this->assertTrue( ailo_track_valid_url_template( 'https://a.example/t/{tracking}' ) );
		$this->assertTrue( ailo_track_valid_url_template( 'http://a.example/t/{tracking}' ) );

		$this->assertFalse( ailo_track_valid_url_template( 'javascript:alert(1)' ) );
		$this->assertFalse( ailo_track_valid_url_template( 'data:text/html,<script>alert(1)</script>' ) );
		$this->assertFalse( ailo_track_valid_url_template( '' ) );
		$this->assertFalse( ailo_track_valid_url_template( 'not a url at all' ) );
		$this->assertFalse( ailo_track_valid_url_template( 'https://' ) );
	}

	/**
	 * The template keeps its braces on the way in.
	 *
	 * esc_url_raw() strips { and }, which would destroy the {tracking}
	 * placeholder and leave every link pointing at the carrier's home page with
	 * no number on it.
	 */
	public function test_placeholder_survives_storage() {
		$carriers = ailo_track_get_carriers();
		$this->assertStringContainsString( '{tracking}', $carriers['post']['url'] );
	}

	// ── the filter, which is code from somewhere else ──

	public function test_filter_can_add_a_carrier() {
		add_filter(
			'ailo_track_carriers',
			static function ( $carriers ) {
				$carriers['api'] = array(
					'label' => 'API Courier',
					'url'   => 'https://api.example/t?c={tracking}',
				);
				return $carriers;
			}
		);

		$carriers = ailo_track_get_carriers();
		$this->assertArrayHasKey( 'api', $carriers );
		$this->assertSame( 'https://api.example/t?c=XY9', ailo_track_build_url( 'api', 'XY9' ) );
	}

	/**
	 * A filter is somebody else's code. The escaping guarantees the rest of the
	 * plugin relies on cannot depend on that code having been careful.
	 */
	public function test_filtered_carrier_label_is_sanitised() {
		add_filter(
			'ailo_track_carriers',
			static function ( $carriers ) {
				$carriers['evil'] = array(
					'label' => '<script>alert(1)</script>',
					'url'   => 'https://ok.example/{tracking}',
				);
				return $carriers;
			}
		);

		$carriers = ailo_track_get_carriers();
		$this->assertStringNotContainsString( '<script', $carriers['evil']['label'] );
	}

	public function test_filtered_carrier_cannot_smuggle_a_javascript_url() {
		add_filter(
			'ailo_track_carriers',
			static function ( $carriers ) {
				$carriers['evil'] = array(
					'label' => 'Evil',
					'url'   => 'javascript:alert(1)',
				);
				return $carriers;
			}
		);

		$this->assertSame( '', ailo_track_build_url( 'evil', 'ABC' ) );
	}

	public function test_filter_returning_rubbish_does_not_break_the_list() {
		add_filter( 'ailo_track_carriers', static fn() => 'not an array' );
		$this->assertIsArray( ailo_track_get_carriers() );
	}
}
