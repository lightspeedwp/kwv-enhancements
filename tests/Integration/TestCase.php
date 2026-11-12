<?php
/**
 * Base class for integration tests.
 *
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements\Tests\Integration;

use KWV\Enhancements\Tests\Support\RedirectException;

/**
 * Adds KWV-specific helpers to the WordPress test case.
 */
abstract class TestCase extends \WP_UnitTestCase {

	/**
	 * The WP test case unregisters every meta key after each test, so put the
	 * plugin's back: on a real request `init` registers them every time.
	 */
	public function set_up(): void {
		parent::set_up();
		( new \KWV\Enhancements\Careers() )->register_meta();
		( new \KWV\Enhancements\Events() )->register_meta();
	}

	/**
	 * Skip the test when Gravity Forms is not installed (it is commercial, so
	 * CI does not have it).
	 */
	protected function requireGravityForms(): void {
		if ( ! class_exists( 'GFAPI' ) ) {
			$this->markTestSkipped( 'Gravity Forms is not available in this environment.' );
		}
	}

	/**
	 * Make wp_redirect() throw instead of sending headers, so code that calls
	 * `exit` after redirecting can be tested. The exception carries the target.
	 */
	protected function captureRedirects(): void {
		add_filter(
			'wp_redirect',
			static function ( $location, $status ) {
				throw new RedirectException( (string) $location, (int) $status );
			},
			PHP_INT_MAX,
			2
		);
	}

	/**
	 * Create an image attachment (no file on disk is needed: WordPress decides
	 * "is an image" from the MIME type and file extension).
	 *
	 * @param string $file Attached file name.
	 * @param string $alt  Alt text.
	 * @return int Attachment ID.
	 */
	protected function image( string $file = 'award.jpg', string $alt = '' ): int {
		$id = self::factory()->attachment->create_object(
			array(
				'file'           => $file,
				'post_mime_type' => 'image/jpeg',
				'post_title'     => $file,
			)
		);

		wp_update_attachment_metadata(
			$id,
			array(
				'width'  => 300,
				'height' => 200,
				'file'   => $file,
			)
		);

		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}

		return $id;
	}

	/**
	 * Create a non-image attachment.
	 *
	 * @return int Attachment ID.
	 */
	protected function pdf(): int {
		return self::factory()->attachment->create_object(
			array(
				'file'           => 'brochure.pdf',
				'post_mime_type' => 'application/pdf',
			)
		);
	}

	/**
	 * Today's date in the site's timezone, offset by a number of days, as the
	 * Ymd string SCF's date picker stores.
	 *
	 * @param int $days Offset in days (negative for the past).
	 * @return string
	 */
	protected function ymd( int $days = 0 ): string {
		return wp_date( 'Ymd', strtotime( sprintf( '%+d days', $days ), time() ) );
	}
}
