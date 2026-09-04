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
			// Not esc_url_raw() here: it strips { and }, which would destroy the
			// {tracking} placeholder and leave a link pointing nowhere useful.
			// The template is stored raw and validated on the way in; escaping
			// happens on the way OUT, once {tracking} has been substituted.
			'url'   => isset( $carrier['url'] ) ? (string) $carrier['url'] : '',
		);
	}

	/**
	 * Carriers this store can use.
	 *
	 * The stored option holds carriers the shop owner typed in by hand. An add-on
	 * that talks to a courier API has no reason to make them type anything, so it
	 * can register its carriers here instead.
	 *
	 * Whatever comes back is put through the SAME shape check as stored carriers
	 * below. A filter is code from somewhere else, and the escaping guarantees the
	 * rest of the plugin relies on cannot depend on that code being careful.
	 *
	 * @since 1.1.0
	 * @param array<string,array{label:string,url:string}> $out Keyed by slug.
	 */
	$filtered = apply_filters( 'ailo_track_carriers', $out );
	if ( ! is_array( $filtered ) ) {
		return $out;
	}

	$safe = array();
	foreach ( $filtered as $slug => $carrier ) {
		if ( ! is_array( $carrier ) || empty( $carrier['label'] ) || ! is_scalar( $carrier['label'] ) ) {
			continue;
		}
		$slug = sanitize_key( $slug );
		if ( '' === $slug ) {
			continue;
		}
		$url = isset( $carrier['url'] ) && is_scalar( $carrier['url'] ) ? (string) $carrier['url'] : '';
		$safe[ $slug ] = array(
			'label' => sanitize_text_field( (string) $carrier['label'] ),
			'url'   => $url,
		);
	}
	return $safe;
}

/**
 * Is this an acceptable tracking URL template?
 *
 * Checked when the shop owner saves, not when a page renders. Scheme must be
 * http or https — that alone rules out javascript:, data: and anything else that
 * would turn a carrier link into an XSS vector — and there must be a host.
 *
 * Deliberately does NOT call wp_http_validate_url(): that performs a blocking
 * gethostbyname() and rejects private hosts, which is right for a server-side
 * fetch and wrong for a link a human clicks. We never fetch this URL.
 *
 * @param string $template Raw template as typed.
 * @return bool
 */
function ailo_track_valid_url_template( $template ) {
	$template = trim( (string) $template );
	if ( '' === $template ) {
		return false;
	}
	// Validate the shape with the placeholder swapped for something inert, so
	// the braces cannot upset the parser.
	$probe  = str_replace( '{tracking}', 'AILOTRACKPROBE', $template );
	$parts  = wp_parse_url( $probe );
	$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
	$host   = isset( $parts['host'] ) ? $parts['host'] : '';

	return in_array( $scheme, array( 'http', 'https' ), true ) && '' !== $host;
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

	$template = $carriers[ $slug ]['url'];

	// Re-check the shape here too. The option could have been written by an
	// older version of this plugin, by WP-CLI, or by another plugin, and this
	// value ends up in an href.
	if ( ! ailo_track_valid_url_template( $template ) ) {
		return '';
	}

	// Substitute FIRST, escape after: escaping the template would eat the braces.
	$url = str_replace( '{tracking}', rawurlencode( $number ), $template );

	// esc_url_raw for storage-shaped output; callers escape again with esc_url()
	// when printing. No network call here — this runs on every rendered link.
	return esc_url_raw( $url, array( 'http', 'https' ) );
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
