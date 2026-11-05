import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import Edit from './edit';
import './editor.scss';

registerBlockType( metadata.name, {
	edit: Edit,
	// Dynamic block: rendered in PHP.
	save: () => null,
} );
