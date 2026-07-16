<?php
/**
 * SCF / ACF local-JSON wiring.
 *
 * Points Secure Custom Fields (or ACF) at this plugin's /acf-json directory for
 * both loading and saving field groups, so field definitions ship as code and
 * stay version-controlled rather than living only in the database.
 *
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers this plugin's acf-json directory as an SCF/ACF load and save point.
 */
class AcfJson implements Module {

	/**
	 * Hook the load and save filters.
	 */
	public function register(): void {
		add_filter( 'acf/settings/load_json', array( $this, 'add_load_point' ) );
		add_filter( 'acf/settings/save_json', array( $this, 'set_save_point' ) );
	}

	/**
	 * Append this plugin's acf-json directory to the JSON load paths, so the
	 * field groups ship as code.
	 *
	 * @param string[] $paths Existing JSON load paths.
	 * @return string[] Paths with this plugin's acf-json directory appended.
	 */
	public function add_load_point( $paths ) {
		$paths[] = KWV_ENH_DIR . 'acf-json';
		return $paths;
	}

	/**
	 * Save field-group JSON into this plugin (used when editing groups in the
	 * admin UI on a dev environment), keeping /acf-json authoritative.
	 *
	 * @param string $path Default save path.
	 * @return string This plugin's acf-json directory.
	 */
	public function set_save_point( $path ) {
		return KWV_ENH_DIR . 'acf-json';
	}
}
