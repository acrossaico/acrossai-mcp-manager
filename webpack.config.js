// WordPress webpack config
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

// Try to import getWebpackEntryPoints, fallback to empty object if not available
let getWebpackEntryPoints;
try {
	( {
		getWebpackEntryPoints,
	} = require( '@wordpress/scripts/utils/config' ) );
} catch {
	// Fallback for older versions of @wordpress/scripts
	getWebpackEntryPoints = () => ( {} );
}

// Plugins
const RemoveEmptyScriptsPlugin = require( 'webpack-remove-empty-scripts' );
const CopyPlugin = require( 'copy-webpack-plugin' );

// Utilities
const path = require( 'path' );
const { globSync } = require( 'glob' );

// Dynamically load SCSS files for core blocks (like block styles)
const blockStylesheets = () =>
	globSync( './src/scss/blocks/core/*.scss' ).reduce( ( files, filepath ) => {
		const name = path.parse( filepath ).name;
		files[ `css/blocks/core/${ name }` ] = path.resolve(
			process.cwd(),
			'src/scss/blocks/core',
			`${ name }.scss`,
		);
		return files;
	}, {} );

// Dynamically load custom blocks from block.json files
const blockJsonFiles = globSync( './src/blocks/**/block.json' );
const blockEntries = {};

blockJsonFiles.forEach( ( jsonFile ) => {
	const blockPath = path.dirname( jsonFile );
	const relativePath = path.relative( './src/blocks', blockPath );

	// Add index.js if it exists
	const indexPath = path.join( blockPath, 'index.js' );
	if ( globSync( indexPath ).length > 0 ) {
		blockEntries[ `blocks/${ relativePath }/index` ] =
			path.resolve( indexPath );
	}

	// Add view.js if it exists
	const viewPath = path.join( blockPath, 'view.js' );
	if ( globSync( viewPath ).length > 0 ) {
		blockEntries[ `blocks/${ relativePath }/view` ] =
			path.resolve( viewPath );
	}
} );

