/**
 * "Where this recipe appears" panel on the recipe edit screen.
 */
import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { ExternalLink } from '@wordpress/components';

const TYPE = 'scrumptious_recipe';

function ViewsPanel() {
	const { postType, postId } = useSelect( ( select ) => {
		const editor = select( 'core/editor' );
		return {
			postType: editor?.getCurrentPostType(),
			postId: editor?.getCurrentPostId(),
		};
	}, [] );

	const views = useSelect(
		( select ) =>
			postType === TYPE
				? select( coreStore ).getEntityRecord( 'postType', TYPE, postId )
						?.scrumptious_views
				: null,
		[ postType, postId ]
	);

	if ( postType !== TYPE ) {
		return null;
	}

	return (
		<PluginDocumentSettingPanel
			name="scrumptious-views"
			title={ __( 'Where this recipe appears', 'scrumptious' ) }
			initialOpen
		>
			{ ! views?.length && (
				<p>
					{ __(
						'Not used in any post yet. Add a Recipe Card block to a post and choose this recipe.',
						'scrumptious'
					) }
				</p>
			) }
			{ !! views?.length && (
				<ul style={ { margin: 0 } }>
					{ views.map( ( v ) => (
						<li key={ `${ v.type }-${ v.id }` } style={ { marginBottom: 8 } }>
							{ v.link ? (
								<ExternalLink href={ v.link }>{ v.title || __( '(no title)', 'scrumptious' ) }</ExternalLink>
							) : (
								v.title
							) }
							{ v.status && v.status !== 'publish' && <em> — { v.status }</em> }
							{ v.edit && (
								<>
									{ ' · ' }
									<a href={ v.edit }>{ __( 'Edit post', 'scrumptious' ) }</a>
								</>
							) }
						</li>
					) ) }
				</ul>
			) }
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'scrumptious-views', { render: ViewsPanel } );
