<?php
/**
 * WordPress test-library configuration for the integration suite.
 *
 * Every value can be overridden with an environment variable, so the same file
 * serves a developer machine (local MySQL) and CI (a MySQL service container).
 * The test library DROPS AND RECREATES every table in this database on each
 * run: never point it at a database you care about.
 *
 * @package KWV_Enhancements
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- the test library reads $table_prefix.

$kwv_env = static function ( string $name, string $fallback ): string {
	$value = getenv( $name );

	return false === $value || '' === $value ? $fallback : $value;
};

$kwv_root = dirname( __DIR__, 2 );

// A clean copy of WordPress core from Composer (roots/wordpress-no-content),
// never the local site's own install: that one has a SQLite db.php drop-in and
// real content.
define( 'ABSPATH', $kwv_env( 'WP_CORE_DIR', $kwv_root . '/vendor/roots/wordpress-no-content' ) . '/' );

// A scratch wp-content (uploads, cache) inside build/, which is gitignored.
define( 'WP_CONTENT_DIR', $kwv_root . '/build/wp-content' );

// Third-party plugins (WooCommerce, Secure Custom Fields, Gravity Forms) are
// loaded from an existing plugins folder, never committed to this repository.
define( 'WP_PLUGIN_DIR', rtrim( $kwv_env( 'KWV_WP_PLUGINS_DIR', dirname( $kwv_root ) ), '/' ) );

// Mutation testing runs several test processes at once; Infection gives each
// a TEST_TOKEN, and each gets its own database (tests/bin/mutation-databases.php)
// so parallel runs never share tables.
$kwv_token = getenv( 'TEST_TOKEN' );
define( 'DB_NAME', $kwv_env( 'WP_TESTS_DB_NAME', 'kwv_enhancements_tests' ) . ( false === $kwv_token || '' === $kwv_token ? '' : '_t' . $kwv_token ) );
define( 'DB_USER', $kwv_env( 'WP_TESTS_DB_USER', 'root' ) );
define( 'DB_PASSWORD', $kwv_env( 'WP_TESTS_DB_PASSWORD', '' ) );
define( 'DB_HOST', $kwv_env( 'WP_TESTS_DB_HOST', '127.0.0.1' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'KWV Enhancements Tests' );
define( 'WP_PHP_BINARY', PHP_BINARY );
define( 'WPLANG', '' );
define( 'WP_DEBUG', true );
define( 'WP_ENVIRONMENT_TYPE', 'local' );

// Tests never touch the network: third-party plugins phone home while loading
// (licence and update checks), which made every bootstrap slow and flaky.
define( 'WP_HTTP_BLOCK_EXTERNAL', true );

define( 'AUTH_KEY', 'kwv-tests' );
define( 'SECURE_AUTH_KEY', 'kwv-tests' );
define( 'LOGGED_IN_KEY', 'kwv-tests' );
define( 'NONCE_KEY', 'kwv-tests' );
define( 'AUTH_SALT', 'kwv-tests' );
define( 'SECURE_AUTH_SALT', 'kwv-tests' );
define( 'LOGGED_IN_SALT', 'kwv-tests' );
define( 'NONCE_SALT', 'kwv-tests' );

unset( $kwv_env, $kwv_root, $kwv_token );
