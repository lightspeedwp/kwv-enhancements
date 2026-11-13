<?php
/**
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements\Tests\Unit;

use KWV\Enhancements\AcfJson;

/**
 * @covers \KWV\Enhancements\AcfJson
 */
final class AcfJsonTest extends TestCase {

	public function test_register_hooks_both_scf_json_filters(): void {
		$module = new AcfJson();
		$module->register();

		$this->assertNotFalse( has_filter( 'acf/settings/load_json', array( $module, 'add_load_point' ) ) );
		$this->assertNotFalse( has_filter( 'acf/settings/save_json', array( $module, 'set_save_point' ) ) );
	}

	public function test_load_point_is_added_without_dropping_existing_paths(): void {
		$paths = ( new AcfJson() )->add_load_point( array( '/theme/acf-json' ) );

		$this->assertSame( array( '/theme/acf-json', KWV_ENH_DIR . 'acf-json' ), $paths );
	}

	public function test_save_point_is_the_plugin_folder(): void {
		$this->assertSame( KWV_ENH_DIR . 'acf-json', ( new AcfJson() )->set_save_point( '/anything' ) );
	}

	public function test_every_local_json_field_group_is_valid_json_with_a_key(): void {
		$files = glob( KWV_ENH_DIR . 'acf-json/*.json' );

		$this->assertNotEmpty( $files );

		foreach ( $files as $file ) {
			$group = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			$this->assertIsArray( $group, basename( $file ) . ' is not valid JSON' );
			$this->assertSame( basename( $file, '.json' ), $group['key'] ?? null, basename( $file ) . ' key must match its file name' );
			$this->assertNotEmpty( $group['fields'] ?? array(), basename( $file ) . ' has no fields' );
		}
	}
}
