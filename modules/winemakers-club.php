<?php
/**
 * Winemaker's Club subscription flow.
 *
 * The Winemaker's Club is sold as a WooCommerce Subscriptions product whose
 * "edition" rotates every quarter. The /wineclub/ page is the real sales page
 * for it (hero, edition contents, benefits, pricing) — the generic single
 * product template is redundant for these products. This module wires that up:
 *
 *  - "Sign up now" buttons on /wineclub/ point at a static sentinel URL,
 *    `/?kwv-join=current`. This handler resolves the current (latest published)
 *    edition and hands off to WooCommerce's native add-to-cart-to-checkout URL,
 *    so the buttons never need editing when the edition rotates, and cart /
 *    mixed-checkout behaviour stays whatever WooCommerce + Subscriptions decide
 *    by default — we don't touch the cart ourselves.
 *
 *  - Single subscription-product URLs (still reachable via search, related
 *    products, or direct links) redirect to /wineclub/, so no one lands on the
 *    unstyled generic product page.
 *
 * Why a plugin (not the theme): this is purchase-flow business logic and must
 * survive a theme switch, per the KWV Enhancements charter.
 *
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes the Winemaker's Club subscription purchase flow.
 */
class WinemakersClub implements Module {

	/**
	 * Product-cat slug that identifies club-membership subscription products.
	 */
	const CATEGORY_SLUG = 'wine-club-membership';

	/**
	 * Query var the "Sign up now" buttons carry, e.g. `/?kwv-join=current`.
	 */
	const JOIN_VAR = 'kwv-join';

	/**
	 * WooCommerce product types treated as a club subscription.
	 *
	 * @var string[]
	 */
	const SUBSCRIPTION_TYPES = array( 'subscription', 'variable-subscription' );

	/**
	 * Register hooks.
	 */
	public function register(): void {
		// Handle the join sentinel before the single-product redirect, so a
		// `?kwv-join=` on any page (including a product page) wins.
		add_action( 'template_redirect', array( $this, 'maybe_handle_join' ), 5 );
		add_action( 'template_redirect', array( $this, 'maybe_redirect_single_subscription' ), 10 );
	}

	/**
	 * Resolve `/?kwv-join=…` to WooCommerce's native add-to-cart checkout URL.
	 *
	 * `current` (or empty) → the latest published edition; a numeric value →
	 * that product if it validates as a purchasable club subscription. Anything
	 * unresolvable falls back to the /wineclub/ page.
	 */
	public function maybe_handle_join(): void {
		if ( ! isset( $_GET[ self::JOIN_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing of a public "join" link, no state change here.
			return;
		}

		if ( ! function_exists( 'wc_get_checkout_url' ) ) {
			return;
		}

		$requested = sanitize_text_field( wp_unslash( $_GET[ self::JOIN_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.

		$product_id = ( '' === $requested || 'current' === $requested )
			? $this->current_edition_id()
			: $this->validate_subscription_id( (int) $requested );

		if ( ! $product_id ) {
			wp_safe_redirect( home_url( '/wineclub/' ) );
			exit;
		}

		// Hand off to WooCommerce's own add-to-cart handling on the checkout
		// page; it applies the site's cart / Subscriptions rules as configured.
		$url = add_query_arg( 'add-to-cart', $product_id, wc_get_checkout_url() );
		wp_safe_redirect( esc_url_raw( $url ) );
		exit;
	}

	/**
	 * Send single subscription-product pages to the /wineclub/ sales page.
	 */
	public function maybe_redirect_single_subscription(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$product = wc_get_product( get_queried_object_id() );

		if ( ! $product || ! in_array( $product->get_type(), self::SUBSCRIPTION_TYPES, true ) ) {
			return;
		}

		wp_safe_redirect( home_url( '/wineclub/' ), 301 );
		exit;
	}

	/**
	 * The latest published club-subscription product ("current edition").
	 *
	 * @return int Product ID, or 0 if none is published.
	 */
	private function current_edition_id(): int {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return 0;
		}

		$ids = wc_get_products(
			array(
				'type'     => self::SUBSCRIPTION_TYPES,
				'status'   => 'publish',
				'category' => array( self::CATEGORY_SLUG ),
				'limit'    => 1,
				'orderby'  => 'date',
				'order'    => 'DESC',
				'return'   => 'ids',
			)
		);

		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Confirm an explicit product ID is a purchasable, published club subscription.
	 *
	 * @param int $product_id Candidate product ID.
	 * @return int The ID if valid, else 0.
	 */
	private function validate_subscription_id( int $product_id ): int {
		if ( $product_id <= 0 ) {
			return 0;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product
			|| 'publish' !== $product->get_status()
			|| ! $product->is_purchasable()
			|| ! in_array( $product->get_type(), self::SUBSCRIPTION_TYPES, true ) ) {
			return 0;
		}

		return $product_id;
	}
}
