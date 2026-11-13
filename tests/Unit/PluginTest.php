<?php
/**
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements\Tests\Unit;

use Brain\Monkey\Functions;
use KWV\Enhancements\Activatable;
use KWV\Enhancements\Deactivatable;
use KWV\Enhancements\Module;
use KWV\Enhancements\Plugin;
use Mockery;

/**
 * @covers \KWV\Enhancements\Plugin
 */
final class PluginTest extends TestCase {

	protected function tear_down(): void {
		$this->resetSingleton();
		parent::tear_down();
	}

	private function resetSingleton(): void {
		$property = new \ReflectionProperty( Plugin::class, 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}

	/**
	 * @param Plugin   $plugin  The container.
	 * @param object[] $modules Replacement modules.
	 */
	private function setModules( Plugin $plugin, array $modules ): void {
		$property = new \ReflectionProperty( Plugin::class, 'modules' );
		$property->setAccessible( true );
		$property->setValue( $plugin, $modules );
	}

	/**
	 * @return object[]
	 */
	private function modules( Plugin $plugin ): array {
		$property = new \ReflectionProperty( Plugin::class, 'modules' );
		$property->setAccessible( true );
		return $property->getValue( $plugin );
	}

	public function test_instance_is_a_singleton(): void {
		$this->assertSame( Plugin::instance(), Plugin::instance() );
	}

	public function test_every_module_is_registered_in_load_order(): void {
		$classes = array_map( 'get_class', $this->modules( Plugin::instance() ) );

		$this->assertSame(
			array(
				'KWV\Enhancements\AcfJson',
				'KWV\Enhancements\BlockBindings',
				'KWV\Enhancements\Careers',
				'KWV\Enhancements\ApplicationForm',
				'KWV\Enhancements\ApplicationContext',
				'KWV\Enhancements\CareersModal',
				'KWV\Enhancements\Events',
				'KWV\Enhancements\BookingForm',
				'KWV\Enhancements\EventAccommodation',
				'KWV\Enhancements\Brands',
				'KWV\Enhancements\WinemakersClub',
				'KWV\Enhancements\PayflexConfigurationCache',
			),
			$classes
		);

		foreach ( $this->modules( Plugin::instance() ) as $module ) {
			$this->assertInstanceOf( Module::class, $module );
		}
	}

	public function test_careers_modules_share_one_careers_instance(): void {
		$modules = $this->modules( Plugin::instance() );
		$careers = $modules[2];

		foreach ( array( 4, 5 ) as $index ) {
			$property = new \ReflectionProperty( get_class( $modules[ $index ] ), 'careers' );
			$property->setAccessible( true );
			$this->assertSame( $careers, $property->getValue( $modules[ $index ] ) );
		}
	}

	public function test_register_calls_register_on_every_module(): void {
		$plugin = Plugin::instance();
		$first  = Mockery::mock( Module::class );
		$second = Mockery::mock( Module::class );
		$first->shouldReceive( 'register' )->once();
		$second->shouldReceive( 'register' )->once();
		$this->setModules( $plugin, array( $first, $second ) );

		$plugin->register();
	}

	public function test_activate_runs_only_activatable_modules_then_flushes_once(): void {
		$plugin      = Plugin::instance();
		$activatable = Mockery::mock( Module::class, Activatable::class );
		$plain       = Mockery::mock( Module::class );
		$teardown    = Mockery::mock( Module::class, Deactivatable::class );

		$activatable->shouldReceive( 'activate' )->once();
		$plain->shouldNotReceive( 'activate' );
		$teardown->shouldNotReceive( 'deactivate' );
		$this->setModules( $plugin, array( $activatable, $plain, $teardown ) );

		Functions\expect( 'flush_rewrite_rules' )->once();

		$plugin->activate();
	}

	public function test_deactivate_runs_only_deactivatable_modules_then_flushes_once(): void {
		$plugin        = Plugin::instance();
		$deactivatable = Mockery::mock( Module::class, Deactivatable::class );
		$activatable   = Mockery::mock( Module::class, Activatable::class );

		$deactivatable->shouldReceive( 'deactivate' )->once();
		$activatable->shouldNotReceive( 'activate' );
		$this->setModules( $plugin, array( $deactivatable, $activatable ) );

		Functions\expect( 'flush_rewrite_rules' )->once();

		$plugin->deactivate();
	}
}
