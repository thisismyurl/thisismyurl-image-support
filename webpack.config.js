/**
 * Custom webpack config for the Image Support plugin's editor bundle.
 *
 * Extends @wordpress/scripts' default config and overrides only the entry
 * map so the output filename is `photo-credit-panel.js` rather than the
 * default `photo-credit-panel.jsx.js` that wp-scripts produces when handed
 * a JSX-extension entry on the CLI.
 *
 * The default config already wires every @wordpress/* dep as an external
 * (resolved at runtime against the editor's WP global), emits a per-entry
 * .asset.php with the dependency manifest, and handles JSX transpilation
 * via Babel. We inherit all of that and only adjust the entry contract.
 *
 * Source: assets/editor/photo-credit-panel.jsx
 * Output: assets/build/photo-credit-panel.js (+ .asset.php), via the
 *         --output-path=assets/build flag in the npm "build" script.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/packages/packages-scripts/
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		'photo-credit-panel': path.resolve( __dirname, 'assets/editor/photo-credit-panel.jsx' ),
	},
};
