<?php
/**
 * Thrown by tests in place of a redirect, so code that exits after
 * redirecting can be asserted on.
 *
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements\Tests\Support;

/**
 * A captured redirect: the target location and HTTP status.
 */
final class RedirectException extends \RuntimeException {

	/**
	 * The redirect target.
	 *
	 * @var string
	 */
	public $location;

	/**
	 * The HTTP status.
	 *
	 * @var int
	 */
	public $status;

	/**
	 * @param string $location Redirect target.
	 * @param int    $status   HTTP status.
	 */
	public function __construct( string $location, int $status = 302 ) {
		parent::__construct( sprintf( 'Redirect (%d) to %s', $status, $location ) );
		$this->location = $location;
		$this->status   = $status;
	}
}
