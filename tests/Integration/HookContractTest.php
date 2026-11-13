<?php
/**
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements\Tests\Integration;

use KWV\Enhancements\Tests\Support\PluginHooks;

/**
 * Every hook the plugin registers, with its priority and argument count, as
 * WordPress actually holds them after the plugin loads. The expected table is
 * shared with the unit suite (tests/Support/PluginHooks.php).
 *
 * Getting either wrong fails quietly in WordPress: a callback that asks for
 * too few arguments never sees the one it needs, and a priority change can
 * reorder it against WooCommerce or Gravity Forms. This pins the full set, so
 * adding, removing or re-wiring a hook is a deliberate, reviewed change here.
 *
 * @coversNothing
 */
final class HookContractTest extends TestCase {

	/**
	 * Every callback on an object in the plugin's namespace, in the same form
	 * as the tables above.
	 *
	 * @return array<string,int[]>
	 */
	private function registered(): array {
		global $wp_filter;

		$found = array();

		foreach ( $wp_filter as $hook => $wp_hook ) {
			foreach ( $wp_hook->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'];

					if ( ! is_array( $function ) || ! is_object( $function[0] ) ) {
						continue;
					}

					$class = get_class( $function[0] );

					if ( 0 !== strpos( $class, 'KWV\\Enhancements\\' ) || 0 === strpos( $class, 'KWV\\Enhancements\\Tests\\' ) ) {
						continue;
					}

					// The activation hooks are named after the plugin file's path.
					$hook = preg_replace( '#^(de)?activate_.*kwv-enhancements\.php$#', '$1activate_kwv-enhancements', (string) $hook );

					$key           = $hook . ' | ' . substr( $class, strlen( 'KWV\\Enhancements\\' ) ) . '::' . $function[1];
					$found[ $key ] = array( (int) $priority, (int) $callback['accepted_args'] );
				}
			}
		}

		ksort( $found );

		return $found;
	}

	public function test_the_plugin_registers_exactly_these_hooks(): void {
		$expected = PluginHooks::HOOKS + PluginHooks::ACTIVATION_HOOKS + ( class_exists( 'GFAPI' ) ? PluginHooks::GRAVITY_FORMS_HOOKS : array() );
		ksort( $expected );

		$this->assertSame( $expected, $this->registered() );
	}

	public function test_no_callback_requires_more_arguments_than_it_is_given(): void {
		foreach ( $this->registered() as $key => list( , $accepted ) ) {
			list( , $method )     = explode( ' | ', $key );
			list( $class, $name ) = explode( '::', $method );

			$required = ( new \ReflectionMethod( 'KWV\\Enhancements\\' . $class, $name ) )->getNumberOfRequiredParameters();

			$this->assertLessThanOrEqual( $accepted, $required, "{$key} is given {$accepted} argument(s) but requires {$required}: WordPress would fatal." );
		}
	}
}
