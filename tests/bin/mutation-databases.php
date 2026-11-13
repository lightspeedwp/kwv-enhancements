<?php
/**
 * Prepare one integration-test database per Infection thread.
 *
 * Infection runs mutants in parallel and gives each process a TEST_TOKEN
 * (0 … threads-1); wp-tests-config.php appends `_t<token>` to the database
 * name. This creates those databases and installs WordPress into each once,
 * so the mutant runs can skip the slow reinstall (WP_TESTS_SKIP_INSTALL=1).
 *
 * Usage: php tests/bin/mutation-databases.php <threads>
 *
 * @package KWV_Enhancements
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput, WordPress.DB.RestrictedClasses, WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- CLI script, not WordPress.

/**
 * Creates and installs the per-thread test databases.
 */
final class KwvMutationDatabases {

	/**
	 * Number of Infection threads.
	 *
	 * @var int
	 */
	private $threads;

	/**
	 * Plugin root.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * @param int    $threads Number of Infection threads.
	 * @param string $root    Plugin root.
	 */
	public function __construct( int $threads, string $root ) {
		$this->threads = $threads;
		$this->root    = $root;
	}

	/**
	 * Read a connection setting the same way wp-tests-config.php does.
	 *
	 * @param string $name     Environment variable.
	 * @param string $fallback Default.
	 * @return string
	 */
	private function env( string $name, string $fallback ): string {
		$value = getenv( $name );

		return false === $value || '' === $value ? $fallback : $value;
	}

	/**
	 * Create every database, then install WordPress into each.
	 *
	 * @return int Exit code.
	 */
	public function run(): int {

		$host = explode( ':', $this->env( 'WP_TESTS_DB_HOST', '127.0.0.1' ) );
		$db   = new mysqli( $host[0], $this->env( 'WP_TESTS_DB_USER', 'root' ), $this->env( 'WP_TESTS_DB_PASSWORD', '' ), '', (int) ( $host[1] ?? 3306 ) );
		$base = $this->env( 'WP_TESTS_DB_NAME', 'kwv_enhancements_tests' );

		for ( $token = 0; $token < $this->threads; $token++ ) {
			$name = $db->real_escape_string( "{$base}_t{$token}" );
			$db->query( "CREATE DATABASE IF NOT EXISTS `{$name}`" );

			echo "Installing WordPress into {$name}\n";
			$command = sprintf(
				'TEST_TOKEN=%d %s %s -c %s --filter PluginBootTest',
				$token,
				escapeshellarg( PHP_BINARY ),
				escapeshellarg( $this->root . '/vendor/bin/phpunit' ),
				escapeshellarg( $this->root . '/phpunit.integration.xml.dist' )
			);
			exec( $command . ' 2>&1', $output, $status );

			if ( 0 !== $status ) {
				fwrite( STDERR, implode( "\n", $output ) . "\n" );
				return $status;
			}
		}

		return 0;
	}
}

exit( ( new KwvMutationDatabases( (int) ( $argv[1] ?? 6 ), dirname( __DIR__, 2 ) ) )->run() );
