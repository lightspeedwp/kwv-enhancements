<?php
/**
 * Caches the Payflex `/configuration` API response.
 *
 * Works around a bug in Payflex Payment Gateway 2.7.1
 * (`includes/class-wc-gateway-payflex.php:964-970`):
 *
 *     public function get_payflex_limits( $field = false ) {
 *         if ( ! isset( $settings['payflex_limit_last_updated'] ) || ... ) {
 *             $this->update_payment_limits();
 *         }
 *
 *         $settings = get_payflex_option();   // assigned AFTER it is read
 *
 * `$settings` is read one line before it is populated, so the `isset()` is
 * always false and the intended 24-hour throttle never applies.
 * `update_payment_limits()` therefore issues a blocking remote GET on every
 * call, and `get_payflex_limits()` runs from `check_cart_within_limits()`,
 * hooked to `woocommerce_available_payment_gateways` — which WooCommerce fires
 * several times per page render (cart and mini-cart blocks among them).
 *
 * Measured on kwv.lightspeedwp.dev, 2026-08-10: eight blocking Payflex calls
 * per render at ~0.81s each, for a 7.9s origin TTFB on
 * /product-category/wine/. Caching this one response took the page to ~1.1s.
 *
 * Only the `/configuration` GET is cached. The auth token is left alone —
 * the gateway already caches it in the `payflex_access_token` transient with
 * the expiry the API returns, and short-circuiting that would risk serving an
 * expired token.
 *
 * Remove this module once Payflex ship a fix. Reported upstream; see README.
 *
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Short-circuits repeat Payflex `/configuration` requests from a cached copy.
 */
class PayflexConfigurationCache implements Module {

	/**
	 * Transient holding the cached `wp_remote_get()` response array.
	 */
	private const CACHE_KEY = 'kwv_payflex_configuration_response';

	/**
	 * How long to hold the cached response.
	 *
	 * The limits it carries (minimum/maximum order value, refunds enabled)
	 * change rarely, and the gateway's own intent was a 24-hour refresh.
	 * Twelve hours keeps us well inside that while halving the staleness.
	 */
	private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * API hosts whose `/configuration` endpoint we cache.
	 *
	 * @var string[]
	 */
	private const HOSTS = array( 'api.payflex.co.za', 'api.uat.payflex.co.za' );

	/**
	 * Register the HTTP filters.
	 */
	public function register(): void {
		add_filter( 'pre_http_request', array( $this, 'serve_from_cache' ), 10, 3 );
		add_filter( 'http_response', array( $this, 'store_in_cache' ), 10, 3 );
	}

	/**
	 * Return the cached response instead of making the request.
	 *
	 * @param false|array|\WP_Error $preempt Short-circuit value, false to proceed.
	 * @param array                 $args    Request arguments.
	 * @param string                $url     Request URL.
	 * @return false|array|\WP_Error
	 */
	public function serve_from_cache( $preempt, $args, $url ) {
		if ( false !== $preempt || ! $this->is_configuration_request( $url, $args ) ) {
			return $preempt;
		}

		$cached = get_transient( self::CACHE_KEY );

		return is_array( $cached ) ? $cached : $preempt;
	}

	/**
	 * Cache a successful response for subsequent requests.
	 *
	 * @param array  $response Response array.
	 * @param array  $args     Request arguments.
	 * @param string $url      Request URL.
	 * @return array
	 */
	public function store_in_cache( $response, $args, $url ) {
		if ( ! $this->is_configuration_request( $url, $args ) ) {
			return $response;
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return $response; // Never cache a failure — the gateway must be free to retry.
		}

		set_transient(
			self::CACHE_KEY,
			array(
				'headers'  => array(),
				'body'     => wp_remote_retrieve_body( $response ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			),
			self::CACHE_TTL
		);

		return $response;
	}

	/**
	 * Is this a GET of the Payflex configuration endpoint?
	 *
	 * @param string $url  Request URL.
	 * @param array  $args Request arguments.
	 * @return bool
	 */
	private function is_configuration_request( $url, $args ): bool {
		if ( 'GET' !== strtoupper( $args['method'] ?? 'GET' ) ) {
			return false;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );

		return in_array( $host, self::HOSTS, true )
			&& '/configuration' === wp_parse_url( $url, PHP_URL_PATH );
	}
}
