/**
 * "Suggest with AI" for course, cuisine and diet.
 *
 * Asks the server for suggestions (matched to the site's existing terms),
 * shows them as chips, and adds the ones the user picks to the recipe.
 */
import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import ProHint from './pro-hint';

const config = () => window.scrumptiousEditor || { ai: false, settingsUrl: '' };

const LABELS = {
	scrumptious_course: __( 'Course', 'scrumptious' ),
	scrumptious_cuisine: __( 'Cuisine', 'scrumptious' ),
	scrumptious_diet: __( 'Diet', 'scrumptious' ),
};

export default function TagSuggest( { recipeId, title, data, current, onChange } ) {
	const { saveEntityRecord } = useDispatch( coreStore );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ found, setFound ] = useState( null );

	if ( ! config().ai ) {
		return (
			<p className="swp-help">
				{ __( 'Want course, cuisine and diet filled in for you?', 'scrumptious' ) }{ ' ' }
				<a href={ config().settingsUrl }>{ __( 'Set up AI', 'scrumptious' ) }</a>
			</p>
		);
	}

	const has = ( tax, id ) => id > 0 && ( current[ tax ] || [] ).includes( id );

	const ask = async () => {
		setBusy( true );
		setError( '' );
		setFound( null );
		try {
			const res = await apiFetch( {
				path: '/scrumptious/v1/suggest-terms',
				method: 'POST',
				data: { recipe_id: recipeId, title, recipe: data },
			} );
			setFound( res );
		} catch ( e ) {
			setError( e.message || __( 'Suggestions failed. Try again.', 'scrumptious' ) );
		}
		setBusy( false );
	};

	const add = async ( items ) => {
		const next = {};
		for ( const [ tax, item ] of items ) {
			let id = item.id;
			if ( ! id ) {
				const made = await saveEntityRecord( 'taxonomy', tax, { name: item.name }, { throwOnError: false } );
				id = made?.id || 0;
				if ( id ) {
					// Remember the new ID so the chip shows as added.
					setFound( ( f ) => ( {
						...f,
						[ tax ]: f[ tax ].map( ( t ) => ( t.name === item.name ? { ...t, id } : t ) ),
					} ) );
				}
			}
			if ( id ) {
				next[ tax ] = [ ...new Set( [ ...( next[ tax ] || current[ tax ] || [] ), id ] ) ];
			}
		}
		if ( Object.keys( next ).length ) {
			onChange( next );
		}
	};

	const pending = [];
	Object.entries( found || {} ).forEach( ( [ tax, list ] ) =>
		( list || [] ).forEach( ( item ) => {
			if ( ! has( tax, item.id ) ) {
				pending.push( [ tax, item ] );
			}
		} )
	);
	const empty = found && ! Object.values( found ).some( ( l ) => l && l.length );

	return (
		<div className="swp-tag-suggest">
			<Button variant="secondary" onClick={ ask } disabled={ busy } __next40pxDefaultSize>
				{ busy ? <Spinner /> : null }
				{ found ? __( 'Suggest again', 'scrumptious' ) : __( 'Suggest with AI', 'scrumptious' ) }
			</Button>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			<ProHint
				text={ __( 'Pro can do this for all your recipes at once.', 'scrumptious' ) }
				linkText={ __( 'Bulk AI', 'scrumptious' ) }
				urlKey="bulkAiUrl"
			/>
			{ empty && <p className="swp-help">{ __( 'No suggestions for this recipe.', 'scrumptious' ) }</p> }
			{ found &&
				Object.entries( found ).map( ( [ tax, list ] ) =>
					list && list.length ? (
						<div key={ tax } className="swp-tag-suggest__row">
							<span className="swp-tag-suggest__label">{ LABELS[ tax ] || tax }</span>
							{ list.map( ( item ) => {
								const added = has( tax, item.id );
								return (
									<Button
										key={ item.name }
										size="small"
										variant={ added ? 'primary' : 'tertiary' }
										className="swp-tag-suggest__chip"
										disabled={ added }
										aria-label={
											added
												? sprintf( /* translators: %s: term name */ __( '%s (added)', 'scrumptious' ), item.name )
												: sprintf( /* translators: %s: term name */ __( 'Add %s', 'scrumptious' ), item.name )
										}
										onClick={ () => add( [ [ tax, item ] ] ) }
									>
										{ added ? '✓ ' : '+ ' }
										{ item.name }
										{ ! item.id && <em>{ ' ' + __( '(new)', 'scrumptious' ) }</em> }
									</Button>
								);
							} ) }
						</div>
					) : null
				) }
			{ pending.length > 1 && (
				<Button variant="link" onClick={ () => add( pending ) }>
					{ __( 'Add all', 'scrumptious' ) }
				</Button>
			) }
			{ found && ! empty && (
				<p className="swp-help">
					{ __( 'Check diets before saving: readers with allergies rely on them.', 'scrumptious' ) }
				</p>
			) }
		</div>
	);
}
