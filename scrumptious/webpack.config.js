/**
 * Extends @wordpress/scripts: block entries from block.json + admin-only entries.
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

const base = Array.isArray( defaultConfig ) ? defaultConfig[ 0 ] : defaultConfig;

module.exports = {
	...base,
	entry: async () => {
		const blocks = typeof base.entry === 'function' ? await base.entry() : base.entry;
		return {
			...blocks,
			'admin/views-panel': path.resolve( __dirname, 'src/admin/views-panel.js' ),
		};
	},
};
