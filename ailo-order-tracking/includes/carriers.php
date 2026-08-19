<?php
/**
 * Carriers are defined by the shop owner, not by this plugin.
 *
 * This is deliberate and it is the whole reason the plugin needs no external
 * service: a carrier is just a label plus a URL template containing {tracking}.
 * The shop owner adds their own — a national post office, a local courier, a
 * freight company — and the plugin never has to know anything about it, never
 * calls it, and never needs an API key or an account.
 *
 * @package AiloOrderTracking
 */

defined( 'ABSPATH' ) || exit;

const AILO_TRACK_OPTION_CARRIERS = 'ailo_track_carriers';

/**
 * All carriers configured on this store.
 *
 * @return array<string,array{label:string,url:string}> Keyed by slug.
 */
function ailo_track_get_carriers() {
	$raw = get_option( AILO_TRACK_OPTION_CARRIERS, array() );
	if ( ! is_array( $raw ) ) {
		return array();
	}

	$out = array();
	foreach ( $raw as $slug => $carrier ) {
		if ( ! is_array( $carrier ) || empty( $carrier['label'] ) ) {
			continue;
		}
		$slug = sanitize_key( $slug );
		if ( '' === $slug ) {
			continue;
		}
		$out[ $slug ] = array(
			'label' => sanitize_text_field( $carrier['label'] ),
			'url'   => isset( $carrier['url'] ) ? esc_url_raw( $carrier['url'] ) : '',
		);
	}
	return $out;
}

/**
 * Build the public tracking URL for one shipment.
 *
 * @param string $carrier_slug Carrier slug as stored on the order.
 * @param string $number       Tracking number.
 * @return string Empty string when the carrier is unknown or has no template.
 */
function ailo_track_build_url( $carrier_slug, $number ) {
	$carriers = ailo_track_get_carriers();
	$slug     = sanitize_key( $carrier_slug );
	if ( ! isset( $carriers[ $slug ] ) || '' === $carriers[ $slug ]['url'] ) {
		return '';
	}

	$url = str_replace( '{tracking}', rawurlencode( $number ), $carriers[ $slug ]['url'] );

	/**
	 * A shop owner's URL template is stored with esc_url_raw, but the template
	 * could still be http:// or a scheme we do not want to hand to a customer.
	 * wp_http_validate_url rejects anything that is not a public http(s) URL.
	 */
	$safe = wp_http_validate_url( $url );
	return $safe ? $safe : '';
}

/**
 * Human-readable carrier name.
 *
 * @param string $carrier_slug Carrier slug.
 * @return string Falls back to the slug so a removed carrier still shows something.
 */
function ailo_track_carrier_label( $carrier_slug ) {
	$carriers = ailo_track_get_carriers();
	$slug     = sanitize_key( $carrier_slug );
	if ( isset( $carriers[ $slug ] ) ) {
		return $carriers[ $slug ]['label'];
	}
	return $slug;
}
