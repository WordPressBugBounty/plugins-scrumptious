/**
 * Small grey "this is part of Pro" hints with a link out. No Pro code ships here.
 * One "×" hides every hint for this user (saved in user meta).
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const config = () => ( window.scrumptiousEditor && window.scrumptiousEditor.proHints ) || null;
let hidden = false;
const listeners = new Set();

function hideAll() {
	hidden = true;
	listeners.forEach( ( fn ) => fn() );
	apiFetch( { path: '/scrumptious/v1/hide-pro-hints', method: 'POST' } ).catch( () => {} );
}

/**
 * @param {Object} props
 * @param {string} props.text     Hint text.
 * @param {string} props.linkText Link text.
 * @param {string} props.urlKey   Key in scrumptiousEditor.proHints for the URL.
 */
export default function ProHint( { text, linkText, urlKey } ) {
	const [ , setTick ] = useState( 0 );
	useEffect( () => {
		const fn = () => setTick( ( t ) => t + 1 );
		listeners.add( fn );
		return () => listeners.delete( fn );
	}, [] );
	const c = config();
	if ( hidden || ! c || ! c[ urlKey ] ) {
		return null;
	}
	return (
		<p className="swp-pro-hint">
			{ text }{ ' ' }
			<a href={ c[ urlKey ] } target="_blank" rel="noopener noreferrer">
				{ linkText }
			</a>
			<button type="button" className="swp-pro-hint__hide" onClick={ hideAll } aria-label={ __( 'Hide these Pro tips', 'scrumptious' ) } title={ __( 'Hide these Pro tips', 'scrumptious' ) }>
				×
			</button>
		</p>
	);
}