// Final Webpack export
module.exports = {
	...defaultConfig,
	resolve: {
		...defaultConfig.resolve,
		alias: {
			...( defaultConfig.resolve?.alias ?? {} ),
			// F015 — Access Control v2 adoption. Aliases the vendor's React
			// component so `import { AccessControl } from '@wpb/access-control'`
			// resolves to the vendor's source file. Matches the sibling
			// acrossai-abilities-manager plugin's setup.
			'@wpb/access-control': path.resolve(
				process.cwd(),
				'vendor/wpboilerplate/wpb-access-control/js/AccessControl.js',
			),
		},
	},
	entry: {
		...getWebpackEntryPoints(), // Default WP entry points (e.g., index.js)
		...blockStylesheets(), // Core block styles (scss)
		...blockEntries, // Custom blocks (index.js/view.js)
		'js/frontend': path.resolve( process.cwd(), 'src/js', 'frontend.js' ),
		'js/backend': path.resolve( process.cwd(), 'src/js', 'backend.js' ),
		// F015 — Access Control tab React entry (mounts the vendor's <AccessControl>).
		'js/access-control': path.resolve(
			process.cwd(),
			'src/js',
			'access-control.js',
		),
		// F017 — Abilities tab React entry (mounts the @wordpress/dataviews app).
		// `src/js/abilities.js` imports `../scss/abilities.scss`; the
		// @wordpress/scripts mini-css-extract config emits it as
		// `build/js/abilities.css`, which admin/Main.php auto-enqueues via
		// `file_exists()` alongside the JS bundle.
		'js/abilities': path.resolve( process.cwd(), 'src/js', 'abilities.js' ),
		// F020 — Tools tab React entry (mounts the hand-rolled shuttle picker).
		// Matches F017's shape for asset manifest + optional CSS extract.
		'js/tools': path.resolve( process.cwd(), 'src/js', 'tools.js' ),
		// F095 — AI Connectors tab entry, returned from the companion. Imports
		// ../scss/ai-connectors.scss, which mini-css-extract emits alongside as
		// build/js/ai-connectors.css (admin/Main.php enqueues it on file_exists).
		'js/ai-connectors': path.resolve(
			process.cwd(),
			'src/js',
			'ai-connectors.js',
		),
		// F096 — MCP Activity Log React entry (mounts the @wordpress/dataviews
		// app at #acrossai-mcp-activity-root). ONE bundle, TWO mount points: the
		// global Activity page and the per-server Logs tab, which passes a
		// serverId and hides the Server column.
		'js/activity-log': path.resolve(
			process.cwd(),
			'src/js/activity-log',
			'index.js',
		),
		// F101 — Connection troubleshooter entry. Plain DOM, not React: the
		// page is a button and a list of server-rendered verdicts. Imports
		// ../scss/troubleshoot.scss, emitted alongside as
		// build/js/troubleshoot.css and enqueued by TroubleshootPage.
		'js/troubleshoot': path.resolve(
			process.cwd(),
			'src/js',
			'troubleshoot.js',
		),
		// F069 — MCP Quick Connect via AcrossAI wizard React entry (mounts the 5-step
		// wizard app at #acrossai-mcp-quick-connect-root on the plugin
		// page when ?quick-connect=1 is present). `src/js/quick-connect.js`
		// imports `../scss/quick-connect.scss`; mini-css-extract emits
		// `build/js/quick-connect.css`; admin/Main.php enqueue is gated on
		// the wizard URL so the bundle never loads on the list-table view.
		'js/quick-connect': path.resolve( process.cwd(), 'src/js', 'quick-connect.js' ),
		'css/frontend': path.resolve(
			process.cwd(),
			'src/scss',
			'frontend.scss',
		),
		'css/backend': path.resolve(
			process.cwd(),
			'src/scss',
			'backend.scss',
		),

		// --- Absorbed from acrossai-abilities-manager (F106) ---
		//
		// Every name is prefixed `abilities/` and every source path lives under
		// `src/abilities/`. Without that, twelve built files collide: both
		// plugins emitted `frontend`, `backend`, `abilities` and `quick-connect`
		// in js, css and their -rtl variants, so whichever built last silently
		// won. The PHP tree is namespaced the same way, under `abilities/`.

		...getWebpackEntryPoints(), // Default WP entry points (e.g., index.js)
		...blockStylesheets(), // Core block styles (scss)
		...blockEntries, // Custom blocks (index.js/view.js)
		'js/abilities/frontend': path.resolve( process.cwd(), 'src/abilities/js', 'frontend.js' ),
		'js/abilities/backend': path.resolve( process.cwd(), 'src/abilities/js', 'backend.js' ),
		'css/abilities/frontend': path.resolve(
			process.cwd(),
			'src/abilities/scss',
			'frontend.scss',
		),
		'css/abilities/backend': path.resolve( process.cwd(), 'src/abilities/scss', 'backend.scss' ),
		'js/abilities/abilities': path.resolve(
			process.cwd(),
			'src/abilities/js/abilities',
			'index.js',
		),
		'css/abilities/abilities': path.resolve(
			process.cwd(),
			'src/abilities/scss/abilities',
			'admin.scss',
		),
		'js/abilities/mcp-abilities-extension': path.resolve(
			process.cwd(),
			'src/abilities/js/mcp-abilities-extension',
			'index.js',
		),
		'js/abilities/file-manager-settings': path.resolve(
			process.cwd(),
			'src/abilities/js/file-manager-settings',
			'index.js',
		),
		'css/abilities/file-manager-settings': path.resolve(
			process.cwd(),
			'src/abilities/scss/file-manager-settings',
			'admin.scss',
		),
		'js/abilities/database-settings': path.resolve(
			process.cwd(),
			'src/abilities/js/database-settings',
			'index.js',
		),
		'css/abilities/database-settings': path.resolve(
			process.cwd(),
			'src/abilities/scss/database-settings',
			'admin.scss',
		),
		'js/abilities/integrations': path.resolve(
			process.cwd(),
			'src/abilities/js/integrations',
			'index.js',
		),
		'css/abilities/integrations': path.resolve(
			process.cwd(),
			'src/abilities/scss/integrations',
			'admin.scss',
		),
		'js/abilities/quick-connect': path.resolve(
			process.cwd(),
			'src/abilities/js/quick-connect',
			'index.js',
		),
		'css/abilities/quick-connect': path.resolve(
			process.cwd(),
			'src/abilities/scss/quick-connect',
			'admin.scss',
		),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( process.cwd(), 'build' ),
		filename: '[name].js',
	},
	plugins: [
		...defaultConfig.plugins,

		// Remove empty .js files after WP script/plugin generation
		new RemoveEmptyScriptsPlugin( {
			stage: RemoveEmptyScriptsPlugin.STAGE_AFTER_PROCESS_PLUGINS,
		} ),

		// Safely copy media or other static assets
		new CopyPlugin( {
			patterns: [
				{
					from: './src/media',
					to: './media',
					noErrorOnMissing: true,
				},
				{
					from: './src/fonts',
					to: './fonts',
					noErrorOnMissing: true,
				},
			],
		} ),
	],
};
