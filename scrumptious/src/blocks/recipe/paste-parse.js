/**
 * Paste & Parse: paste recipe text, get a filled-in recipe.
 * Uses the site's AI provider when set up, the built-in parser otherwise.
 */
import { __, sprintf } from '@wordpress/i18n';
import { Modal, Button, TextareaControl, Notice, ExternalLink } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { applyFilters } from '@wordpress/hooks';
import apiFetch from '@wordpress/api-fetch';
import ProHint from './pro-hint';
import HelpLink from './help-link';

const config = () => window.scrumptiousEditor || { ai: false, provider: '', settingsUrl: '' };

/**
 * Extra input types other plugins can add to the box (filter `scrumptious.pasteParse.sources`).
 * Each: { test( text ) => bool, request( text, options ) => apiFetch args, defaults?: {},
 * Options?: component( { options, setOption } ), button?: string, help?: string }.
 * The response must look like /scrumptious/v1/parse's.
 */
const sources = () => {
	const list = applyFilters( 'scrumptious.pasteParse.sources', [] );
	return Array.isArray( list ) ? list : [];
};

export const hasContent = ( data ) =>
	( data.ingredient_groups || [] ).some( ( g ) => g.items?.length ) ||
	( data.instruction_groups || [] ).some( ( g ) => g.steps?.length );

export default function PasteParse( { data, onApply, variant = 'secondary', label } ) {
	const [ open, setOpen ] = useState( false );
	const [ text, setText ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ options, setOptions ] = useState( {} );
	const source = sources().find( ( s ) => typeof s.test === 'function' && s.test( text.trim() ) ) || null;
	const opts = { ...( source?.defaults || {} ), ...options };
	const setOption = ( key, value ) => setOptions( ( o ) => ( { ...o, [ key ]: value } ) );
	const { ai, provider, settingsUrl } = config();
	const replacing = hasContent( data );

	const close = () => {
		setOpen( false );
		setError( '' );
	};

	const parse = async () => {
		setBusy( true );
		setError( '' );
		try {
			const res = source
				? await apiFetch( source.request( text.trim(), opts ) )
				: await apiFetch( {
						path: '/scrumptious/v1/parse',
						method: 'POST',
						data: { text, mode: 'auto' },
				  } );
			onApply( res );
			setText( '' );
			close();
		} catch ( e ) {
			setError( e?.message || __( 'Could not parse that text.', 'scrumptious' ) );
		}
		setBusy( false );
	};

	return (
		<>
			<Button variant={ variant } onClick={ () => setOpen( true ) }>
				{ label || __( 'Paste & Parse', 'scrumptious' ) }
			</Button>
			{ open && (
				<Modal title={ __( 'Paste & Parse', 'scrumptious' ) } onRequestClose={ close } size="medium">
					<p style={ { marginTop: 0 } }>
						{ applyFilters(
							'scrumptious.pasteParse.intro',
							__( 'Paste the whole recipe text — from a document, an email or your notes. Title, times, servings, ingredients, steps and notes are filled in for you. Check the result before publishing.', 'scrumptious' )
						) }
					</p>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Recipe text', 'scrumptious' ) }
						hideLabelFromVision
						rows={ 14 }
						value={ text }
						onChange={ setText }
						placeholder={ applyFilters(
							'scrumptious.pasteParse.placeholder',
							__( 'Chewy Chocolate Chip Cookies\nPrep time: 15 min · Servings: 24\n\nIngredients\n2 1/4 cups flour\n…', 'scrumptious' )
						) }
					/>
					{ source?.Options && <source.Options options={ opts } setOption={ setOption } /> }
					<p className="swp-help">
						{ source?.help ? source.help + ' ' : '' }
						{ ai
							? sprintf(
									/* translators: %s: AI provider name */
									__( 'Using AI (%s). Your text is sent to your provider with your own key.', 'scrumptious' ),
									provider
							  )
							: __( 'Using the built-in parser. Connect an AI provider for messier text (stories, odd layouts).', 'scrumptious' ) }{ ' ' }
						{ ! ai && settingsUrl && <ExternalLink href={ settingsUrl }>{ __( 'Set up AI', 'scrumptious' ) }</ExternalLink> }{ ' ' }
						<HelpLink slug="paste-and-parse">{ __( 'How Paste & Parse works', 'scrumptious' ) }</HelpLink>
					</p>
					{ ! source && (
						<ProHint
							text={ __( 'Have a recipe on another site? Import from a link is part of Pro.', 'scrumptious' ) }
							linkText={ __( 'Learn more', 'scrumptious' ) }
							urlKey="importUrl"
						/>
					) }
					{ replacing && (
						<Notice status="warning" isDismissible={ false }>
							{ __( 'This replaces the current ingredients, steps and details of this recipe.', 'scrumptious' ) }
						</Notice>
					) }
					{ error && (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) }
					<div style={ { display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 } }>
						<Button variant="tertiary" onClick={ close }>
							{ __( 'Cancel', 'scrumptious' ) }
						</Button>
						<Button variant="primary" onClick={ parse } isBusy={ busy } disabled={ busy || ! text.trim() }>
							{ busy
								? __( 'Reading recipe…', 'scrumptious' )
								: source?.button
								? source.button
								: __( 'Parse recipe', 'scrumptious' ) }
						</Button>
					</div>
				</Modal>
			) }
		</>
	);
}
