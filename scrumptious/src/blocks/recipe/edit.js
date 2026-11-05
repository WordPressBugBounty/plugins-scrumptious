/**
 * Recipe block editor.
 *
 * The recipe lives in its own `scrumptious_recipe` post; this block stores only the
 * recipe ID. Edits are made inline on the canvas and auto-saved (debounced)
 * to the recipe post through core-data, so the user never sees a separate
 * "save recipe" step.
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	useBlockProps,
	InspectorControls,
	BlockControls,
	RichText,
	MediaUpload,
	MediaUploadCheck,
} from '@wordpress/block-editor';
import {
	Placeholder,
	Button,
	ToolbarGroup,
	ToolbarButton,
	PanelBody,
	TextControl,
	TextareaControl,
	ComboboxControl,
	FormTokenField,
	SelectControl,
	Spinner,
	Notice,
	__experimentalNumberControl as NumberControl,
} from '@wordpress/components';
import { useEntityRecord, useEntityRecords, store as coreStore } from '@wordpress/core-data';
import { useDispatch, useSelect } from '@wordpress/data';
import { useState, useEffect, useRef, useMemo, useCallback } from '@wordpress/element';
import ServerSideRender from '@wordpress/server-side-render';
import PasteParse, { hasContent } from './paste-parse';
import HelpLink from './help-link';
import TagSuggest from './tag-suggest';

const META = '_scrumptious_recipe';
const TYPE = 'scrumptious_recipe';
const SAVE_DELAY = 1200;

const EMPTY = {
	v: 1,
	summary: '',
	author_name: '',
	servings: 0,
	servings_unit: '',
	times: { prep: 0, cook: 0, rest: 0, total: 0 },
	ingredient_groups: [ { title: '', items: [] } ],
	instruction_groups: [ { title: '', steps: [] } ],
	notes: '',
	video_url: '',
	keywords: '',
	nutrition: {},
	gallery: [],
	source_url: '',
	source_name: '',
};

const NUTRITION = [
	[ 'serving_size', __( 'Serving size', 'scrumptious' ), '' ],
	[ 'calories', __( 'Calories', 'scrumptious' ), 'kcal' ],
	[ 'carbohydrates', __( 'Carbohydrates', 'scrumptious' ), 'g' ],
	[ 'protein', __( 'Protein', 'scrumptious' ), 'g' ],
	[ 'fat', __( 'Fat', 'scrumptious' ), 'g' ],
	[ 'saturated_fat', __( 'Saturated fat', 'scrumptious' ), 'g' ],
	[ 'fiber', __( 'Fiber', 'scrumptious' ), 'g' ],
	[ 'sugar', __( 'Sugar', 'scrumptious' ), 'g' ],
	[ 'sodium', __( 'Sodium', 'scrumptious' ), 'mg' ],
	[ 'cholesterol', __( 'Cholesterol', 'scrumptious' ), 'mg' ],
];

function parseMeta( value ) {
	if ( ! value ) {
		return { ...EMPTY };
	}
	try {
		const data = JSON.parse( value );
		return { ...EMPTY, ...data, times: { ...EMPTY.times, ...( data.times || {} ) } };
	} catch ( e ) {
		return { ...EMPTY };
	}
}

const linesOf = ( text ) =>
	text
		.split( /\r?\n/ )
		.map( ( l ) => l.trim() )
		.filter( Boolean );

/**
 * Keep each step's extra data (photo, timer) with the right step when the text changes:
 * unchanged lines match by text, so adding or removing a step above one with a photo
 * doesn't move the photo. Edited lines keep their position's data when the count is unchanged.
 *
 * @param {Array}    prev  Existing steps.
 * @param {string[]} lines New step texts.
 * @return {Array} Steps.
 */
export function matchSteps( prev, lines ) {
	const used = new Set();
	const picks = lines.map( ( line ) => {
		const i = prev.findIndex( ( p, idx ) => ! used.has( idx ) && p.text === line );
		if ( i >= 0 ) {
			used.add( i );
		}
		return i;
	} );
	return lines.map( ( line, si ) => {
		let i = picks[ si ];
		if ( i < 0 && prev.length === lines.length && ! used.has( si ) ) {
			i = si;
			used.add( si );
		}
		return i >= 0 ? { ...prev[ i ], text: line } : { text: line };
	} );
}

