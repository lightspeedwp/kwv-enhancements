<?php
/**
 * Plugin Name: KWV Enhancements
 * Plugin URI: https://kwv.co.za
 * Description: Site-specific enhancements and content fixes for the KWV 2026 site — functionality that must survive a theme switch. Ships as modules: Careers (the kwv_career job post type, the jobs-board modal, and its Gravity Forms application form) and Events (the kwv_event post type, its Brand taxonomy, the dynamic booking-form dropdowns, and the "Accommodation nearby" block).
 * Version: 1.1.0
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
 *
 * Architecture: a small OO module system. Each concern is a class implementing
 * the Module interface (modules/module.php); Careers and Events share a
 * PostTypeModule base for their CPT/meta/expiry machinery. The Plugin container
 * (modules/plugin.php) instantiates the modules, registers their hooks, and
 * delegates activation/deactivation. This bootstrap file only wires that up.
 *
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KWV_ENH_VERSION', '1.1.0' );
define( 'KWV_ENH_FILE', __FILE__ );
define( 'KWV_ENH_DIR', plugin_dir_path( __FILE__ ) );
define( 'KWV_ENH_URL', plugin_dir_url( __FILE__ ) );

// Load the module framework, then the modules (contracts and base before
// concretes; the container last).
require_once KWV_ENH_DIR . 'modules/module.php';
require_once KWV_ENH_DIR . 'modules/post-type-module.php';
require_once KWV_ENH_DIR . 'modules/acf-json.php';
require_once KWV_ENH_DIR . 'modules/block-bindings.php';
require_once KWV_ENH_DIR . 'modules/email-template.php';
require_once KWV_ENH_DIR . 'modules/careers.php';
require_once KWV_ENH_DIR . 'modules/careers-application-form.php';
require_once KWV_ENH_DIR . 'modules/careers-application-context.php';
require_once KWV_ENH_DIR . 'modules/careers-modal.php';
require_once KWV_ENH_DIR . 'modules/events.php';
require_once KWV_ENH_DIR . 'modules/events-booking-form.php';
require_once KWV_ENH_DIR . 'modules/event-accommodation.php';
require_once KWV_ENH_DIR . 'modules/winemakers-club.php';
require_once KWV_ENH_DIR . 'modules/payflex-configuration-cache.php';
require_once KWV_ENH_DIR . 'modules/plugin.php';

$kwv_enhancements = Plugin::instance();
$kwv_enhancements->register();

register_activation_hook( __FILE__, array( $kwv_enhancements, 'activate' ) );
register_deactivation_hook( __FILE__, array( $kwv_enhancements, 'deactivate' ) );
