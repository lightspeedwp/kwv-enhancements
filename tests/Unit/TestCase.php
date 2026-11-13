<?php
/**
 * Base class for unit tests: Brain Monkey set-up/tear-down plus helpers.
 *
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use KWV\Enhancements\Tests\Support\RedirectException;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Yoast\PHPUnitPolyfills\TestCases\TestCase as PolyfillTestCase;

/**
 * Unit test case with WordPress functions mocked by Brain Monkey.
 */
abstract class TestCase extends PolyfillTestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * In-memory options for {@see stubOptions()}.
	 *
	 * @var array<string,mixed>
	 */
	protected $options = array();

	protected function set_up(): void {
		parent::set_up();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		\GFAPI::reset();
		$_GET  = array();
		$_POST = array();
	}

	protected function tear_down(): void {
		Monkey\tearDown();
		$_GET  = array();
		$_POST = array();
		parent::tear_down();
	}

	/**
	 * Back get_option()/update_option() with $this->options.
	 *
	 * @param array<string,mixed> $initial Starting options.
	 */
	protected function stubOptions( array $initial = array() ): void {
		$this->options = $initial;

		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
	}

	/**
	 * Make wp_safe_redirect() throw, so code that exits after redirecting can be
	 * asserted on.
	 */
	protected function captureRedirects(): void {
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( $location, $status = 302 ) {
				throw new RedirectException( (string) $location, (int) $status );
			}
		);
	}

	/**
	 * Run a callable that is expected to redirect, and return the redirect.
	 *
	 * @param callable $callback Code under test.
	 * @return RedirectException
	 */
	protected function expectRedirectFrom( callable $callback ): RedirectException {
		try {
			$callback();
		} catch ( RedirectException $redirect ) {
			return $redirect;
		}

		$this->fail( 'Expected a redirect.' );
	}
}
