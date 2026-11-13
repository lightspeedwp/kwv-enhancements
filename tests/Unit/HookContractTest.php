<?php
/**
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements\Tests\Unit;

use Brain\Monkey\Container;
use Brain\Monkey\Functions;
use KWV\Enhancements\ApplicationContext;
use KWV\Enhancements\BookingForm;
use KWV\Enhancements\Plugin;
use KWV\Enhancements\Tests\Support\PluginHooks;

/**
 * Registering the plugin adds exactly the hooks in tests/Support/PluginHooks.php,
 * with those priorities and argument counts. (The integration suite checks the
 * same table against WordPress; this one is what mutation testing can see,
 * because there registration happens inside the test.)
 *
 * @covers \KWV\Enhancements\Plugin
 * @covers \KWV\Enhancements\AcfJson
 * @covers \KWV\Enhancements\BlockBindings
 * @covers \KWV\Enhancements\PostTypeModule
 * @covers \KWV\Enhancements\Careers
 * @covers \KWV\Enhancements\ApplicationForm
 * @covers \KWV\Enhancements\ApplicationContext
 * @covers \KWV\Enhancements\CareersModal
 * @covers \KWV\Enhancements\Events
 * @covers \KWV\Enhancements\BookingForm
 * @covers \KWV\Enhancements\EventAccommodation
 * @covers \KWV\Enhancements\Brands
 * @covers \KWV\Enhancements\WinemakersClub
 * @covers \KWV\Enhancements\PayflexConfigurationCache
 */
final class HookContractTest extends TestCase {

	protected function tear_down(): void {
		$property = new \ReflectionProperty( Plugin::class, 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );
		parent::tear_down();
	}

	/**
	 * Brain Monkey's record of every add_action()/add_filter() call, in the
	 * contract's "hook | Class::method" => [priority, accepted args] form.
	 *
	 * @return array<string,int[]>
	 */
	private function added(): array {
		$storage = new \ReflectionProperty( get_class( Container::instance()->hookStorage() ), 'storage' );
		$storage->setAccessible( true );
		$added = $storage->getValue( Container::instance()->hookStorage() )['added'] ?? array();

		$found = array();
		foreach ( $added as $hooks ) {
			foreach ( $hooks as $hook => $calls ) {
				foreach ( $calls as list( $callback, $priority, $accepted ) ) {
					// Brain Monkey records a callback as Class->method(); the contract uses Class::method.
					$method = preg_replace( '/^KWV\\\\Enhancements\\\\(\w+)->(\w+)\(\)$/', '$1::$2', (string) $callback );

					$found[ $hook . ' | ' . $method ] = array( $priority, $accepted );
				}
			}
		}
		ksort( $found );

		return $found;
	}

	public function test_registering_the_plugin_adds_exactly_the_contracted_hooks(): void {
		Functions\expect( 'add_shortcode' )->once()->with(
			'kwv_career_form',
			\Mockery::on(
				static function ( $callback ) {
					return is_array( $callback ) && $callback[0] instanceof \KWV\Enhancements\Careers && 'render_form' === $callback[1];
				}
			)
		);

		$plugin = Plugin::instance();
		$plugin->register();

		// The Gravity Forms hooks are added on init, when GFAPI exists (the
		// unit suite's fake does).
		$modules = new \ReflectionProperty( Plugin::class, 'modules' );
		$modules->setAccessible( true );
		foreach ( $modules->getValue( $plugin ) as $module ) {
			if ( $module instanceof ApplicationContext || $module instanceof BookingForm ) {
				$module->register_hooks();
			}
		}

		$expected = PluginHooks::HOOKS + PluginHooks::GRAVITY_FORMS_HOOKS;
		ksort( $expected );

		$this->assertSame( $expected, $this->added() );
	}
}
