<?php
/**
 * Golden-file ("snapshot") assertions.
 *
 * A snapshot test stores the full output of something (a provisioned Gravity
 * Form, an email, a rendered block) in tests/__snapshots__/ and fails when the
 * output changes in any way. It catches the accidental edit that a targeted
 * assertion would miss: a dropped field, a broken style in an email, a lost
 * attribute in block markup.
 *
 * When a change is intended, regenerate and review the diff in git:
 *
 *   UPDATE_SNAPSHOTS=1 composer test
 *
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements\Tests\Support;

/**
 * Adds assertMatchesSnapshot() to a test case.
 */
trait Snapshots {

	/**
	 * Assert that a value matches its stored snapshot.
	 *
	 * Arrays and objects are stored as pretty-printed JSON, strings as-is.
	 * A missing snapshot is written and the test is marked incomplete, so a
	 * new snapshot is never silently accepted in CI (where CI=true fails it).
	 *
	 * @param mixed  $actual The value under test.
	 * @param string $name   Snapshot file name, e.g. "careers-form.json".
	 */
	protected function assertMatchesSnapshot( $actual, string $name ): void {

		$file   = dirname( __DIR__ ) . '/__snapshots__/' . $name;
		$actual = is_string( $actual )
			? $actual
			: (string) json_encode( $actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress may not be loaded (unit suite).

		if ( '1' === getenv( 'UPDATE_SNAPSHOTS' ) || ! file_exists( $file ) ) {

			if ( ! file_exists( $file ) && getenv( 'CI' ) ) {
				$this->fail( "Snapshot {$name} is missing. Run UPDATE_SNAPSHOTS=1 composer test locally and commit it." );
			}

			if ( ! is_dir( dirname( $file ) ) ) {
				mkdir( dirname( $file ), 0777, true );
			}

			$existed = file_exists( $file );
			file_put_contents( $file, $actual ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

			if ( ! $existed ) {
				$this->markTestIncomplete( "Snapshot {$name} written; review it and run the tests again." );
			}
		}

		$this->assertSame( file_get_contents( $file ), $actual, "Output differs from snapshot {$name}. If the change is intended: UPDATE_SNAPSHOTS=1 composer test, then review the diff." ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Replace run-specific IDs in markup with stable placeholders, so a
	 * snapshot does not depend on database auto-increment values.
	 *
	 * @param string             $html Markup.
	 * @param array<string,int>  $ids  Placeholder => ID, e.g. array( 'AWARD_1' => 37 ).
	 * @return string
	 */
	protected function withStableIds( string $html, array $ids ): string {
		foreach ( $ids as $placeholder => $id ) {
			// Only where an ID appears in this markup (wp-image-18, tpl-18,
			// data-kwv-…="18", "id":18), never in other numbers such as an SVG
			// width="18" or path data (L12, 4.13).
			$html = (string) preg_replace( '/(?:-|data-[a-z-]+="|"id":)\K' . (int) $id . '(?![\d.])/', '{' . $placeholder . '}', $html );
		}

		// wp_unique_id() counters depend on how many blocks rendered before.
		return (string) preg_replace( '/(kwv-event-accommodation-)\d+/', '$1{N}', $html );
	}
}
