<?php
/**
 * Loads and installs the third-party plugins the integration suite runs with.
 *
 * Plugins come from WP_PLUGIN_DIR (see tests/Integration/wp-tests-config.php,
 * overridable with KWV_WP_PLUGINS_DIR). WooCommerce and Secure Custom Fields
 * are required: they are free on wordpress.org, so CI downloads them. Gravity
 * Forms is commercial, so it is optional — tests that need it skip themselves
 * when it is missing ({@see TestCase::requireGravityForms()}).
 *
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements\Tests\Support;

/**
 * Loads WooCommerce, SCF and Gravity Forms into the test WordPress.
 */
final class ThirdPartyPlugins {

	/**
	 * Plugins the suite cannot run without (basename => label).
	 */
	private const REQUIRED = array(
		'woocommerce/woocommerce.php'                   => 'WooCommerce',
		'secure-custom-fields/secure-custom-fields.php' => 'Secure Custom Fields',
	);

	/**
	 * Plugins loaded when present (basename => label).
	 */
	private const OPTIONAL = array(
		'gravityforms/gravityforms.php' => 'Gravity Forms',
	);

	/**
	 * Basenames that were found and loaded.
	 *
	 * @var string[]
	 */
	private $loaded = array();

	/**
	 * Load every available plugin. Called on muplugins_loaded.
	 */
	public function load(): void {

		foreach ( self::REQUIRED as $basename => $label ) {
			if ( ! is_readable( WP_PLUGIN_DIR . '/' . $basename ) ) {
				fwrite( STDERR, sprintf( "%s not found at %s. Set KWV_WP_PLUGINS_DIR to a plugins folder that contains it (see tests/README.md).\n", $label, WP_PLUGIN_DIR . '/' . $basename ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				exit( 1 );
			}
		}

		foreach ( array_merge( array_keys( self::REQUIRED ), array_keys( self::OPTIONAL ) ) as $basename ) {
			if ( is_readable( WP_PLUGIN_DIR . '/' . $basename ) ) {
				require_once WP_PLUGIN_DIR . '/' . $basename;
				$this->loaded[] = $basename;
			}
		}

		// Report them as active, so is_plugin_active() checks behave.
		$loaded = $this->loaded;
		add_filter(
			'pre_option_active_plugins',
			static function () use ( $loaded ) {
				return $loaded;
			}
		);
	}

	/**
	 * Create the plugins' database tables. Called on setup_theme, before the
	 * test library starts wrapping each test in a transaction.
	 */
	public function install(): void {

		if ( class_exists( 'WC_Install' ) ) {
			\WC_Install::install();
			// Reload roles so the capabilities WooCommerce just added are live.
			$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			wp_roles();
		}

		if ( function_exists( 'gf_upgrade' ) ) {
			gf_upgrade()->install();
		}
	}

	/**
	 * Print which optional plugins are missing, so a skipped test is explained.
	 */
	public function report(): void {

		foreach ( self::OPTIONAL as $basename => $label ) {
			if ( ! in_array( $basename, $this->loaded, true ) ) {
				echo esc_html( $label ) . ' is not available: tests that need it will be skipped.' . PHP_EOL;
			}
		}
	}
}
