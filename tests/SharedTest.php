<?php
/**
 * What every query of a class shares.
 *
 * @package SugarCommerce\Data
 */

declare( strict_types=1 );

namespace SugarCommerce\Data\Tests;

use Acme\Database\Queries\Event;
use Acme\Database\Queries\Widget;
use Acme\Database\Rows\Event as EventRow;
use Acme\Database\Schemas\Widgets;
use SugarCommerce\Data\Tests\Support\CountedColumn;
use SugarCommerce\Data\Tests\Support\CountedWidget;
use SugarCommerce\Data\Tests\Support\CountedWidgets;
use SugarCommerce\Data\Tests\Support\GuardedEvent;

/**
 * One Schema a class, its lookups remembered, and who may read which
 * column worked out once a user -- with every row reading as it did.
 */
final class SharedTest extends TestCase {

	/**
	 * Events with an error only managers may read and a JSON payload.
	 *
	 * @param int $count How many.
	 *
	 * @return array<int, int> Their ids.
	 */
	private static function events( int $count ): array {
		$ids = [];

		for ( $i = 0; $i < $count; $i++ ) {
			$ids[] = (int) ( new Event() )->add_item(
				[
					'event_ref' => 'shared-' . wp_generate_password( 12, false ),
					'error'     => 'boom ' . $i,
					'payload'   => '{"n":' . $i . '}',
				]
			);
		}

		return $ids;
	}

	/**
	 * A user with a role.
	 *
	 * @param string $role The role.
	 *
	 * @return int
	 */
	private static function user( string $role ): int {
		return (int) self::factory()->user->create( [ 'role' => $role ] );
	}

	/**
	 * Count every capability check while a callback runs.
	 *
	 * @param callable $callback What to run.
	 *
	 * @return int
	 */
	private static function checks( callable $callback ): int {
		$count = 0;
		$spy   = static function ( $caps ) use ( &$count ) {
			++$count;

			return $caps;
		};

		add_filter( 'map_meta_cap', $spy );

		try {
			$callback();
		} finally {
			remove_filter( 'map_meta_cap', $spy );
		}

		return $count;
	}

	// ------------------------------------------------------------------
	// One Schema a class.
	// ------------------------------------------------------------------

	/**
	 * However many queries of a class are made, its Columns are built once.
	 *
	 * @return void
	 */
	public function test_every_query_of_a_class_shares_one_schema(): void {
		$first = new CountedWidget();
		$built = CountedColumn::$built;

		$this->assertGreaterThan( 0, $built );

		for ( $i = 0; $i < 50; $i++ ) {
			$query = new CountedWidget();

			$this->assertSame( $first->get_schema(), $query->get_schema() );
		}

		$this->assertSame( $built, CountedColumn::$built, 'A later query built Columns of its own.' );
		$this->assertNotSame( ( new Widget() )->get_schema(), ( new Event() )->get_schema() );
	}

	/**
	 * A class named with a leading backslash or without is one Schema.
	 *
	 * @return void
	 */
	public function test_a_schema_named_either_way_is_one(): void {
		$this->assertSame( ( new Widget() )->get_schema(), ( new Widget( [ 'table_schema' => Widgets::class ] ) )->get_schema() );
	}

	/**
	 * A Schema handed in is used as it is, not swapped for the shared one.
	 *
	 * @return void
	 */
	public function test_a_schema_passed_in_is_used_as_it_is(): void {
		$schema = new Widgets();

		$this->assertSame( $schema, ( new Widget( [ 'table_schema' => $schema ] ) )->get_schema() );
		$this->assertSame( [ 'id' ], ( new Widget( [ 'table_schema' => $schema ] ) )->get_column_names( [ 'cache_key' => true ] ) );
	}

	/**
	 * Reading cached rows asks the Schema nothing it has answered before.
	 *
	 * @return void
	 */
	public function test_column_lookups_are_remembered(): void {
		$ids = [];

		for ( $i = 0; $i < 20; $i++ ) {
			$ids[] = (int) ( new Widget() )->add_item( [ 'name' => 'Lookup ' . $i ] );
		}

		foreach ( $ids as $id ) {
			( new CountedWidget() )->get_item( $id );
		}

		CountedWidgets::$lookups = 0;

		foreach ( $ids as $id ) {
			$this->assertSame( $id, ( new CountedWidget() )->get_item( $id )->id );
		}

		$this->assertSame( 0, CountedWidgets::$lookups );
	}

