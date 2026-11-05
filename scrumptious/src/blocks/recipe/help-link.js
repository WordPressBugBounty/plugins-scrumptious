/**
 * "Learn more" link to a help desk article.
 */
import { __ } from '@wordpress/i18n';
import { ExternalLink } from '@wordpress/components';

const base = () => ( window.scrumptiousEditor && window.scrumptiousEditor.helpBase ) || 'https://scrumptiouswp.com/help/';

export default function HelpLink( { slug, children } ) {
	return <ExternalLink href={ `${ base() }${ slug }/` }>{ children || __( 'Learn more', 'scrumptious' ) }</ExternalLink>;
}
