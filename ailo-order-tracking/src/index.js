/**
 * Editor code for the Order Tracking Lookup block.
 *
 * The block is server-rendered (block.json "render": render.php), so the editor
 * shows a static, non-interactive preview rather than ServerSideRender. That is
 * a deliberate trade: ServerSideRender costs a REST round trip on every keystroke
 * in the sidebar and makes the editor feel slow, and this block has nothing
 * dynamic to preview — the form looks the same regardless of store data.
 *
 * @package AiloOrderTracking
 */

import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InspectorControls, RichText } from '@wordpress/block-editor';
import { PanelBody, SelectControl, ToggleControl, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';

registerBlockType( metadata.name, {
	edit( { attributes, setAttributes } ) {
		const { mode, heading, showCarrierLink, placeholderText } = attributes;
		const blockProps = useBlockProps( { className: 'ailo-track' } );

		const showTracking = mode === 'tracking' || mode === 'both';
		const showOrder = mode === 'order' || mode === 'both';

		return (
			<>
				<InspectorControls>
					<PanelBody title={ __( 'Lookup settings', 'ailo-order-tracking' ) }>
						<SelectControl
							label={ __( 'What customers can search by', 'ailo-order-tracking' ) }
							value={ mode }
							options={ [
								{ label: __( 'Tracking number only', 'ailo-order-tracking' ), value: 'tracking' },
								{ label: __( 'Order number + email or phone', 'ailo-order-tracking' ), value: 'order' },
								{ label: __( 'Both', 'ailo-order-tracking' ), value: 'both' },
							] }
							onChange={ ( value ) => setAttributes( { mode: value } ) }
							help={ __(
								'Looking up by order number always requires the email or phone on the order, so order numbers cannot be walked to harvest shipping data.',
								'ailo-order-tracking'
							) }
							__nextHasNoMarginBottom
						/>
						<ToggleControl
							label={ __( 'Link to the carrier page', 'ailo-order-tracking' ) }
							checked={ showCarrierLink }
							onChange={ ( value ) => setAttributes( { showCarrierLink: value } ) }
							help={ __(
								'Shown only for carriers that have a tracking URL configured.',
								'ailo-order-tracking'
							) }
							__nextHasNoMarginBottom
						/>
						<TextControl
							label={ __( 'Placeholder text', 'ailo-order-tracking' ) }
							value={ placeholderText }
							onChange={ ( value ) => setAttributes( { placeholderText: value } ) }
							placeholder={ __( 'e.g. ABC123456789', 'ailo-order-tracking' ) }
							__nextHasNoMarginBottom
						/>
					</PanelBody>
				</InspectorControls>

				<div { ...blockProps }>
					<RichText
						tagName="h2"
						className="ailo-track__heading"
						value={ heading }
						allowedFormats={ [] }
						onChange={ ( value ) => setAttributes( { heading: value } ) }
						placeholder={ __( 'Track your order', 'ailo-order-tracking' ) }
					/>

					{ showTracking && (
						<div className="ailo-track__field">
							<label className="ailo-track__label">
								{ __( 'Tracking number', 'ailo-order-tracking' ) }
							</label>
							<input
								type="text"
								className="ailo-track__input"
								disabled
								placeholder={ placeholderText || __( 'e.g. ABC123456789', 'ailo-order-tracking' ) }
							/>
						</div>
					) }

					{ showOrder && (
						<>
							<div className="ailo-track__field">
								<label className="ailo-track__label">
									{ __( 'Order number', 'ailo-order-tracking' ) }
								</label>
								<input type="text" className="ailo-track__input" disabled />
							</div>
							<div className="ailo-track__field">
								<label className="ailo-track__label">
									{ __( 'Email or phone on the order', 'ailo-order-tracking' ) }
								</label>
								<input type="text" className="ailo-track__input" disabled />
							</div>
						</>
					) }

					<button type="button" className="ailo-track__submit" disabled>
						{ __( 'Track', 'ailo-order-tracking' ) }
					</button>

					<p className="ailo-track__editor-note">
						{ __( 'Preview only — the form works on the published page.', 'ailo-order-tracking' ) }
					</p>
				</div>
			</>
		);
	},

	// Server-rendered: nothing is stored in post content beyond the attributes.
	save() {
		return null;
	},
} );
