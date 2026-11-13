<?php
/**
 * Smoke test: the plugin and its third-party dependencies load.
 *
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements\Tests\Integration;

/**
 * @coversNothing
 */
final class PluginBootTest extends TestCase {

	public function test_plugin_and_dependencies_are_loaded(): void {
		$this->assertTrue( defined( 'KWV_ENH_VERSION' ) );
		$this->assertTrue( class_exists( 'WooCommerce' ) );
		$this->assertTrue( function_exists( 'acf_get_field_groups' ) );
		$this->assertTrue( post_type_exists( 'kwv_career' ) );
		$this->assertTrue( post_type_exists( 'kwv_event' ) );
	}
}