/* ---------- Gallery (sidebar) ---------- */

function GalleryThumb( { id, onRemove } ) {
	const media = useSelect( ( select ) => select( coreStore ).getMedia( id, { context: 'view' } ), [ id ] );
	const src = media?.media_details?.sizes?.thumbnail?.source_url || media?.source_url;
	return (
		<li className="swp-gallery-edit__item">
			{ src ? <img src={ src } alt={ media?.alt_text || '' } /> : <Spinner /> }
			<Button
				className="swp-gallery-edit__remove"
				icon="no-alt"
				label={ __( 'Remove from gallery', 'scrumptious' ) }
				onClick={ onRemove }
				size="small"
			/>
		</li>
	);
}

function GalleryPanel( { ids, onChange } ) {
	return (
		<PanelBody title={ __( 'Gallery', 'scrumptious' ) } initialOpen={ ids.length > 0 }>
			{ ids.length > 0 && (
				<ul className="swp-gallery-edit">
					{ ids.map( ( id ) => (
						<GalleryThumb key={ id } id={ id } onRemove={ () => onChange( ids.filter( ( x ) => x !== id ) ) } />
					) ) }
				</ul>
			) }
			<MediaUploadCheck>
				<MediaUpload
					allowedTypes={ [ 'image' ] }
					multiple
					gallery
					value={ ids }
					onSelect={ ( media ) => onChange( ( media || [] ).map( ( m ) => m.id ) ) }
					render={ ( { open } ) => (
						<Button variant="secondary" onClick={ open }>
							{ ids.length ? __( 'Edit gallery', 'scrumptious' ) : __( 'Add photos', 'scrumptious' ) }
						</Button>
					) }
				/>
			</MediaUploadCheck>
			<p className="swp-help">
				{ __( 'Extra photos of the dish or the steps. They show as a gallery on the card (readers can tap to enlarge), and the first five are added to Google recipe data. Drag to reorder in Edit gallery.', 'scrumptious' ) }{ ' ' }
				<HelpLink slug="gallery" />
			</p>
		</PanelBody>
	);
}

/* ---------- Placeholder: create or pick a recipe ---------- */

