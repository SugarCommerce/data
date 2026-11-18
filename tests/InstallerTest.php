<?php
/**
 * Installing, and repairing a table that has gone.
 *
 * @package SugarCommerce\Data
 */

declare( strict_types=1 );

namespace SugarCommerce\Data\Tests;

use Acme\Database\Components\Core;
use Acme\Database\Tables\Events;
use SugarCommerce\Data\Components;

/**
 * The installer, against real tables.
 */
final class InstallerTest extends TestCase {

	/**
	 * Nothing is missing once installed, and installing again makes nothing.
	 *
	 * @return void
	 */
	public function test_installing_twice_creates_nothing(): void {
		$this->assertSame( [], acme_components()->installer->missing() );
		$this->assertSame( [], acme_components()->installer->install(), 'install() must be safe to run twice.' );
	}

	/**
	 * A table dropped behind BerlinDB's back is found and made again.
	 *
	 * @return void
	 */
	public function test_a_missing_table_is_recreated(): void {
		global $wpdb;

		$installer = acme_components()->installer;
		$table     = acme_get_component( 'event' )->table;
		$name      = $table->table_name;
		$repaired  = [];

		add_action(
			'acme_schema_repaired',
			static function ( array $created, array $missing ) use ( &$repaired ): void {
				$repaired = [ $created, $missing ];
			},
			10,
			2
		);

		$this->with_real_ddl(
			function () use ( $wpdb, $name, $table, $installer ): void {
				$wpdb->query( "DROP TABLE IF EXISTS `{$name}`" );

				$this->assertFalse( $table->exists(), 'The drop did not take effect.' );
				$this->assertSame( [ $name ], $installer->missing() );

				delete_transient( 'acme_schema_checked' );
				$installer->maybe_repair();
			}
		);

		$this->assertTrue( $table->exists(), 'The missing table was not recreated.' );
		$this->assertSame( [], $installer->missing() );
		$this->assertSame( [ [ $name ], [ $name ] ], $repaired );
	}

	/**
	 * A table that cannot be made again leaves no version behind claiming it.
	 *
	 * Otherwise BerlinDB would read the old version, think the table
	 * current, and never try again.
	 *
	 * @return void
	 */
	public function test_a_failed_repair_forgets_the_version(): void {
		global $wpdb;

		$installer = acme_components()->installer;
		$table     = acme_get_component( 'event' )->table;
		$name      = $table->table_name;
		$refuse    = static fn( string $query ): string => str_contains( $query, 'CREATE TABLE' ) && str_contains( $query, $name ) ? 'SELECT * FROM `acme_no_such_table`' : $query;

		// The table's record, as an install leaves it.
		update_option( 'wpdb_acme_events_version', '1' );

		$this->with_real_ddl(
			function () use ( $wpdb, $name, $table, $installer, $refuse ): void {
				$wpdb->query( "DROP TABLE IF EXISTS `{$name}`" );

				add_filter( 'query', $refuse );
				$suppressed = $wpdb->suppress_errors( true );

				try {
					delete_transient( 'acme_schema_checked' );
					$installer->maybe_repair();
				} finally {
					$wpdb->suppress_errors( $suppressed );
					remove_filter( 'query', $refuse );
				}

				$this->assertFalse( $table->exists(), 'The create was refused.' );
				$this->assertFalse( get_option( 'wpdb_acme_events_version' ), 'A version still claims a table that is not there.' );

				$installer->install();
			}
		);

		$this->assertTrue( $table->exists(), 'Put back.' );
	}

