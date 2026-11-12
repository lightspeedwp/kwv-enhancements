/**
 * Editor registration for kwv/brand-awards.
 *
 * The block is dynamic — PHP renders it (see modules/brands.php) — so the
 * editor just previews the server render. Plain ES5 + window.wp globals, so
 * there is no build step; dependencies are declared in index.asset.php.
 */
( function ( blocks, element, blockEditor, serverSideRender ) {
	var el = element.createElement;

	blocks.registerBlockType( 'kwv/brand-awards', {
		edit: function ( props ) {
			return el(
				'div',
				blockEditor.useBlockProps(),
				el( serverSideRender, {
					block: 'kwv/brand-awards',
					attributes: props.attributes,
				} )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.serverSideRender );
