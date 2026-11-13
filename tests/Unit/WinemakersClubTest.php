<?php
/**
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements\Tests\Unit;

use Brain\Monkey\Functions;
use KWV\Enhancements\WinemakersClub;
use Mockery;

/**
 * Unit tests: WooCommerce Subscriptions is commercial and not available to the
 * integration suite, so the product lookups are mocked.
 *
 * @covers \KWV\Enhancements\WinemakersClub
 */
final class WinemakersClubTest extends TestCase {

	/** @var WinemakersClub */
	private $club;

	protected function set_up(): void {
		parent::set_up();
		$this->club = new WinemakersClub();
		$this->captureRedirects();
		Functions\when( 'home_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.org' . $path;
			}
		);
		Functions\when( 'wc_get_checkout_url' )->justReturn( 'https://example.org/checkout/' );
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'add_query_arg' )->alias(
			static function ( $key, $value, $url ) {
				return $url . '?' . $key . '=' . $value;
			}
		);
	}

	/**
	 * A product double.
	 *
	 * @param string $type        Product type.
	 * @param string $status      Post status.
	 * @param bool   $purchasable Whether it can be bought.
	 * @return \Mockery\MockInterface
	 */
	private function product( string $type, string $status = 'publish', bool $purchasable = true ) {
		$product = Mockery::mock();
		$product->allows( 'get_type' )->andReturn( $type );
		$product->allows( 'get_status' )->andReturn( $status );
		$product->allows( 'is_purchasable' )->andReturn( $purchasable );

		return $product;
	}

	public function test_register_runs_the_join_handler_before_the_single_product_redirect(): void {
		$this->club->register();

		$this->assertSame( 5, has_action( 'template_redirect', 'KWV\Enhancements\WinemakersClub->maybe_handle_join()' ) );
		$this->assertSame( 10, has_action( 'template_redirect', 'KWV\Enhancements\WinemakersClub->maybe_redirect_single_subscription()' ) );
	}

	public function test_join_does_nothing_without_the_query_var(): void {
		Functions\expect( 'wp_safe_redirect' )->never();

		$this->club->maybe_handle_join();
		$this->addToAssertionCount( 1 );
	}

	/**
	 * @dataProvider current_edition_values
	 *
	 * @param string $value The kwv-join value.
	 */
	public function test_join_current_adds_the_newest_edition_at_checkout( string $value ): void {
		$_GET['kwv-join'] = $value;
		Functions\expect( 'wc_get_products' )->once()->with(
			Mockery::on(
				static function ( $args ) {
					return array( 'subscription', 'variable-subscription' ) === $args['type']
						&& array( 'wine-club-membership' ) === $args['category']
						&& 'publish' === $args['status']
						&& 'DESC' === $args['order']
						&& 'ids' === $args['return']
						&& 1 === $args['limit'];
				}
			)
		)->andReturn( array( 42 ) );

		$redirect = $this->expectRedirectFrom( array( $this->club, 'maybe_handle_join' ) );

		$this->assertSame( 'https://example.org/checkout/?add-to-cart=42', $redirect->location );
	}

	public static function current_edition_values(): array {
		return array(
			'current' => array( 'current' ),
			'empty'   => array( '' ),
		);
	}

	public function test_join_with_no_current_edition_goes_to_the_club_page(): void {
		$_GET['kwv-join'] = 'current';
		Functions\when( 'wc_get_products' )->justReturn( array() );

		$redirect = $this->expectRedirectFrom( array( $this->club, 'maybe_handle_join' ) );

		$this->assertSame( 'https://example.org/wineclub/', $redirect->location );
	}

	public function test_join_with_a_valid_subscription_id_adds_it(): void {
		$_GET['kwv-join'] = '77';
		Functions\expect( 'wc_get_product' )->once()->with( 77 )->andReturn( $this->product( 'variable-subscription' ) );

		$redirect = $this->expectRedirectFrom( array( $this->club, 'maybe_handle_join' ) );

		$this->assertSame( 'https://example.org/checkout/?add-to-cart=77', $redirect->location );
	}

	/**
	 * @dataProvider invalid_products
	 *
	 * @param string     $value   The kwv-join value.
	 * @param array|null $product Product type/status/purchasable, or null for "not found".
	 */
	public function test_join_refuses_anything_but_a_live_purchasable_subscription( string $value, ?array $product ): void {
		$_GET['kwv-join'] = $value;
		Functions\when( 'wc_get_product' )->justReturn( $product ? $this->product( ...$product ) : false );

		$redirect = $this->expectRedirectFrom( array( $this->club, 'maybe_handle_join' ) );

		$this->assertSame( 'https://example.org/wineclub/', $redirect->location );
	}

	public static function invalid_products(): array {
		return array(
			'negative id'     => array( '-5', null ),
			'not a number'    => array( 'abc', null ),
			'missing product' => array( '9', null ),
			'simple product'  => array( '9', array( 'simple' ) ),
			'draft'           => array( '9', array( 'subscription', 'draft' ) ),
			'not purchasable' => array( '9', array( 'subscription', 'publish', false ) ),
		);
	}

	public function test_single_subscription_product_redirects_permanently_to_the_club_page(): void {
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'wc_get_product' )->justReturn( $this->product( 'subscription' ) );

		$redirect = $this->expectRedirectFrom( array( $this->club, 'maybe_redirect_single_subscription' ) );

		$this->assertSame( 'https://example.org/wineclub/', $redirect->location );
		$this->assertSame( 301, $redirect->status );
	}

	public function test_other_single_products_are_left_alone(): void {
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'wc_get_product' )->justReturn( $this->product( 'simple' ) );

		$this->club->maybe_redirect_single_subscription();
		$this->addToAssertionCount( 1 ); // No redirect thrown.
	}

	public function test_non_product_pages_are_left_alone(): void {
		Functions\when( 'is_product' )->justReturn( false );
		Functions\expect( 'wc_get_product' )->never();

		$this->club->maybe_redirect_single_subscription();
		$this->addToAssertionCount( 1 );
	}

	public function test_missing_product_on_a_product_page_is_left_alone(): void {
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'wc_get_product' )->justReturn( false );

		$this->club->maybe_redirect_single_subscription();
		$this->addToAssertionCount( 1 );
	}

	public function test_join_with_product_id_zero_never_looks_a_product_up(): void {
		$_GET['kwv-join'] = '0';
		Functions\expect( 'wc_get_product' )->never();

		$redirect = $this->expectRedirectFrom( array( $this->club, 'maybe_handle_join' ) );

		$this->assertSame( 'https://example.org/wineclub/', $redirect->location );
	}

	public function test_join_redirects_with_an_integer_product_id(): void {
		$_GET['kwv-join'] = 'current';
		Functions\when( 'wc_get_products' )->justReturn( array( '42' ) );
		$added = null;
		Functions\when( 'add_query_arg' )->alias(
			static function ( $key, $value ) use ( &$added ) {
				$added = $value;
				return 'x';
			}
		);

		$this->expectRedirectFrom( array( $this->club, 'maybe_handle_join' ) );

		$this->assertSame( 42, $added );
	}
}
