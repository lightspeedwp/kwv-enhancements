<?php
/**
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements\Tests\Unit;

use Brain\Monkey\Functions;
use KWV\Enhancements\PayflexConfigurationCache;

/**
 * @covers \KWV\Enhancements\PayflexConfigurationCache
 */
final class PayflexConfigurationCacheTest extends TestCase {

	private const CONFIG_URL = 'https://api.payflex.co.za/configuration';

	/** @var PayflexConfigurationCache */
	private $cache;

	protected function set_up(): void {
		parent::set_up();
		$this->cache = new PayflexConfigurationCache();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return $response['response']['code'] ?? '';
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ) {
				return $response['body'] ?? '';
			}
		);
	}

	private function response( int $code, string $body = '{"minimumAmount":50}' ): array {
		return array(
			'headers'  => array( 'x' => 'y' ),
			'body'     => $body,
			'response' => array( 'code' => $code ),
		);
	}

	public function test_register_hooks_both_http_filters(): void {
		$this->cache->register();

		$this->assertNotFalse( has_filter( 'pre_http_request', 'KWV\Enhancements\PayflexConfigurationCache->serve_from_cache()' ) );
		$this->assertNotFalse( has_filter( 'http_response', 'KWV\Enhancements\PayflexConfigurationCache->store_in_cache()' ) );
	}

	public function test_serves_a_cached_configuration_response(): void {
		$cached = $this->response( 200 );
		Functions\expect( 'get_transient' )->once()->with( 'kwv_payflex_configuration_response' )->andReturn( $cached );

		$this->assertSame( $cached, $this->cache->serve_from_cache( false, array( 'method' => 'GET' ), self::CONFIG_URL ) );
	}

	public function test_serves_the_uat_host_too(): void {
		Functions\when( 'get_transient' )->justReturn( array( 'body' => 'x' ) );

		$this->assertSame( array( 'body' => 'x' ), $this->cache->serve_from_cache( false, array(), 'https://api.uat.payflex.co.za/configuration' ) );
	}

	public function test_lets_the_request_through_on_a_cache_miss(): void {
		Functions\when( 'get_transient' )->justReturn( false );

		$this->assertFalse( $this->cache->serve_from_cache( false, array( 'method' => 'GET' ), self::CONFIG_URL ) );
	}

	public function test_never_overrides_another_short_circuit(): void {
		Functions\expect( 'get_transient' )->never();
		$preempt = array( 'body' => 'other' );

		$this->assertSame( $preempt, $this->cache->serve_from_cache( $preempt, array(), self::CONFIG_URL ) );
	}

	/**
	 * @dataProvider uncached_requests
	 *
	 * @param array  $args Request args.
	 * @param string $url  Request URL.
	 */
	public function test_ignores_requests_other_than_the_configuration_get( array $args, string $url ): void {
		Functions\expect( 'get_transient' )->never();
		Functions\expect( 'set_transient' )->never();

		$this->assertFalse( $this->cache->serve_from_cache( false, $args, $url ) );
		$this->assertSame( array( 'a' ), $this->cache->store_in_cache( array( 'a' ), $args, $url ) );
	}

	/**
	 * @return array<string,array{0:array,1:string}>
	 */
	public function uncached_requests(): array {
		return array(
			'POST'          => array( array( 'method' => 'post' ), self::CONFIG_URL ),
			'auth endpoint' => array( array( 'method' => 'GET' ), 'https://api.payflex.co.za/auth/merchant' ),
			'other host'    => array( array( 'method' => 'GET' ), 'https://evil.example/configuration' ),
			'lookalike'     => array( array(), 'https://api.payflex.co.za.evil.example/configuration' ),
		);
	}

	public function test_caches_a_successful_response_for_twelve_hours(): void {
		Functions\expect( 'set_transient' )->once()->with(
			'kwv_payflex_configuration_response',
			array(
				'headers'  => array(),
				'body'     => '{"minimumAmount":50}',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			),
			12 * HOUR_IN_SECONDS
		);

		$response = $this->response( 200 );
		$this->assertSame( $response, $this->cache->store_in_cache( $response, array( 'method' => 'GET' ), self::CONFIG_URL ) );
	}

	public function test_never_caches_a_failure(): void {
		Functions\expect( 'set_transient' )->never();

		$response = $this->response( 500 );
		$this->assertSame( $response, $this->cache->store_in_cache( $response, array(), self::CONFIG_URL ) );
	}
}
