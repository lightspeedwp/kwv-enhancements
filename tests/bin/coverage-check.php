<?php
/**
 * Fail the build when line coverage in a Clover report is below a threshold.
 *
 * PHPUnit 9 has no built-in coverage gate, so `composer test:coverage` merges
 * the unit and integration coverage with phpcov and then runs this.
 *
 * Usage: php tests/bin/coverage-check.php <clover.xml> <minimum-percent>
 *
 * @package KWV_Enhancements
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput -- CLI script, not WordPress.

/**
 * Reads statement coverage from a Clover report and compares it to a minimum.
 */
final class KwvCoverageCheck {

	/**
	 * Path to the Clover XML report.
	 *
	 * @var string
	 */
	private $clover;

	/**
	 * Minimum line coverage, in percent.
	 *
	 * @var float
	 */
	private $minimum;

	/**
	 * @param string $clover  Path to the Clover XML report.
	 * @param float  $minimum Minimum line coverage, in percent.
	 */
	public function __construct( string $clover, float $minimum ) {
		$this->clover  = $clover;
		$this->minimum = $minimum;
	}

	/**
	 * Run the check and return a process exit code.
	 *
	 * @return int 0 when coverage meets the minimum, 1 otherwise.
	 */
	public function run(): int {

		if ( ! is_readable( $this->clover ) ) {
			fwrite( STDERR, "Coverage report not found: {$this->clover}\n" );
			return 1;
		}

		$xml = simplexml_load_file( $this->clover );

		if ( false === $xml || ! isset( $xml->project->metrics ) ) {
			fwrite( STDERR, "Not a Clover report: {$this->clover}\n" );
			return 1;
		}

		$metrics   = $xml->project->metrics;
		$total     = (int) $metrics['statements'];
		$covered   = (int) $metrics['coveredstatements'];
		$percent   = $total > 0 ? $covered / $total * 100 : 0.0;
		$formatted = sprintf( '%.2f%% (%d/%d lines)', $percent, $covered, $total );

		if ( $percent < $this->minimum ) {
			fwrite( STDERR, sprintf( "Line coverage %s is below the %.0f%% minimum.\n", $formatted, $this->minimum ) );
			return 1;
		}

		printf( "Line coverage %s meets the %.0f%% minimum.\n", $formatted, $this->minimum );
		return 0;
	}
}

if ( $argc < 3 ) {
	fwrite( STDERR, "Usage: php tests/bin/coverage-check.php <clover.xml> <minimum-percent>\n" );
	exit( 1 );
}

exit( ( new KwvCoverageCheck( $argv[1], (float) $argv[2] ) )->run() );