	/**
	 * An older table gains what it is missing when its version rises.
	 *
	 * BerlinDB's own behaviour, but the reason generated tables need no
	 * migrations -- so it is pinned here, against a generated class.
	 *
	 * @return void
	 */
	public function test_an_older_table_is_brought_up_to_date(): void {
		global $wpdb;

		$name    = acme_get_component( 'event' )->table->table_name;
		$columns = static fn(): array => (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$name}`" );

		$this->with_real_ddl(
			function () use ( $wpdb, $name, $columns ): void {
				$wpdb->query( "ALTER TABLE `{$name}` DROP COLUMN `error`" );
				$wpdb->query( "INSERT INTO `{$name}` ( event_ref, status ) VALUES ( 'evt_kept', 'processed' )" );

				$this->assertNotContains( 'error', $columns() );

				// What an older release left behind: a version below the
				// generated class's.
				update_option( 'wpdb_acme_events_version', '0' );
				delete_option( 'wpdb_acme_events_version_uninstalled' );

				( new Events() )->maybe_upgrade();

				$added = in_array( 'error', $columns(), true );

				if ( ! $added ) {
					// Put it back, so one failure does not become twenty.
					$wpdb->query( "ALTER TABLE `{$name}` ADD COLUMN `error` text NOT NULL AFTER `attempts`" );
				}

				$this->assertTrue( $added, 'The missing column was not added.' );
				$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$name}` WHERE event_ref = 'evt_kept'" ) );

				$wpdb->query( "DELETE FROM `{$name}` WHERE event_ref = 'evt_kept'" );
			}
		);
	}

	/**
	 * The check runs at most once an hour.
	 *
	 * @return void
	 */
	public function test_the_repair_check_is_rate_limited(): void {
		$installer = acme_components()->installer;

		delete_transient( 'acme_schema_checked' );
		$installer->maybe_repair();

		$this->assertSame( 'acme_schema_checked', $installer->check_transient() );
		$this->assertNotFalse( get_transient( 'acme_schema_checked' ) );

		$asked = self::queries( static fn() => $installer->maybe_repair() );

		$this->assertSame( [], array_filter( $asked, static fn( string $q ): bool => str_contains( $q, 'SHOW TABLES' ) ), 'A second check this hour asks nothing.' );
	}

	/**
	 * Nothing missing, nothing repaired.
	 *
	 * @return void
	 */
	public function test_repair_fires_only_when_something_was_missing(): void {
		$fired = 0;

		add_action(
			'acme_schema_repaired',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		delete_transient( 'acme_schema_checked' );
		acme_components()->installer->maybe_repair();

		$this->assertSame( 0, $fired );
	}

	/**
	 * init() hooks the check after BerlinDB's own upgrade.
	 *
	 * @return void
	 */
	public function test_the_check_is_hooked_after_berlindb(): void {
		$this->assertSame( 20, has_action( 'admin_init', [ acme_components()->installer, 'maybe_repair' ] ) );
	}

	/**
	 * Activation on a request where nothing was registered.
	 *
	 * @return void
	 */
	public function test_activation_registers_installs_and_announces(): void {
		$registry  = new Components( 'acme' );
		$announced = 0;
		$hooked    = 0;

		add_action(
			'acme_activated',
			static function () use ( &$announced ): void {
				++$announced;
			}
		);

		// What the plugin's own Core::init() would do, on this registry.
		$register = static function () use ( $registry, &$hooked ): void {
			++$hooked;

			add_action(
				'acme_register_components',
				static function () use ( $registry ): void {
					foreach ( acme_get_components() as $name => $component ) {
						$registry->register(
							$name,
							[
								'table' => get_class( $component->table ),
								'query' => $component->class_for( 'query' ),
							]
						);
					}
				}
			);
		};

		$this->assertSame( [], $registry->installer->activate( $register ), 'The tables are already there.' );
		$this->assertSame( 1, $hooked );
		$this->assertTrue( $registry->is_ready() );
		$this->assertSame( [ 'widget', 'event', 'counter' ], array_keys( $registry->all() ) );
		$this->assertSame( 1, $announced );

		$registry->installer->activate( $register );

		$this->assertSame( 1, $hooked, 'Already registered: not hooked again.' );
		$this->assertSame( 2, $announced );
		$this->assertTrue( class_exists( Core::class ) );
	}

	/**
	 * Whether the tables are there: a yes is remembered, a no is not.
	 *
	 * @return void
	 */
	public function test_tables_installed(): void {
		$installer = acme_components()->installer;

		$installer->reset();

		$this->assertTrue( $installer->tables_installed() );
		$this->assertSame( [], self::queries( static fn() => $installer->tables_installed() ), 'A yes is remembered.' );

		$absent = new Components( 'acme', [ 'probe' => 'nothing_here' ] );

		$this->assertFalse( $absent->installer->tables_installed() );
		$this->assertNotSame( [], self::queries( static fn() => $absent->installer->tables_installed() ), 'A no is asked again.' );

		$this->assertFalse( ( new Components( 'empty' ) )->installer->tables_installed(), 'Nothing registered, nothing to look for.' );

		$first = new Components( 'acme' );

		$first->register( 'counter', [ 'table' => get_class( acme_get_component( 'counter' )->table ) ] );

		$this->assertTrue( $first->installer->tables_installed(), 'Without a probe, the first component\'s table.' );
	}
}