function PickRecipe( { onSelect } ) {
	const [ search, setSearch ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const { saveEntityRecord } = useDispatch( coreStore );
	const { records, isResolving } = useEntityRecords( 'postType', TYPE, {
		per_page: 20,
		search: search || undefined,
		status: 'publish,draft',
		_fields: 'id,title',
	} );

	const create = async () => {
		setBusy( true );
		setError( '' );
		try {
			const rec = await saveEntityRecord(
				'postType',
				TYPE,
				{ title: __( 'Untitled recipe', 'scrumptious' ), status: 'draft' }, // Published with the post (Usage::publish_recipes).
				{ throwOnError: true }
			);
			onSelect( rec.id );
		} catch ( e ) {
			setError( e?.message || __( 'Could not create the recipe.', 'scrumptious' ) );
		}
		setBusy( false );
	};

	const options = ( records || [] ).map( ( r ) => ( {
		value: String( r.id ),
		label: r.title?.rendered || r.title?.raw || `#${ r.id }`,
	} ) );

	return (
		<Placeholder
			icon="carrot"
			label={ __( 'Recipe Card', 'scrumptious' ) }
			instructions={ __( 'Create a new recipe, or insert one you already made.', 'scrumptious' ) }
		>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			<div className="swp-pick">
				<Button variant="primary" onClick={ create } isBusy={ busy } disabled={ busy }>
					{ __( 'Create new recipe', 'scrumptious' ) }
				</Button>
				<ComboboxControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Or insert an existing recipe', 'scrumptious' ) }
					value={ null }
					options={ options }
					onFilterValueChange={ setSearch }
					onChange={ ( v ) => v && onSelect( parseInt( v, 10 ) ) }
					help={ isResolving ? __( 'Searching…', 'scrumptious' ) : undefined }
				/>
			</div>
		</Placeholder>
	);
}

/* ---------- Line-per-item textarea that commits on blur ---------- */

function LinesField( { label, help, value, onCommit, rows = 6, placeholder } ) {
	const [ draft, setDraft ] = useState( value );
	const focused = useRef( false );

	// Pick up server-side changes (e.g. after parse) when not typing.
	useEffect( () => {
		if ( ! focused.current ) {
			setDraft( value );
		}
	}, [ value ] );

	return (
		<TextareaControl
			__nextHasNoMarginBottom
			label={ label }
			help={ help }
			value={ draft }
			rows={ rows }
			placeholder={ placeholder }
			onFocus={ () => ( focused.current = true ) }
			onChange={ setDraft }
			onBlur={ () => {
				focused.current = false;
				if ( draft !== value ) {
					onCommit( draft );
				}
			} }
		/>
	);
}

/* ---------- Parsed ingredient preview ---------- */

function ParsedPreview( { items } ) {
	if ( ! items?.length ) {
		return null;
	}
	return (
		<ul className="swp-parsed" aria-label={ __( 'How we read your ingredients', 'scrumptious' ) }>
			{ items.map( ( it, i ) => (
				<li key={ i }>
					{ it.amount && <span className="swp-parsed__amount">{ it.amount }</span> }
					{ it.unit && <span className="swp-parsed__unit">{ it.unit }</span> }
					<span className="swp-parsed__name">{ it.name || it.raw }</span>
					{ it.notes && <span className="swp-parsed__notes">{ it.notes }</span> }
				</li>
			) ) }
		</ul>
	);
}

/* ---------- Course / cuisine / diet pickers (used inside posts) ---------- */

const TAXONOMIES = [
	[ 'scrumptious_course', __( 'Course', 'scrumptious' ), __( 'e.g. Dessert, Dinner', 'scrumptious' ) ],
	[ 'scrumptious_cuisine', __( 'Cuisine', 'scrumptious' ), __( 'e.g. Italian, Mexican', 'scrumptious' ) ],
	[ 'scrumptious_diet', __( 'Diet', 'scrumptious' ), __( 'e.g. Vegan, Gluten-free', 'scrumptious' ) ],
];

function TermsField( { taxonomy, label, help, value, onChange } ) {
	const { saveEntityRecord } = useDispatch( coreStore );
	const { records } = useEntityRecords( 'taxonomy', taxonomy, { per_page: 100, _fields: 'id,name' } );
	const byId = new Map( ( records || [] ).map( ( t ) => [ t.id, t.name ] ) );
	const names = ( value || [] ).map( ( id ) => byId.get( id ) ).filter( Boolean );

	const change = async ( tokens ) => {
		const ids = [];
		for ( const token of tokens ) {
			const name = typeof token === 'string' ? token.trim() : token.value;
			if ( ! name ) {
				continue;
			}
			const found = ( records || [] ).find( ( t ) => t.name.toLowerCase() === name.toLowerCase() );
			if ( found ) {
				ids.push( found.id );
			} else {
				const created = await saveEntityRecord( 'taxonomy', taxonomy, { name }, { throwOnError: false } );
				if ( created?.id ) {
					ids.push( created.id );
				}
			}
		}
		onChange( [ ...new Set( ids ) ] );
	};

	return (
		<FormTokenField
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ label }
			value={ names }
			suggestions={ ( records || [] ).map( ( t ) => t.name ) }
			onChange={ change }
			__experimentalShowHowTo={ false }
			placeholder={ help }
		/>
	);
}

/* ---------- Main editor ---------- */

