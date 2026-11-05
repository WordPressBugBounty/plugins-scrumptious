import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls, RichText } from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';
import metadata from './block.json';

function Edit( { attributes, setAttributes } ) {
	const { label, showPrint } = attributes;
	return (
		<div { ...useBlockProps( { className: 'swp-jump' } ) }>
			<InspectorControls>
				<PanelBody title={ __( 'Buttons', 'scrumptious' ) }>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Show "Print Recipe" button', 'scrumptious' ) }
						checked={ showPrint }
						onChange={ ( v ) => setAttributes( { showPrint: v } ) }
					/>
					<p className="swp-help">
						{ __( 'Jumps to the first recipe card in this post.', 'scrumptious' ) }
					</p>
				</PanelBody>
			</InspectorControls>
			<RichText
				tagName="span"
				className="swp-button swp-button--jump"
				value={ label }
				placeholder={ __( 'Jump to Recipe', 'scrumptious' ) }
				onChange={ ( v ) => setAttributes( { label: v } ) }
				allowedFormats={ [] }
			/>
			{ showPrint && (
				<span className="swp-button swp-button--print">{ __( 'Print Recipe', 'scrumptious' ) }</span>
			) }
		</div>
	);
}

registerBlockType( metadata.name, { edit: Edit, save: () => null } );
