/**
 * Editor registration for kwv/event-accommodation.
 *
 * The block is dynamic — PHP renders it (see modules/event-accommodation.php) —
 * so the editor just previews the server render. Plain ES5 + window.wp globals,
 * so there is no build step; dependencies are declared in index.asset.php.
 */
( function ( blocks, element, blockEditor, serverSideRender ) {
	var el = element.createElement;

	blocks.registerBlockType( 'kwv/event-accommodation', {
		edit: function ( props ) {
			var postId = props.context && props.context.postId;

			return el(
				'div',
				blockEditor.useBlockProps(),
				el( serverSideRender, {
					block: 'kwv/event-accommodation',
					attributes: props.attributes,
					urlQueryArgs: postId ? { post_id: postId } : {},
				} )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.serverSideRender );
