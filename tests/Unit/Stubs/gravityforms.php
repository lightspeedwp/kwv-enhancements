<?php
/**
 * A fake of the slice of the Gravity Forms API the plugin uses, for the unit
 * suite. Gravity Forms is commercial, so CI cannot install it; these fakes let
 * the form provisioning and submission logic be tested everywhere. The
 * integration suite exercises the real plugin when it is available.
 *
 * @package KWV_Enhancements
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Commenting, WordPress.NamingConventions, Universal.Files.SeparateFunctionsFromOO, Universal.NamingConventions.NoReservedKeywordParameterNames, Generic.CodeAnalysis.UnusedFunctionParameter -- mirrors Gravity Forms' own signatures.

if ( ! class_exists( 'GFAPI' ) ) {
	/**
	 * In-memory form store with the GFAPI method signatures the plugin calls.
	 */
	class GFAPI {
		/** @var array<int,array> */
		public static $forms = array();
		/** @var int */
		public static $next_id = 1;
		/** @var WP_Error|null Returned by the next add/update when set. */
		public static $fail_with = null;

		public static function reset(): void {
			self::$forms     = array();
			self::$next_id   = 1;
			self::$fail_with = null;
		}

		public static function add_form( $form ) {
			if ( self::$fail_with ) {
				return self::$fail_with;
			}
			$id                 = self::$next_id++;
			$form['id']         = $id;
			self::$forms[ $id ] = $form;
			return $id;
		}

		public static function get_form( $form_id ) {
			return self::$forms[ (int) $form_id ] ?? false;
		}

		public static function update_form( $form, $form_id = 0 ) {
			if ( self::$fail_with ) {
				return self::$fail_with;
			}
			self::$forms[ (int) $form_id ] = $form;
			return true;
		}
	}
}

if ( ! function_exists( 'rgar' ) ) {
	function rgar( $array, $prop, $default_value = null ) {
		if ( ! is_array( $array ) && ! ( is_object( $array ) && $array instanceof ArrayAccess ) ) {
			return $default_value;
		}
		return isset( $array[ $prop ] ) ? $array[ $prop ] : $default_value;
	}
}

if ( ! function_exists( 'rgpost' ) ) {
	function rgpost( $name, $do_stripslashes = true ) {
		return isset( $_POST[ $name ] ) ? $_POST[ $name ] : ''; // phpcs:ignore WordPress.Security
	}
}

/**
 * Build a Gravity Forms field-like object.
 *
 * @param array $props Field properties.
 * @return stdClass
 */
function kwv_test_gf_field( array $props ): stdClass {
	return (object) array_merge(
		array(
			'id'        => 0,
			'type'      => 'text',
			'label'     => '',
			'inputName' => '',
		),
		$props
	);
}
