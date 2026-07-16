<?php
/**
 * Plugin Name: KWV Enhancements
 * Plugin URI: https://kwv.co.za
 * Description: Site-specific enhancements and content fixes for the KWV 2026 site — functionality that must survive a theme switch. Ships as modules; the first is Careers (the kwv_career job post type and its Gravity Forms application form).
 * Version: 1.0.0
 * Author: LightSpeed
 * Author URI: https://lightspeedwp.agency
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: kwv-enhancements
 * Requires at least: 6.7
 * Requires PHP: 7.4
 *
 * Why a plugin (not the theme): content structure — post types, meta, and the
 * data behind them — must survive a theme switch or rebuild. Presentation lives
 * in the kwv-theme-2026 block theme; anything that owns data lives here.
 *
 * Custom fields are authored with Secure Custom Fields and stored as code in
 * /acf-json (local JSON), so field definitions are version-controlled and
 * deploy with the plugin rather than living only in a database.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KWV_ENH_VERSION', '1.0.0' );
define( 'KWV_ENH_FILE', __FILE__ );
define( 'KWV_ENH_DIR', plugin_dir_path( __FILE__ ) );
define( 'KWV_ENH_URL', plugin_dir_url( __FILE__ ) );

/**
 * Tell Secure Custom Fields / ACF to load (and save) field groups from this
 * plugin's /acf-json directory, so the Careers field group ships as code.
 *
 * @param string[] $paths Existing JSON load paths.
 * @return string[] Paths with this plugin's acf-json directory appended.
 */
function kwv_enh_acf_json_load_point( $paths ) {
	$paths[] = KWV_ENH_DIR . 'acf-json';
	return $paths;
}
add_filter( 'acf/settings/load_json', 'kwv_enh_acf_json_load_point' );

/**
 * Save SCF/ACF field-group JSON into this plugin (used when editing groups in
 * the admin UI on a dev environment), keeping /acf-json authoritative.
 *
 * @param string $path Default save path.
 * @return string This plugin's acf-json directory.
 */
function kwv_enh_acf_json_save_point( $path ) {
	return KWV_ENH_DIR . 'acf-json';
}
add_filter( 'acf/settings/save_json', 'kwv_enh_acf_json_save_point' );

// Load modules.
require_once KWV_ENH_DIR . 'modules/block-bindings.php';
require_once KWV_ENH_DIR . 'modules/careers.php';
require_once KWV_ENH_DIR . 'modules/careers-application-form.php';

/**
 * Activation: register post types then flush rewrite rules so /careers/{slug}
 * resolves immediately, and schedule the daily job-expiry check.
 */
function kwv_enh_activate() {
	kwv_careers_register_post_type();
	flush_rewrite_rules();
	kwv_careers_schedule_expiry_event();
}
register_activation_hook( __FILE__, 'kwv_enh_activate' );

/**
 * Deactivation: clear the expiry cron and flush rewrite rules.
 */
function kwv_enh_deactivate() {
	kwv_careers_unschedule_expiry_event();
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'kwv_enh_deactivate' );
