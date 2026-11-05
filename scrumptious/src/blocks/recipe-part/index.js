import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl, ComboboxControl, Placeholder } from '@wordpress/components';
import { useEntityRecords } from '@wordpress/core-data';
import { useState } from '@wordpress/element';
import ServerSideRender from '@wordpress/server-side-render';
import metadata from './block.json';

const PARTS = [
	[ 'ingredients', __( 'Recipe Ingredients', 'scrumptious' ), 'editor-ul', __( 'Ingredient list with scaling and unit conversion.', 'scrumptious' ) ],
	[ 'instructions', __( 'Recipe Instructions', 'scrumptious' ), 'editor-ol', __( 'Numbered steps.', 'scrumptious' ) ],
	[ 'meta', __( 'Recipe Times & Servings', 'scrumptious' ), 'clock', __( 'Prep, cook, total time and servings.', 'scrumptious' ) ],
	[ 'rating', __( 'Recipe Rating', 'scrumptious' ), 'star-filled', __( 'Star rating readers can use.', 'scrumptious' ) ],
	[ 'nutrition', __( 'Recipe Nutrition', 'scrumptious' ), 'heart', __( 'Nutrition per serving.', 'scrumptious' ) ],
	[ 'notes', __( 'Recipe Notes', 'scrumptious' ), 'edit', __( 'Tips and substitutions.', 'scrumptious' ) ],
	[ 'summary', __( 'Recipe Summary', 'scrumptious' ), 'text', __( 'Short description.', 'scrumptious' ) ],
	[ 'gallery', __( 'Recipe Gallery', 'scrumptious' ), 'format-gallery', __( 'Photo gallery with a tap-to-enlarge lightbox.', 'scrumptious' ) ],
	[ 'share', __( 'Recipe Share Buttons', 'scrumptious' ), 'share', __( 'Pinterest, Facebook, WhatsApp, email and copy-link buttons.', 'scrumptious' ) ],
	[ 'actions', __( 'Recipe Buttons', 'scrumptious' ), 'printer', __( 'Print and cook mode buttons.', 'scrumptious' ) ],
];

function RecipePicker( { value, onChange } ) {
	const [ search, setSearch ] = useState( '' );
	const { records } = useEntityRecords( 'postType', 'scrumptious_recipe', {
		per_page: 20,
		search: search || undefined,
		_fields: 'id,title',
	} );
	return (
		<ComboboxControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ __( 'Recipe', 'scrumptious' ) }
			help={ __( 'Leave empty to use the current recipe (on recipe templates) or the first recipe in this post.', 'scrumptious' ) }
			value={ value ? String( value ) : null }
			options={ ( records || [] ).map( ( r ) => ( { value: String( r.id ), label: r.title?.rendered || `#${ r.id }` } ) ) }
			onFilterValueChange={ setSearch }
			onChange={ ( v ) => onChange( v ? parseInt( v, 10 ) : 0 ) }
		/>
	);
}

function Edit( { attributes, setAttributes, context } ) {
	const { part, recipeId } = attributes;
	const fromContext = context?.postType === 'scrumptious_recipe' ? context.postId : 0;
	const id = recipeId || fromContext;
	const meta = PARTS.find( ( p ) => p[ 0 ] === part ) || PARTS[ 0 ];
	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody title={ __( 'Recipe part', 'scrumptious' ) }>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Show', 'scrumptious' ) }
						value={ part }
						options={ PARTS.map( ( p ) => ( { value: p[ 0 ], label: p[ 1 ] } ) ) }
						onChange={ ( v ) => setAttributes( { part: v } ) }
					/>
					<div style={ { height: 12 } } />
					<RecipePicker value={ recipeId } onChange={ ( v ) => setAttributes( { recipeId: v } ) } />
				</PanelBody>
			</InspectorControls>
			{ id ? (
				<ServerSideRender block={ metadata.name } attributes={ { part, recipeId: id } } />
			) : (
				<Placeholder icon={ meta[ 2 ] } label={ meta[ 1 ] } instructions={ __( 'Shows this part of the current recipe. Pick a recipe in the sidebar to preview it here.', 'scrumptious' ) } />
			) }
		</div>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
	variations: PARTS.map( ( [ name, title, icon, description ], i ) => ( {
		name,
		title,
		icon,
		description,
		isDefault: i === 0,
		attributes: { part: name },
		scope: [ 'inserter', 'transform' ],
		isActive: ( attrs ) => attrs.part === name,
	} ) ),
} );