function RecipeEditor( { recipeId, bound = false } ) {
	const { record, editedRecord, edit, hasResolved } = useEntityRecord( 'postType', TYPE, recipeId );
	const { saveEditedEntityRecord } = useDispatch( coreStore );
	const { isSaving, hasEdits, lastError } = useSelect(
		( select ) => {
			const core = select( coreStore );
			return {
				isSaving: core.isSavingEntityRecord( 'postType', TYPE, recipeId ),
				hasEdits: core.hasEditsForEntityRecord( 'postType', TYPE, recipeId ),
				lastError: core.getLastEntitySaveError( 'postType', TYPE, recipeId ),
			};
		},
		[ recipeId ]
	);

	const data = useMemo( () => parseMeta( editedRecord?.meta?.[ META ] ), [ editedRecord?.meta?.[ META ] ] );

	// Debounced autosave of the recipe post.
	const timer = useRef();
	useEffect( () => {
		if ( bound || ! hasEdits ) {
			return undefined;
		}
		clearTimeout( timer.current );
		timer.current = setTimeout( () => saveEditedEntityRecord( 'postType', TYPE, recipeId ), SAVE_DELAY );
		return () => clearTimeout( timer.current );
	}, [ hasEdits, editedRecord, recipeId, saveEditedEntityRecord ] );

	// Always work from the latest data: a text box that commits on blur and a button click
	// in the same moment must not overwrite each other.
	const dataRef = useRef( data );
	dataRef.current = data;
	const metaRef = useRef( editedRecord?.meta );
	metaRef.current = editedRecord?.meta;

	const update = useCallback(
		( patch ) => {
			const next = { ...dataRef.current, ...patch };
			dataRef.current = next;
			edit( { meta: { ...( metaRef.current || {} ), [ META ]: JSON.stringify( next ) } } );
		},
		[ edit ]
	);

	const setTime = ( key, val ) => {
		const times = { ...dataRef.current.times, [ key ]: parseInt( val, 10 ) || 0, total: 0 };
		update( { times } );
	};

	const CHILD = { ingredient_groups: 'items', instruction_groups: 'steps' };
	// There is always at least one group to type into.
	const groupsOf = ( source, listKey ) =>
		source[ listKey ]?.length ? source[ listKey ] : [ { title: '', [ CHILD[ listKey ] ]: [] } ];

	const setGroup = ( listKey, index, patch ) => {
		const groups = [ ...groupsOf( dataRef.current, listKey ) ];
		groups[ index ] = { ...groups[ index ], ...patch };
		update( { [ listKey ]: groups } );
	};

	const addGroup = ( listKey ) =>
		update( {
			[ listKey ]: [ ...groupsOf( dataRef.current, listKey ), { title: '', [ CHILD[ listKey ] ]: [] } ],
		} );

	const removeGroup = ( listKey, index ) =>
		update( { [ listKey ]: groupsOf( dataRef.current, listKey ).filter( ( _g, i ) => i !== index ) } );

	const [ parseNotice, setParseNotice ] = useState( null );
	const applyParsed = ( res ) => {
		const r = res.recipe || {};
		const current = dataRef.current;
		const nutrition = { ...( current.nutrition || {} ) };
		Object.entries( r.nutrition || {} ).forEach( ( [ k, v ] ) => {
			if ( v !== '' && v !== null ) {
				nutrition[ k ] = v;
			}
		} );
		update( {
			summary: r.summary || current.summary,
			servings: r.servings || current.servings,
			servings_unit: r.servings_unit || current.servings_unit,
			times: r.times || current.times,
			ingredient_groups: r.ingredient_groups?.length ? r.ingredient_groups : current.ingredient_groups,
			instruction_groups: r.instruction_groups?.length ? r.instruction_groups : current.instruction_groups,
			notes: r.notes || current.notes,
			keywords: r.keywords || current.keywords,
			video_url: r.video_url || current.video_url,
			source_url: r.source_url || current.source_url || '',
			source_name: r.source_name || current.source_name || '',
			nutrition,
		} );
		// Photo and course/cuisine/diet, when the response has them.
		const extra = {};
		if ( res.image_id && ! editedRecord.featured_media ) {
			extra.featured_media = res.image_id;
		}
		Object.entries( res.terms || {} ).forEach( ( [ tax, ids ] ) => {
			const existing = Array.isArray( editedRecord[ tax ] ) ? editedRecord[ tax ] : [];
			extra[ tax ] = [ ...new Set( [ ...existing, ...ids ] ) ];
		} );
		if ( Object.keys( extra ).length ) {
			edit( extra );
		}
		const title = editedRecord.title?.raw ?? editedRecord.title ?? '';
		if ( res.title && ( ! title || /^untitled recipe$/i.test( title ) ) ) {
			edit( { title: res.title } );
		}
		setParseNotice(
			res.notice
				? { status: 'warning', text: res.notice }
				: {
						status: 'success',
						text:
							res.source === 'ai'
								? __( 'Recipe filled in by AI. Give it a quick check.', 'scrumptious' )
								: res.source === 'schema'
								? __( 'Recipe imported from the page. Give it a quick check.', 'scrumptious' )
								: __( 'Recipe filled in. Give it a quick check.', 'scrumptious' ),
				  }
		);
	};

	const ingredientGroups = groupsOf( data, 'ingredient_groups' );
	const instructionGroups = groupsOf( data, 'instruction_groups' );

	if ( ! hasResolved ) {
		return <Spinner />;
	}
	if ( ! record ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ __( 'This recipe no longer exists or you do not have permission to edit it.', 'scrumptious' ) }
			</Notice>
		);
	}

	const status = bound
		? ''
		: isSaving
		? __( 'Saving…', 'scrumptious' )
		: hasEdits
		? __( 'Unsaved changes', 'scrumptious' )
		: __( 'All changes saved', 'scrumptious' );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Recipe image', 'scrumptious' ) }>
					<MediaUploadCheck>
						<MediaUpload
							allowedTypes={ [ 'image' ] }
							value={ editedRecord.featured_media }
							onSelect={ ( m ) => edit( { featured_media: m.id } ) }
							render={ ( { open } ) => (
								<>
									<Button variant="secondary" onClick={ open }>
										{ editedRecord.featured_media
											? __( 'Replace image', 'scrumptious' )
											: __( 'Choose image', 'scrumptious' ) }
									</Button>
									{ !! editedRecord.featured_media && (
										<Button variant="link" isDestructive onClick={ () => edit( { featured_media: 0 } ) }>
											{ __( 'Remove', 'scrumptious' ) }
										</Button>
									) }
								</>
							) }
						/>
					</MediaUploadCheck>
					<p className="swp-help">
						{ __( 'Google requires an image. Use a large photo (at least 1200px wide); we create square, 4:3 and 16:9 crops automatically.', 'scrumptious' ) }{ ' ' }
						<HelpLink slug="images" />
					</p>
				</PanelBody>
				<GalleryPanel ids={ data.gallery || [] } onChange={ ( gallery ) => update( { gallery } ) } />
				<PanelBody title={ __( 'Course, cuisine & diet', 'scrumptious' ) } initialOpen={ ! bound }>
					{ bound && (
						<p className="swp-help">
							{ __( 'Set these in the Courses, Cuisines and Diets panels of the Recipe sidebar, or let AI suggest them.', 'scrumptious' ) }{ ' ' }
							<HelpLink slug="taxonomies" />
						</p>
					) }
				{ ! bound && (
					<>
						{ TAXONOMIES.map( ( [ tax, label, help ] ) => (
							<div key={ tax } style={ { marginBottom: 16 } }>
								<TermsField
									taxonomy={ tax }
									label={ label }
									help={ help }
									value={ editedRecord[ tax ] }
									onChange={ ( ids ) => edit( { [ tax ]: ids } ) }
								/>
							</div>
						) ) }
						<p className="swp-help">
							{ __( 'Used for Google recipe data and your recipe index. Press Enter after each one.', 'scrumptious' ) }{ ' ' }
							<HelpLink slug="taxonomies" />
						</p>
					</>
				) }
					<TagSuggest
						recipeId={ recipeId }
						title={ editedRecord.title?.raw ?? editedRecord.title ?? '' }
						data={ data }
						current={ {
							scrumptious_course: editedRecord.scrumptious_course || [],
							scrumptious_cuisine: editedRecord.scrumptious_cuisine || [],
							scrumptious_diet: editedRecord.scrumptious_diet || [],
						} }
						onChange={ ( terms ) => edit( terms ) }
					/>
				</PanelBody>
				<PanelBody title={ __( 'Details', 'scrumptious' ) } initialOpen={ false }>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Keywords', 'scrumptious' ) }
						help={ __( 'Comma-separated, e.g. "weeknight, one-pot".', 'scrumptious' ) }
						value={ data.keywords }
						onChange={ ( keywords ) => update( { keywords } ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Video URL', 'scrumptious' ) }
						type="url"
						value={ data.video_url }
						onChange={ ( video_url ) => update( { video_url } ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Author name (optional)', 'scrumptious' ) }
						help={ __( 'Leave empty to use the recipe post author.', 'scrumptious' ) }
						value={ data.author_name }
						onChange={ ( author_name ) => update( { author_name } ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Adapted from (link)', 'scrumptious' ) }
						help={ __( 'Credit the original recipe. Shows as "Adapted from …" at the end of the card.', 'scrumptious' ) }
						type="url"
						value={ data.source_url || '' }
						onChange={ ( source_url ) => update( { source_url } ) }
					/>
					{ !! data.source_url && (
						<TextControl
							__nextHasNoMarginBottom
							label={ __( 'Adapted from (name)', 'scrumptious' ) }
							help={ __( 'e.g. the site or cookbook name. Leave empty to show the web address.', 'scrumptious' ) }
							value={ data.source_name || '' }
							onChange={ ( source_name ) => update( { source_name } ) }
						/>
					) }
				</PanelBody>
				<PanelBody title={ __( 'Nutrition (per serving)', 'scrumptious' ) } initialOpen={ false }>
					<p className="swp-help">
						{ __( 'Numbers only; units are added for you.', 'scrumptious' ) } <HelpLink slug="nutrition" />
					</p>
					{ NUTRITION.map( ( [ key, label, unit ] ) => (
						<TextControl
							__nextHasNoMarginBottom
							key={ key }
							label={ unit ? `${ label } (${ unit })` : label }
							value={ data.nutrition?.[ key ] || '' }
							onChange={ ( v ) => update( { nutrition: { ...data.nutrition, [ key ]: v } } ) }
						/>
					) ) }
				</PanelBody>
			</InspectorControls>

			<div className="swp-editor">
				{ ! hasContent( data ) && (
					<div className="swp-editor__start">
						<strong>{ __( 'Have this recipe written down somewhere?', 'scrumptious' ) }</strong>{ ' ' }
						{ __( 'Paste it and we will fill everything in.', 'scrumptious' ) }
						<PasteParse data={ data } onApply={ applyParsed } variant="primary" />
					</div>
				) }
				{ parseNotice && (
					<Notice status={ parseNotice.status } onRemove={ () => setParseNotice( null ) }>
						{ parseNotice.text }
					</Notice>
				) }
				<div className="swp-editor__status" aria-live="polite">
					{ hasContent( data ) && (
						<span className="swp-editor__tools">
							<PasteParse data={ data } onApply={ applyParsed } variant="link" />
						</span>
					) }
					{ status }
					{ lastError && ! isSaving && (
						<span className="swp-editor__error">
							{ sprintf(
								/* translators: %s: error message */
								__( 'Save failed: %s', 'scrumptious' ),
								lastError.message
							) }
						</span>
					) }
				</div>

				{ ! bound && ( <RichText
					tagName="h2"
					className="swp-editor__title"
					value={ editedRecord.title?.raw ?? editedRecord.title ?? '' }
					onChange={ ( title ) => edit( { title } ) }
					placeholder={ __( 'Recipe name', 'scrumptious' ) }
					allowedFormats={ [] }
				/> ) }
				<RichText
					tagName="p"
					className="swp-editor__summary"
					value={ data.summary }
					onChange={ ( summary ) => update( { summary } ) }
					placeholder={ __( 'A short, appetizing description…', 'scrumptious' ) }
					allowedFormats={ [ 'core/bold', 'core/italic', 'core/link' ] }
				/>

				<div className="swp-editor__meta">
					{ [
						[ 'prep', __( 'Prep (min)', 'scrumptious' ) ],
						[ 'cook', __( 'Cook (min)', 'scrumptious' ) ],
						[ 'rest', __( 'Rest (min)', 'scrumptious' ) ],
					].map( ( [ key, label ] ) => (
						<NumberControl
							key={ key }
							label={ label }
							min={ 0 }
							value={ data.times[ key ] || '' }
							onChange={ ( v ) => setTime( key, v ) }
						/>
					) ) }
					<NumberControl
						label={ __( 'Servings', 'scrumptious' ) }
						min={ 0 }
						step="any"
						value={ data.servings || '' }
						onChange={ ( v ) => update( { servings: parseFloat( v ) || 0 } ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Unit', 'scrumptious' ) }
						placeholder={ __( 'e.g. cookies', 'scrumptious' ) }
						value={ data.servings_unit }
						onChange={ ( servings_unit ) => update( { servings_unit } ) }
					/>
				</div>

				<h3 className="swp-editor__section">{ __( 'Ingredients', 'scrumptious' ) }</h3>
				{ ingredientGroups.map( ( group, gi ) => (
					<div className="swp-editor__group" key={ gi }>
						{ ( ingredientGroups.length > 1 || group.title ) && (
							<TextControl
								__nextHasNoMarginBottom
								label={ __( 'Group name', 'scrumptious' ) }
								placeholder={ __( 'e.g. For the sauce', 'scrumptious' ) }
								value={ group.title }
								onChange={ ( title ) => setGroup( 'ingredient_groups', gi, { title } ) }
							/>
						) }
						<LinesField
							label={ __( 'One ingredient per line', 'scrumptious' ) }
							help={ __( 'Paste your list — we split amount, unit and notes automatically.', 'scrumptious' ) }
							placeholder={ '2 1/4 cups all-purpose flour\n1 tsp baking soda\n1 cup butter, softened' }
							value={ ( group.items || [] ).map( ( it ) => it.raw ).join( '\n' ) }
							onCommit={ ( text ) =>
								setGroup( 'ingredient_groups', gi, { items: linesOf( text ).map( ( raw ) => ( { raw } ) ) } )
							}
						/>
						<ParsedPreview items={ group.items?.filter( ( it ) => it.name ) } />
						{ ingredientGroups.length > 1 && (
							<Button variant="link" isDestructive onClick={ () => removeGroup( 'ingredient_groups', gi ) }>
								{ __( 'Remove group', 'scrumptious' ) }
							</Button>
						) }
					</div>
				) ) }
				<Button variant="secondary" onClick={ () => addGroup( 'ingredient_groups' ) }>
					{ __( '+ Add ingredient group', 'scrumptious' ) }
				</Button>

				<h3 className="swp-editor__section">{ __( 'Instructions', 'scrumptious' ) }</h3>
				{ instructionGroups.map( ( group, gi ) => (
					<div className="swp-editor__group" key={ gi }>
						{ ( instructionGroups.length > 1 || group.title ) && (
							<TextControl
								__nextHasNoMarginBottom
								label={ __( 'Group name', 'scrumptious' ) }
								placeholder={ __( 'e.g. Make the dough', 'scrumptious' ) }
								value={ group.title }
								onChange={ ( title ) => setGroup( 'instruction_groups', gi, { title } ) }
							/>
						) }
						<LinesField
							label={ __( 'One step per line', 'scrumptious' ) }
							rows={ 8 }
							placeholder={ __( 'Preheat the oven to 375°F.\nWhisk the flour and baking soda.', 'scrumptious' ) }
							value={ ( group.steps || [] ).map( ( s ) => s.text ).join( '\n' ) }
							onCommit={ ( text ) =>
								setGroup( 'instruction_groups', gi, {
									steps: matchSteps( groupsOf( dataRef.current, 'instruction_groups' )[ gi ]?.steps || [], linesOf( text ) ),
								} )
							}
						/>
						{ instructionGroups.length > 1 && (
							<Button variant="link" isDestructive onClick={ () => removeGroup( 'instruction_groups', gi ) }>
								{ __( 'Remove group', 'scrumptious' ) }
							</Button>
						) }
					</div>
				) ) }
				<Button variant="secondary" onClick={ () => addGroup( 'instruction_groups' ) }>
					{ __( '+ Add instruction group', 'scrumptious' ) }
				</Button>

				<h3 className="swp-editor__section">{ __( 'Notes', 'scrumptious' ) }</h3>
				<LinesField
					label={ __( 'Tips, substitutions, storage', 'scrumptious' ) }
					rows={ 4 }
					value={ data.notes }
					onCommit={ ( notes ) => update( { notes } ) }
				/>
			</div>
		</>
	);
}

/* ---------- Per-block display options ---------- */

const SECTION_LABELS = [
	[ 'image', __( 'Image', 'scrumptious' ) ],
	[ 'summary', __( 'Summary', 'scrumptious' ) ],
	[ 'times', __( 'Times & servings', 'scrumptious' ) ],
	[ 'share', __( 'Share buttons', 'scrumptious' ) ],
	[ 'gallery', __( 'Gallery', 'scrumptious' ) ],
	[ 'notes', __( 'Notes', 'scrumptious' ) ],
	[ 'nutrition', __( 'Nutrition', 'scrumptious' ) ],
];

function DisplayPanel( { attributes, setAttributes } ) {
	const { cardStyle, sections = {}, showPrint } = attributes;
	const tri = [
		{ label: __( 'Site default', 'scrumptious' ), value: 'default' },
		{ label: __( 'Show', 'scrumptious' ), value: 'show' },
		{ label: __( 'Hide', 'scrumptious' ), value: 'hide' },
	];
	return (
		<PanelBody title={ __( 'Card display', 'scrumptious' ) }>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Style', 'scrumptious' ) }
				value={ cardStyle }
				options={ [
					{ label: __( 'Site default', 'scrumptious' ), value: '' },
					{ label: __( 'Full', 'scrumptious' ), value: 'full' },
					{ label: __( 'Compact (ingredients beside steps)', 'scrumptious' ), value: 'compact' },
					{ label: __( 'Minimal (no box)', 'scrumptious' ), value: 'minimal' },
				] }
				onChange={ ( v ) => setAttributes( { cardStyle: v } ) }
			/>
			<div style={ { height: 12 } } />
			{ SECTION_LABELS.map( ( [ key, label ] ) => (
				<div key={ key } style={ { marginBottom: 8 } }>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ label }
						value={ sections[ key ] || 'default' }
						options={ tri }
						onChange={ ( v ) => setAttributes( { sections: { ...sections, [ key ]: v } } ) }
					/>
				</div>
			) ) }
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Print button', 'scrumptious' ) }
				value={ showPrint || 'default' }
				options={ tri }
				onChange={ ( v ) => setAttributes( { showPrint: v } ) }
			/>
			<p className="swp-help">
				{ __( '"Site default" follows Recipes → Settings. Use Preview in the toolbar to see the result.', 'scrumptious' ) }{ ' ' }
				<HelpLink slug="recipe-card-block" />
			</p>
		</PanelBody>
	);
}

export default function Edit( { attributes, setAttributes, context } ) {
	// On the recipe's own edit screen the block binds to that post.
	const bound = context?.postType === TYPE && !! context?.postId;
	const recipeId = bound ? context.postId : attributes.recipeId;
	const [ preview, setPreview ] = useState( false );
	const blockProps = useBlockProps( { className: 'swp-block' } );

	return (
		<div { ...blockProps }>
			{ !! recipeId && ! bound && (
				<InspectorControls group="settings">
					<DisplayPanel attributes={ attributes } setAttributes={ setAttributes } />
				</InspectorControls>
			) }
			{ !! recipeId && ! bound && (
				<BlockControls>
					<ToolbarGroup>
						<ToolbarButton isPressed={ ! preview } onClick={ () => setPreview( false ) }>
							{ __( 'Edit', 'scrumptious' ) }
						</ToolbarButton>
						<ToolbarButton isPressed={ preview } onClick={ () => setPreview( true ) }>
							{ __( 'Preview', 'scrumptious' ) }
						</ToolbarButton>
					</ToolbarGroup>
					<ToolbarGroup>
						<ToolbarButton onClick={ () => setAttributes( { recipeId: 0 } ) }>
							{ __( 'Change recipe', 'scrumptious' ) }
						</ToolbarButton>
					</ToolbarGroup>
				</BlockControls>
			) }
			{ ! recipeId && <PickRecipe onSelect={ ( id ) => setAttributes( { recipeId: id } ) } /> }
			{ !! recipeId && preview && (
				<ServerSideRender block="scrumptious/recipe" attributes={ { recipeId, cardStyle: attributes.cardStyle, sections: attributes.sections, showPrint: attributes.showPrint } } />
			) }
			{ !! recipeId && ( bound || ! preview ) && <RecipeEditor recipeId={ recipeId } bound={ bound } /> }
		</div>
	);
}
