<?php
/**
 * Carrier settings, as a section inside WooCommerce → Settings → Shipping.
 *
 * Deliberately not a new top-level admin menu. Guideline 11 of the plugin
 * directory is about not hijacking the admin experience, and a tracking plugin
 * that plants its own menu item next to Posts for two text fields is exactly
 * the kind of thing that annoys reviewers and shop owners equally.
 *
 * @package AiloOrderTracking
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'woocommerce_get_sections_shipping',
	static function ( $sections ) {
		$sections['ailo_track'] = __( 'Order tracking', 'ailo-order-tracking' );
		return $sections;
	}
);

add_action(
	'woocommerce_settings_shipping',
	static function () {
		global $current_section;
		if ( 'ailo_track' !== $current_section ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$carriers = ailo_track_get_carriers();
		$rows     = max( 3, count( $carriers ) + 1 );
		$list     = array_values( $carriers );
		$slugs    = array_keys( $carriers );

		wp_nonce_field( 'ailo_track_settings', 'ailo_track_settings_nonce' );
		?>
		<h2><?php esc_html_e( 'Carriers', 'ailo-order-tracking' ); ?></h2>
		<p>
			<?php
			echo esc_html__(
				'Add the carriers you ship with. The tracking URL is a template: put {tracking} where the carrier expects the tracking number. Leave the URL empty and the number is shown without a link.',
				'ailo-order-tracking'
			);
			?>
		</p>
		<table class="widefat" style="max-width:900px;">
			<thead>
				<tr>
					<th style="width:30%;"><?php esc_html_e( 'Name', 'ailo-order-tracking' ); ?></th>
					<th><?php esc_html_e( 'Tracking URL template', 'ailo-order-tracking' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php for ( $i = 0; $i < $rows; $i++ ) : ?>
				<?php
				$label = isset( $list[ $i ]['label'] ) ? $list[ $i ]['label'] : '';
				$url   = isset( $list[ $i ]['url'] ) ? $list[ $i ]['url'] : '';
				$slug  = isset( $slugs[ $i ] ) ? $slugs[ $i ] : '';
				?>
				<tr>
					<td>
						<input type="hidden" name="ailo_track_carrier_slug[]" value="<?php echo esc_attr( $slug ); ?>" />
						<input type="text" class="widefat" name="ailo_track_carrier_label[]"
							value="<?php echo esc_attr( $label ); ?>"
							placeholder="<?php esc_attr_e( 'e.g. National Post', 'ailo-order-tracking' ); ?>" />
					</td>
					<td>
						<input type="url" class="widefat" name="ailo_track_carrier_url[]"
							value="<?php echo esc_attr( $url ); ?>"
							placeholder="https://example.com/track?code={tracking}" />
					</td>
				</tr>
			<?php endfor; ?>
			</tbody>
		</table>
		<p class="description">
			<?php esc_html_e( 'Clear the name to remove a carrier. Orders already using it keep their tracking number.', 'ailo-order-tracking' ); ?>
		</p>
		<?php
	}
);

add_action(
	'woocommerce_update_options_shipping',
	static function () {
		global $current_section;
		if ( 'ailo_track' !== $current_section ) {
			return;
		}
		if (
			! isset( $_POST['ailo_track_settings_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ailo_track_settings_nonce'] ) ), 'ailo_track_settings' )
		) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// These three arrive as parallel arrays, one entry per carrier row, and
		// every element is sanitised inside the loop below: sanitize_text_field()
		// for the label, sanitize_key() for the slug, and sanitize_text_field()
		// plus ailo_track_valid_url_template() for the URL. The sniff reports the
		// assignment because it cannot follow sanitisation that happens per
		// element further down, so the ignore is on the assignment only.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$labels = isset( $_POST['ailo_track_carrier_label'] ) ? (array) wp_unslash( $_POST['ailo_track_carrier_label'] ) : array();
		$urls   = isset( $_POST['ailo_track_carrier_url'] ) ? (array) wp_unslash( $_POST['ailo_track_carrier_url'] ) : array();
		$slugs  = isset( $_POST['ailo_track_carrier_slug'] ) ? (array) wp_unslash( $_POST['ailo_track_carrier_slug'] ) : array();
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$out = array();
		foreach ( $labels as $i => $label ) {
			$label = sanitize_text_field( $label );
			if ( '' === $label ) {
				continue;
			}

			// Keep the existing slug when there is one, so orders that already
			// point at this carrier do not lose their link when the shop owner
			// renames it.
			$slug = isset( $slugs[ $i ] ) ? sanitize_key( $slugs[ $i ] ) : '';
			if ( '' === $slug ) {
				$slug = sanitize_key( sanitize_title( $label ) );
			}
			if ( '' === $slug || isset( $out[ $slug ] ) ) {
				$slug = $slug . '-' . ( (int) $i + 1 );
			}

			// Not esc_url_raw(): it strips { and }, so {tracking} would be
			// destroyed on save. Validate the shape, store the template as
			// typed, and escape at output time once the placeholder is gone.
			$url = isset( $urls[ $i ] ) ? trim( sanitize_text_field( $urls[ $i ] ) ) : '';
			if ( '' !== $url && ! ailo_track_valid_url_template( $url ) ) {
				$url = '';
				add_action(
					'admin_notices',
					static function () use ( $label ) {
						echo '<div class="notice notice-warning"><p>';
						printf(
							/* translators: %s: carrier name */
							esc_html__( 'The tracking URL for %s was not saved: it must start with http:// or https:// and include a domain.', 'ailo-order-tracking' ),
							esc_html( $label )
						);
						echo '</p></div>';
					}
				);
			}

			$out[ $slug ] = array(
				'label' => $label,
				'url'   => $url,
			);
		}

		update_option( AILO_TRACK_OPTION_CARRIERS, $out, false );
	}
);
