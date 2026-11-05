import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl, RangeControl, ToggleControl } from '@wordpress/components';
import { useEntityRecords } from '@wordpress/core-data';
import ServerSideRender from '@wordpress/server-side-render';
import metadata from './block.json';

function TermSelect( { taxonomy, label, value, onChange } ) {
	const { records } = useEntityRecords( 'taxonomy', taxonomy, { per_page: 100, _fields: 'id,name' } );
	return (
		<SelectControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ label }
			value={ String( value || 0 ) }
			options={ [ { value: '0', label: __( 'All', 'scrumptious' ) }, ...( records || [] ).map( ( t ) => ( { value: String( t.id ), label: t.name } ) ) ] }
			onChange={ ( v ) => onChange( parseInt( v, 10 ) || 0 ) }
		/>
	);
}

function Edit( { attributes, setAttributes } ) {
	const { course, cuisine, diet, count, columns, orderBy, showRating, showTime } = attributes;
	const gap = <div style={ { height: 12 } } />;
	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody title={ __( 'Which recipes', 'scrumptious' ) }>
					<TermSelect taxonomy="scrumptious_course" label={ __( 'Course', 'scrumptious' ) } value={ course } onChange={ ( v ) => setAttributes( { course: v } ) } />
					{ gap }
					<TermSelect taxonomy="scrumptious_cuisine" label={ __( 'Cuisine', 'scrumptious' ) } value={ cuisine } onChange={ ( v ) => setAttributes( { cuisine: v } ) } />
					{ gap }
					<TermSelect taxonomy="scrumptious_diet" label={ __( 'Diet', 'scrumptious' ) } value={ diet } onChange={ ( v ) => setAttributes( { diet: v } ) } />
					{ gap }
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Order', 'scrumptious' ) }
						value={ orderBy }
						options={ [
							{ value: 'date', label: __( 'Newest first', 'scrumptious' ) },
							{ value: 'rating', label: __( 'Highest rated', 'scrumptious' ) },
							{ value: 'title', label: __( 'A → Z', 'scrumptious' ) },
							{ value: 'rand', label: __( 'Random', 'scrumptious' ) },
						] }
						onChange={ ( v ) => setAttributes( { orderBy: v } ) }
					/>
				</PanelBody>
				<PanelBody title={ __( 'Layout', 'scrumptious' ) }>
					<RangeControl __next40pxDefaultSize __nextHasNoMarginBottom label={ __( 'Number of recipes', 'scrumptious' ) } min={ 1 } max={ 24 } value={ count } onChange={ ( v ) => setAttributes( { count: v } ) } />
					<RangeControl __next40pxDefaultSize __nextHasNoMarginBottom label={ __( 'Columns', 'scrumptious' ) } min={ 1 } max={ 6 } value={ columns } onChange={ ( v ) => setAttributes( { columns: v } ) } />
					<ToggleControl __nextHasNoMarginBottom label={ __( 'Show rating', 'scrumptious' ) } checked={ showRating } onChange={ ( v ) => setAttributes( { showRating: v } ) } />
					<ToggleControl __nextHasNoMarginBottom label={ __( 'Show total time', 'scrumptious' ) } checked={ showTime } onChange={ ( v ) => setAttributes( { showTime: v } ) } />
				</PanelBody>
			</InspectorControls>
			<ServerSideRender block={ metadata.name } attributes={ attributes } />
		</div>
	);
}

registerBlockType( metadata.name, { edit: Edit, save: () => null } );
