<?php
/**
 * Script dependencies for the kwv/event-accommodation editor script.
 *
 * Hand-written (there is no build step) in the shape @wordpress/scripts emits,
 * so register_block_type() picks it up from block.json's editorScript.
 *
 * @package KWV_Enhancements
 */

return array(
	'dependencies' => array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-server-side-render' ),
	'version'      => '1.0.0',
);
