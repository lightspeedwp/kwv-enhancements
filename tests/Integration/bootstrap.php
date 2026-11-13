<?php
/**
 * Integration-suite bootstrap: installs WordPress into the test database, loads
 * WooCommerce, Secure Custom Fields and (when present) Gravity Forms, then this
 * plugin, before any test runs.
 *
 * @package KWV_Enhancements
 */

$kwv_root = dirname( __DIR__, 2 );

require_once $kwv_root . '/vendor/autoload.php';

define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $kwv_root . '/vendor/yoast/phpunit-polyfills' );

// WooCommerce's own test bootstrap sets these; without them it wraps work in
// transactions the WP test library already manages.
define( 'WC_USE_TRANSACTIONS', false );
define( 'WC_TAX_ROUNDING_MODE', 'auto' );

$kwv_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $kwv_tests_dir ) {
	$kwv_tests_dir = $kwv_root . '/vendor/wp-phpunit/wp-phpunit';
}

if ( ! is_dir( $kwv_root . '/build/wp-content/uploads' ) ) {
	mkdir( $kwv_root . '/build/wp-content/uploads', 0777, true );
}

// A test run in a separate process re-runs this bootstrap. The parent process
// has already installed the test database, so skip the reinstall: it is slow,
// and its third-party notices on stderr would fail the isolated test.
//
// WooCommerce and Gravity Forms also trigger "called incorrectly" notices while
// loading (translations and wp_is_block_theme() before init). In the parent
// process they are printed and harmless; in a child process PHPUnit turns them
// into a failure. Only the PHP notice is silenced: WP_UnitTestCase still fails
// a test on any _doing_it_wrong() call made by the test itself, because it
// listens to the doing_it_wrong_run action, not to trigger_error().
$kwv_isolated = isset( $GLOBALS['__PHPUNIT_BOOTSTRAP'] );
if ( $kwv_isolated ) {
	putenv( 'WP_TESTS_SKIP_INSTALL=1' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- read by the WP test library's bootstrap.
}

require_once $kwv_tests_dir . '/includes/functions.php';

if ( $kwv_isolated ) {
	tests_add_filter( 'doing_it_wrong_trigger_error', '__return_false' );
}

require_once __DIR__ . '/../Support/ThirdPartyPlugins.php';

$kwv_plugins = new KWV\Enhancements\Tests\Support\ThirdPartyPlugins();

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $kwv_plugins, $kwv_root ) {
		$kwv_plugins->load();
		require $kwv_root . '/kwv-enhancements.php';
	}
);

tests_add_filter(
	'setup_theme',
	static function () use ( $kwv_plugins ) {
		$kwv_plugins->install();
	}
);

require $kwv_tests_dir . '/includes/bootstrap.php';

$kwv_plugins->report();

unset( $kwv_root, $kwv_tests_dir, $kwv_plugins, $kwv_isolated );
