<?php
/**
 * Shared set-up for the suite.
 *
 * @package SugarCommerce\Data
 */

declare( strict_types=1 );

namespace SugarCommerce\Data\Tests;

use WP_UnitTestCase;

/**
 * Real tables, installed once.
 */
abstract class TestCase extends WP_UnitTestCase {

	/**
	 * Whether the tables have been installed in this run.
	 *
	 * @var bool
	 */
	private static bool $installed = false;

	/**
	 * Off: the WordPress test case reads @expectedDeprecated annotations
	 * through a PHPUnit method that PHPUnit 12 no longer has, and nothing
	 * here expects a deprecation.
	 *
	 * @return void
	 */
	public function expectDeprecated(): void {}

	/**
	 * Off, with expectDeprecated().
	 *
	 * @return void
	 */
	public function expectedDeprecated(): void {}

	/**
	 * Install the fixture plugin's tables, from empty, once per run.
	 *
	 * @return void
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		if ( self::$installed ) {
			return;
		}

		global $wpdb;

		// The drop is expected to fail for anything not there yet, and that
		// failure is not worth putting in the output.
		$suppressed = $wpdb->suppress_errors( true );

		foreach ( acme_get_components() as $component ) {
			foreach ( $component->tables() as $table ) {
				$table->uninstall();
				$table->install();
			}
		}

		$wpdb->suppress_errors( $suppressed );

		self::$installed = true;
	}

	/**
	 * Run real DDL: the test framework turns CREATE and DROP TABLE into
	 * their TEMPORARY forms, which is right for everything except a test
	 * about a real table going missing.
	 *
	 * @param callable $callback What to run.
	 *
	 * @return mixed What it returned.
	 */
	protected function with_real_ddl( callable $callback ): mixed {
		remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );

		try {
			return $callback();
		} finally {
			add_filter( 'query', [ $this, '_create_temporary_tables' ] );
			add_filter( 'query', [ $this, '_drop_temporary_tables' ] );
		}
	}

	/**
	 * Every statement run while a callback runs.
	 *
	 * @param callable $callback What to run.
	 *
	 * @return array<int, string>
	 */
	protected static function queries( callable $callback ): array {
		$sql = [];
		$log = static function ( string $query ) use ( &$sql ): string {
			$sql[] = $query;

			return $query;
		};

		add_filter( 'query', $log );

		try {
			$callback();
		} finally {
			remove_filter( 'query', $log );
		}

		return $sql;
	}
}
