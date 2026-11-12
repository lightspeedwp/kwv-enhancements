<?php
/**
 * Minimal stand-ins for WordPress classes the modules type against, for the
 * unit suite only (the integration suite uses the real ones).
 *
 * @package KWV_Enhancements
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Commenting

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $message;
		public function __construct( $code = '', $message = '' ) {
			$this->message = $message;
		}
	}
}

if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {
		public $term_id  = 0;
		public $name     = '';
		public $taxonomy = '';
		public function __construct( $props = array() ) {
			foreach ( (array) $props as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

if ( ! class_exists( 'WP_Block' ) ) {
	class WP_Block {
		public $context    = array();
		public $attributes = array();
		public function __construct( array $context = array(), array $attributes = array() ) {
			$this->context    = $context;
			$this->attributes = $attributes;
		}
	}
}