	// ------------------------------------------------------------------
	// Rows as they were.
	// ------------------------------------------------------------------

	/**
	 * A row read from the cache is the row BerlinDB read from the database:
	 * the same class, columns, decoded JSON, and the columns left out.
	 *
	 * @return void
	 */
	public function test_a_cached_row_reads_as_it_did_from_the_database(): void {
		$ids = self::events( 3 );

		foreach ( [ 0, self::user( 'subscriber' ), self::user( 'administrator' ) ] as $user ) {
			wp_set_current_user( $user );
			wp_cache_flush();

			foreach ( $ids as $id ) {
				$read   = ( new GuardedEvent() )->get_item( $id );
				$cached = ( new GuardedEvent() )->get_item( $id );

				$this->assertInstanceOf( EventRow::class, $cached );
				$this->assertEquals( $read, $cached );
			}

			wp_cache_flush();

			$args   = [
				'id__in' => $ids,
				'fields' => [ 'id', 'error', 'payload' ],
			];
			$read   = ( new GuardedEvent() )->query( $args );
			$cached = ( new GuardedEvent() )->query( $args );

			$this->assertCount( 3, $cached );
			$this->assertEquals( $read, $cached );
		}

		wp_cache_flush();

		$widget = (int) ( new Widget() )->add_item( [ 'name' => 'Same' ] );
		$read   = ( new Widget() )->get_item( $widget );
		$cached = ( new Widget() )->get_item( $widget );

		$this->assertEquals( $read, $cached );
		$this->assertSame( 'Same!', $cached->label( '!' ) );
	}

	/**
	 * A row changed or deleted after it was cached reads as it now is.
	 *
	 * @return void
	 */
	public function test_a_changed_row_reads_changed(): void {
		$query = new Widget();
		$id    = (int) $query->add_item( [ 'name' => 'Before' ] );

		$this->assertSame( 'Before', ( new Widget() )->get_item( $id )->name );

		$query->update_item( $id, [ 'name' => 'After' ] );

		$this->assertSame( 'After', ( new Widget() )->get_item( $id )->name );

		$query->delete_item( $id );

		$this->assertFalse( ( new Widget() )->get_item( $id ) );
	}

	/**
	 * A column the user may not read is left out, and switching user mid-
	 * request switches the answer -- and back.
	 *
	 * @return void
	 */
	public function test_a_column_the_user_may_not_read_is_left_out(): void {
		$ids        = self::events( 2 );
		$subscriber = self::user( 'subscriber' );
		$admin      = self::user( 'administrator' );

		foreach ( [ $subscriber, $admin, $subscriber, $admin ] as $user ) {
			wp_set_current_user( $user );

			$may  = $admin === $user;
			$row  = ( new GuardedEvent() )->get_item( $ids[1] );
			$rows = ( new GuardedEvent() )->query( [ 'id__in' => $ids ] );

			$this->assertSame( $may ? 'boom 1' : null, $row->error );
			$this->assertNull( $row->attempts, 'A column naming no capability is nobody\'s.' );
			$this->assertSame( [ 'n' => 1 ], $row->payload );
			$this->assertSame( $ids[1], $row->id );
			$this->assertCount( 2, $rows );

			foreach ( $rows as $listed ) {
				$this->assertSame( $may, null !== $listed->error );
			}
		}
	}

	/**
	 * Each capability is asked once for a user, however many rows are read.
	 *
	 * @return void
	 */
	public function test_each_capability_is_asked_once_however_many_rows(): void {
		$ids = self::events( 25 );

		// Cached, by somebody else.
		( new GuardedEvent() )->query( [ 'id__in' => $ids ] );

		wp_set_current_user( self::user( 'subscriber' ) );

		$rows   = [];
		$checks = self::checks(
			static function () use ( $ids, &$rows ): void {
				$rows = ( new GuardedEvent() )->query( [ 'id__in' => $ids ] );

				foreach ( $ids as $id ) {
					( new GuardedEvent() )->get_item( $id );
				}
			}
		);

		$this->assertCount( 25, $rows );
		$this->assertSame( 2, $checks, 'One check each for exist and manage_options.' );
	}
}
