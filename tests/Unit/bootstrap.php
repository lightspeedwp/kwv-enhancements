<?php
/**
 * Unit-suite bootstrap: loads the plugin's classes without WordPress.
 *
 * WordPress functions are mocked per test with Brain Monkey; the few WordPress
 * and Gravity Forms classes the modules type against are small fakes in
 * tests/Unit/Stubs. Nothing here touches a database or the network.
 *
 * @package KWV_Enhancements
 */

$kwv_root = dirname( __DIR__, 2 );

require_once $kwv_root . '/vendor/autoload.php';

// The plugin's files bail without ABSPATH; its constants come from the main
// plugin file, which also boots WordPress hooks, so they are defined here.
define( 'ABSPATH', $kwv_root . '/vendor/roots/wordpress-no-content/' );
define( 'KWV_ENH_VERSION', 'test' );
define( 'KWV_ENH_FILE', $kwv_root . '/kwv-enhancements.php' );
define( 'KWV_ENH_DIR', $kwv_root . '/' );
define( 'KWV_ENH_URL', 'https://example.org/wp-content/plugins/kwv-enhancements/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

require_once __DIR__ . '/Stubs/wordpress.php';
require_once __DIR__ . '/Stubs/gravityforms.php';

// Same load order as kwv-enhancements.php.
foreach ( array(
	'module',
	'post-type-module',
	'acf-json',
	'block-bindings',
	'email-template',
	'careers',
	'careers-application-form',
	'careers-application-context',
	'careers-modal',
	'events',
	'events-booking-form',
	'event-accommodation',
	'brands',
	'winemakers-club',
	'payflex-configuration-cache',
	'plugin',
) as $kwv_module ) {
	require_once $kwv_root . '/modules/' . $kwv_module . '.php';
}

unset( $kwv_root, $kwv_module );
