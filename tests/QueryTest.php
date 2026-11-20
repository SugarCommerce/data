<?php
/**
 * What the base Query adds to BerlinDB's.
 *
 * @package SugarCommerce\Data
 */

declare( strict_types=1 );

namespace SugarCommerce\Data\Tests;

use Acme\Database\Queries\Counter;
use Acme\Database\Queries\Event;
use Acme\Database\Queries\Widget;
use SugarCommerce\Data\Tests\Support\GuardedEvent;

/**
 * Insert defaults, a stable order, and the race-free writes.
 */
final class QueryTest extends TestCase {

	/**
	 * A widget's row, read past every cache.
	 *
	 * @param int $id The widget.
	 *
	 * @return array<string, mixed>
	 */
	private static function raw( int $id ): array {
		global $wpdb;

		return (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $wpdb->prefix . 'acme_widgets', $id ), ARRAY_A );
	}

	/**
	 * An event's row, read past every cache.
	 *
	 * @param int $id The event.
	 *
	 * @return array<string, mixed>
	 */
	private static function raw_event( int $id ): array {
		global $wpdb;

		return (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $wpdb->prefix . 'acme_events', $id ), ARRAY_A );
	}

	/**
	 * Set when a row last changed, past the query layer.
	 *
	 * @param int    $id      The event.
	 * @param string $status  Its state.
	 * @param int    $seconds How long ago it changed.
	 *
	 * @return void
	 */
	private static function age_event( int $id, string $status, int $seconds ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'acme_events',
			[
				'status'        => $status,
				'date_modified' => gmdate( 'Y-m-d H:i:s', time() - $seconds ),
			],
			[ 'id' => $id ]
		);

		wp_cache_flush();
	}

	// ------------------------------------------------------------------
	// Insert defaults.
	// ------------------------------------------------------------------

	/**
	 * A column left out is filled from its function.
	 *
	 * @return void
	 */
	public function test_a_left_out_column_gets_its_default(): void {
		update_option( 'acme_currency', 'EUR' );

		$this->assertSame( 'EUR', self::raw( (int) acme_add_widget( [ 'name' => 'A' ] ) )['currency'] );
		$this->assertSame( 'EUR', self::raw( (int) acme_add_widget( [ 'name' => 'B', 'currency' => '' ] ) )['currency'], 'Empty counts as left out.' );
		$this->assertSame( 'EUR', self::raw( (int) acme_add_widget( [ 'name' => 'C', 'currency' => null ] ) )['currency'], 'Null counts as left out.' );
	}

	/**
	 * A value given is kept.
	 *
	 * @return void
	 */
	public function test_a_given_value_is_kept(): void {
		update_option( 'acme_currency', 'EUR' );

		$this->assertSame( 'GBP', self::raw( (int) acme_add_widget( [ 'name' => 'A', 'currency' => 'GBP' ] ) )['currency'] );
	}

	/**
	 * Defaults are for a new row only: an update never moves the column.
	 *
	 * @return void
	 */
	public function test_an_update_does_not_fill_defaults(): void {
		$id = (int) acme_add_widget(
			[
				'name'     => 'A',
				'currency' => 'GBP',
			]
		);

		update_option( 'acme_currency', 'EUR' );
		acme_update_widget( $id, [ 'name' => 'B' ] );

		$this->assertSame( 'GBP', self::raw( $id )['currency'] );

		acme_update_widget( $id, [ 'currency' => '' ] );

		$this->assertSame( '', self::raw( $id )['currency'], 'Emptied on purpose is taken at its word.' );
	}

	/**
	 * Nothing given is given nothing: BerlinDB writes an empty insert as a row
	 * of column defaults, and a function default is not added to it.
	 *
	 * @return void
	 */
	public function test_an_empty_write_gets_no_function_defaults(): void {
		update_option( 'acme_currency', 'EUR' );

		$id = ( new Widget() )->add_item( [] );

		$this->assertSame( '', self::raw( (int) $id )['currency'] ?? null );
		$this->assertFalse( acme_add_widget( [] ), 'The generated add function refuses it outright.' );
	}

	// ------------------------------------------------------------------
	// A stable order.
	// ------------------------------------------------------------------

	/**
	 * Paging through rows that tie on the sort column reads each once.
	 *
	 * @return void
	 */
	public function test_paging_through_tied_rows_reads_each_once(): void {
		$made = [];

		for ( $i = 0; $i < 20; $i++ ) {
			$made[] = (int) acme_add_widget(
				[
					'name'         => "Tied {$i}",
					'date_created' => '2026-01-01 00:00:00',
				]
			);
		}

		$seen = [];

		for ( $page = 0; $page < 5; $page++ ) {
			foreach ( acme_get_widgets(
				[
					'id__in'  => $made,
					'orderby' => 'date_created',
					'order'   => 'DESC',
					'number'  => 4,
					'offset'  => $page * 4,
				]
			) as $widget ) {
				$seen[] = (int) $widget->id;
			}
		}

		rsort( $made );

		$this->assertSame( $made, $seen );

		$first = acme_get_widgets(
			[
				'id__in'  => $made,
				'orderby' => 'date_created',
				'order'   => 'ASC',
				'number'  => 1,
			]
		);

		$this->assertSame( min( $made ), (int) $first[0]->id, 'Ascending breaks the tie the other way.' );
	}

	/**
	 * The SQL names the id after the column, in the same direction.
	 *
	 * @return void
	 */
	public function test_the_order_names_the_id_after_the_column(): void {
		$sql = self::queries(
			static function (): void {
				acme_get_widgets( [ 'orderby' => 'date_created', 'order' => 'ASC', 'number' => 3 ] );
				acme_get_widgets( [ 'orderby' => 'name', 'order' => 'desc', 'number' => 3 ] );
				acme_get_widgets( [ 'orderby' => 'id', 'order' => 'DESC', 'number' => 3 ] );
				acme_get_widgets( [ 'orderby' => 'name', 'order' => 'sideways', 'number' => 4 ] );
			}
		);

		$orders = array_values( array_filter( $sql, static fn( string $q ): bool => str_contains( $q, 'ORDER BY' ) ) );

		$this->assertMatchesRegularExpression( '/ORDER BY `[a-z_]+`\.`date_created` ASC, `[a-z_]+`\.`id` ASC/', $orders[0] );
		$this->assertMatchesRegularExpression( '/ORDER BY `[a-z_]+`\.`name` DESC, `[a-z_]+`\.`id` DESC/', $orders[1] );
		$this->assertDoesNotMatchRegularExpression( '/`id` DESC, `[a-z_]+`\.`id`/', $orders[2] );
		$this->assertMatchesRegularExpression( '/`name` DESC, `[a-z_]+`\.`id` DESC/', $orders[3], 'Anything but ASC is DESC.' );
	}

	/**
	 * Several columns, or none, are left as they came.
	 *
	 * @return void
	 */
	public function test_other_orders_are_left_alone(): void {
		$sql = self::queries(
			static function (): void {
				acme_get_widgets(
					[
						'orderby' => [
							'price' => 'ASC',
							'name'  => 'DESC',
						],
						'number'  => 3,
					]
				);
			}
		);

		$orders = array_values( array_filter( $sql, static fn( string $q ): bool => str_contains( $q, 'ORDER BY' ) ) );

		$this->assertMatchesRegularExpression( '/ORDER BY `[a-z_]+`\.`price` ASC, `[a-z_]+`\.`name` DESC/', $orders[0] );
		$this->assertStringNotContainsString( '`id`', substr( $orders[0], (int) strpos( $orders[0], 'ORDER BY' ) ) );
	}

	// ------------------------------------------------------------------
	// adjust().
	// ------------------------------------------------------------------

	/**
	 * A count goes up and down, and never below nought.
	 *
	 * @return void
	 */
	public function test_adjust_moves_a_count(): void {
		$id    = (int) acme_add_widget( [ 'name' => 'Counted' ] );
		$query = new Widget();

		$this->assertTrue( $query->adjust( $id, 'use_count', 3 ) );
		$this->assertSame( '3', self::raw( $id )['use_count'] );

		$this->assertTrue( $query->adjust( $id, 'use_count', -1 ) );
		$this->assertSame( '2', self::raw( $id )['use_count'] );

		$this->assertTrue( $query->adjust( $id, 'use_count', -5 ), 'An unsigned nought less five is not an error.' );
		$this->assertSame( '0', self::raw( $id )['use_count'] );

		acme_update_widget( $id, [ 'price' => 2 ] );

		$this->assertTrue( $query->adjust( $id, 'price', -5 ) );
		$this->assertSame( '0', self::raw( $id )['price'], 'A signed column stops at nought too.' );
	}

	/**
	 * Every step lands, and the cached row sees it.
	 *
	 * @return void
	 */
	public function test_adjust_clears_the_cache(): void {
		$id = (int) acme_add_widget( [ 'name' => 'Cached' ] );

		$this->assertSame( 0, (int) acme_get_widget( $id )->use_count );
		$this->assertSame( 0, acme_count_widgets( [ 'use_count' => 2, 'id' => $id ] ), 'A cached list, before.' );

		( new Widget() )->adjust( $id, 'use_count', 1 );
		( new Widget() )->adjust( $id, 'use_count', 1 );

		$this->assertSame( 2, (int) acme_get_widget( $id )->use_count );
		$this->assertSame( 1, acme_count_widgets( [ 'use_count' => 2, 'id' => $id ] ), 'Cached lists see it too.' );
	}

	// ------------------------------------------------------------------
	// forget_items().
	// ------------------------------------------------------------------

	/**
	 * Rows changed by SQL of the caller's own read as they now are, singly
	 * and in lists, once forgotten -- and as they were until then.
	 *
	 * @return void
	 */
	public function test_forgotten_rows_read_as_they_now_are(): void {
		global $wpdb;

		$ids   = [ (int) acme_add_widget( [ 'name' => 'Set A' ] ), (int) acme_add_widget( [ 'name' => 'Set B' ] ) ];
		$other = (int) acme_add_widget( [ 'name' => 'Untouched' ] );

		foreach ( [ ...$ids, $other ] as $id ) {
			$this->assertSame( 0, (int) acme_get_widget( $id )->use_count );
		}

		$this->assertSame( 0, acme_count_widgets( [ 'use_count' => 7, 'id__in' => $ids ] ) );

		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET use_count = 7 WHERE id IN ( %d, %d )', $wpdb->prefix . 'acme_widgets', ...$ids ) );

		$this->assertSame( 0, (int) acme_get_widget( $ids[0] )->use_count, 'Still the cached copy.' );

		( new Widget() )->forget_items( $ids );

		foreach ( $ids as $id ) {
			$this->assertSame( 7, (int) acme_get_widget( $id )->use_count );
		}

		$this->assertSame( 2, acme_count_widgets( [ 'use_count' => 7, 'id__in' => $ids ] ), 'Cached lists see it too.' );
		$this->assertNotFalse( wp_cache_get( $other, 'acme-acme_widgets' ), 'Another row stays cached.' );
	}

	/**
	 * A row found by another cached column is looked up afresh once
	 * forgotten, since the SQL may have changed that column.
	 *
	 * @return void
	 */
	public function test_forgetting_starts_lookups_by_other_columns_afresh(): void {
		global $wpdb;

		$id = (int) ( new Event() )->add_item( [ 'event_ref' => 'forget-old' ] );

		$this->assertSame( $id, ( new GuardedEvent() )->get_item_by( 'event_ref', 'forget-old' )->id );

		$wpdb->update( $wpdb->prefix . 'acme_events', [ 'event_ref' => 'forget-new' ], [ 'id' => $id ] );

		( new GuardedEvent() )->forget_items( [ $id ] );

		$this->assertFalse( ( new GuardedEvent() )->get_item_by( 'event_ref', 'forget-old' ) );
		$this->assertSame( $id, ( new GuardedEvent() )->get_item_by( 'event_ref', 'forget-new' )->id );
	}

	/**
	 * Nothing to forget, or invalidation suspended, drops nothing.
	 *
	 * @return void
	 */
	public function test_forget_items_refuses(): void {
		$id = (int) acme_add_widget( [ 'name' => 'Kept' ] );

		acme_get_widget( $id );

		$last = wp_cache_get( 'last_changed', 'acme-acme_widgets' );

		( new Widget() )->forget_items( [] );
		( new Widget() )->forget_items( [ '', null, 1.5 ] );

		$this->assertSame( $last, wp_cache_get( 'last_changed', 'acme-acme_widgets' ) );

		wp_suspend_cache_invalidation( true );

		try {
			( new Widget() )->forget_items( [ $id ] );
		} finally {
			wp_suspend_cache_invalidation( false );
		}

		$this->assertNotFalse( wp_cache_get( $id, 'acme-acme_widgets' ) );
		$this->assertSame( $last, wp_cache_get( 'last_changed', 'acme-acme_widgets' ) );
	}

	/**
	 * adjust() stamps date_modified.
	 *
	 * @return void
	 */
	public function test_adjust_stamps_date_modified(): void {
		global $wpdb;

		$id = (int) acme_add_widget( [ 'name' => 'Stamped' ] );

		$wpdb->update( $wpdb->prefix . 'acme_widgets', [ 'date_modified' => '2020-01-01 00:00:00' ], [ 'id' => $id ] );

		( new Widget() )->adjust( $id, 'use_count', 1 );

		$this->assertNotSame( '2020-01-01 00:00:00', self::raw( $id )['date_modified'] );
	}

	/**
	 * What adjust() refuses.
	 *
	 * @return void
	 */
	public function test_adjust_refuses(): void {
		global $wpdb;

		$id    = (int) acme_add_widget( [ 'name' => 'Refused' ] );
		$query = new Widget();

		// So that any statement at all would change the row.
		$wpdb->update( $wpdb->prefix . 'acme_widgets', [ 'date_modified' => '2020-01-01 00:00:00' ], [ 'id' => $id ] );

		$this->assertFalse( $query->adjust( 0, 'use_count', 1 ), 'No row.' );
		$this->assertFalse( $query->adjust( $id, 'use_count', 0 ), 'Nothing to move.' );
		$this->assertFalse( $query->adjust( $id, 'id', 1 ), 'Not the id.' );
		$this->assertFalse( $query->adjust( $id, 'nope', 1 ), 'Not a column.' );
		$this->assertFalse( $query->adjust( $id + 1000, 'use_count', 1 ), 'A row that is not there.' );
	}

	// ------------------------------------------------------------------
	// claim() and retake().
	// ------------------------------------------------------------------

	/**
	 * The first claim wins; a second is refused quietly.
	 *
	 * @return void
	 */
	public function test_only_the_first_claim_wins(): void {
		global $wpdb;

		$query = new Event();
		$first = $query->claim(
			[
				'event_ref' => 'evt_1',
				'status'    => 'pending',
			]
		);

		$this->assertIsInt( $first );

		$wpdb->last_error = '';

		$this->assertNull(
			$query->claim(
				[
					'event_ref' => 'evt_1',
					'status'    => 'pending',
				]
			)
		);
		$this->assertStringContainsString( 'Duplicate entry', $wpdb->last_error, 'Refused by the unique index.' );
		$this->assertFalse( $wpdb->suppress_errors, 'Error reporting is put back.' );
		$this->assertSame( 1, acme_count_events( [ 'event_ref' => 'evt_1' ] ) );
	}

	/**
	 * A claim can go in through the plugin's own add function.
	 *
	 * @return void
	 */
	public function test_a_claim_can_use_the_plugins_add_function(): void {
		$fired = 0;

		add_action(
			'acme_add_event',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		$id = ( new Event() )->claim( [ 'event_ref' => 'evt_hooked' ], 'acme_add_event' );

		$this->assertIsInt( $id );
		$this->assertSame( 1, $fired );
	}

	/**
	 * A failed row is taken at once; a processed one never.
	 *
	 * @return void
	 */
	public function test_a_failed_row_is_retaken_at_once(): void {
		$query = new Event();
		$id    = (int) $query->claim( [ 'event_ref' => 'evt_failed' ] );

		self::age_event( $id, 'failed', 0 );

		$this->assertSame( 'failed', acme_get_event( $id )->status, 'Cached as failed.' );
		$this->assertTrue( $query->retake( $id, [ 'failed' => 0, 'pending' => 300 ], [ 'status' => 'pending' ] ) );
		$this->assertSame( 'pending', self::raw_event( $id )['status'] );
		$this->assertSame( 'pending', acme_get_event( $id )->status, 'The cached row sees it.' );

		self::age_event( $id, 'processed', 3600 );

		$this->assertFalse( $query->retake( $id, [ 'failed' => 0, 'pending' => 300 ], [ 'status' => 'pending' ] ) );
		$this->assertSame( 'processed', self::raw_event( $id )['status'] );
	}

	/**
	 * A pending row is taken only once it has stalled.
	 *
	 * @return void
	 */
	public function test_a_pending_row_is_retaken_once_stale(): void {
		$query = new Event();
		$id    = (int) $query->claim( [ 'event_ref' => 'evt_pending' ] );

		self::age_event( $id, 'pending', 60 );

		$this->assertFalse( $query->retake( $id, [ 'failed' => 0, 'pending' => 300 ], [ 'status' => 'pending' ] ), 'Still running, as far as anyone knows.' );

		self::age_event( $id, 'pending', 301 );

		$this->assertTrue( $query->retake( $id, [ 'failed' => 0, 'pending' => 300 ], [ 'status' => 'pending' ] ), 'Pending to pending still counts as taken.' );
	}

	/**
	 * Two retries together: only one takes it.
	 *
	 * @return void
	 */
	public function test_only_one_retry_takes_a_row(): void {
		$id = (int) ( new Event() )->claim( [ 'event_ref' => 'evt_race' ] );

		self::age_event( $id, 'failed', 0 );

		$this->assertTrue( ( new Event() )->retake( $id, [ 'failed' => 0, 'pending' => 300 ], [ 'status' => 'pending' ] ) );
		$this->assertFalse( ( new Event() )->retake( $id, [ 'failed' => 0, 'pending' => 300 ], [ 'status' => 'pending' ] ) );
	}

	/**
	 * What retake() refuses.
	 *
	 * @return void
	 */
	public function test_retake_refuses(): void {
		global $wpdb;

		$query = new Event();
		$id    = (int) $query->claim( [ 'event_ref' => 'evt_refuse' ] );

		self::age_event( $id, 'failed', 0 );

		$this->assertFalse( $query->retake( 0, [ 'failed' => 0 ] ), 'No row.' );
		$this->assertFalse( $query->retake( $id, [] ), 'No state to take it from.' );
		$this->assertFalse( $query->retake( $id, [ 'failed' => 0 ], [ 'nope' => 1 ] ), 'Not a column.' );
		$this->assertSame( '', $wpdb->last_error, 'Refused before any SQL was sent.' );
		$this->assertFalse( $query->retake( $id, [ 'failed' => 0 ], [ 'id' => 9 ] ), 'Not the id.' );
		$this->assertFalse( $query->retake( $id, [ 'failed' => 0 ], [], 'nope' ), 'No such state column.' );
		$this->assertFalse( $query->retake( $id, [ 'failed' => 0 ], [ 'date_modified' => '2020-01-01' ] ), 'date_modified is the library\'s own.' );
		$this->assertSame( 'failed', self::raw_event( $id )['status'], 'Nothing was changed.' );
	}

	// ------------------------------------------------------------------
	// tally().
	// ------------------------------------------------------------------

	/**
	 * Counting into a named row: made the first time, then counted.
	 *
	 * @return void
	 */
	public function test_tally_counts(): void {
		$query = new Counter();
		$key   = [ 'bucket' => str_repeat( 'a', 64 ) ];

		$this->assertSame( 1, $query->tally( $key, 'hits', [ 'date_expires' => '2030-01-01 00:00:00' ] ) );
		// Read by id from here on: a read by any other column goes to the
		// database and refreshes the cached copy on its way back.
		$id = (int) acme_get_counter_by( 'bucket', $key['bucket'] )->id;

		$this->assertSame( 1, (int) acme_get_counter( $id )->hits, 'Cached at one.' );
		$this->assertSame( 2, $query->tally( $key, 'hits', [ 'date_expires' => '2031-01-01 00:00:00' ] ) );
		$this->assertSame( 5, $query->tally( $key, 'hits', [], 3 ) );

		$row = acme_get_counter( $id );

		$this->assertSame( 5, (int) $row->hits, 'The cached row sees it.' );
		$this->assertSame( '2030-01-01 00:00:00', $row->date_expires, 'What is written on making the row is written once.' );
		$this->assertNotSame( '0000-00-00 00:00:00', $row->date_created );

		$this->assertSame( 1, $query->tally( [ 'bucket' => str_repeat( 'b', 64 ) ], 'hits' ), 'Another name, another count.' );
	}

	/**
	 * What tally() refuses.
	 *
	 * @return void
	 */
	public function test_tally_refuses(): void {
		global $wpdb;

		$query = new Counter();
		$key   = [ 'bucket' => str_repeat( 'c', 64 ) ];

		$this->assertSame( 0, $query->tally( [], 'hits' ), 'No key.' );
		$this->assertSame( 0, $query->tally( $key, 'hits', [], 0 ), 'Nothing to add.' );
		$this->assertSame( 0, $query->tally( $key, 'nope' ), 'Not a column.' );
		$this->assertSame( 0, $query->tally( $key, 'id' ), 'Not the id.' );
		$sent = self::queries(
			function () use ( $query, $key ): void {
				$this->assertSame( 0, $query->tally( $key, 'hits', [ 'nope' => 1 ] ), 'Not a column to write.' );
				$this->assertSame( 0, $query->tally( $key, 'hits', [ 'hits' => 9 ] ), 'The count is not also a column to write.' );
			}
		);

		$this->assertSame( [], $sent, 'Refused before any SQL was sent.' );
		$this->assertFalse( acme_get_counter_by( 'bucket', $key['bucket'] ), 'Nothing was made.' );
	}

	// ------------------------------------------------------------------
	// sweep().
	// ------------------------------------------------------------------

	/**
	 * Events made a given number of days ago, past the query layer, and no
	 * others: what earlier tests left is cleared first (inside this test's
	 * transaction, so it comes back afterwards).
	 *
	 * @param array<int, array{0: string, 1: int}> $events Status, days old.
	 *
	 * @return array<int, int> Their ids, in the same order.
	 */
	private static function old_events( array $events ): array {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'acme_events' ) );

		$ids = [];

		foreach ( $events as $i => [ $status, $days ] ) {
			$id = (int) acme_add_event( [ 'event_ref' => 'sweep_' . $i . '_' . wp_generate_password( 8, false ), 'status' => $status ] );

			$wpdb->update( $wpdb->prefix . 'acme_events', [ 'date_created' => gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ], [ 'id' => $id ] );

			$ids[] = $id;
		}

		wp_cache_flush();

		return $ids;
	}

	/**
	 * A cut-off a number of days ago.
	 *
	 * @param int $days Days.
	 *
	 * @return string
	 */
	private static function days_ago( int $days ): string {
		return gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
	}

	/**
	 * Old rows go, newer ones stay, and the count says how many went.
	 *
	 * @return void
	 */
	public function test_sweep_removes_what_is_older(): void {
		[ $old, $older, $new ] = self::old_events( [ [ 'done', 40 ], [ 'done', 90 ], [ 'done', 5 ] ] );

		$this->assertSame( 2, ( new Event() )->sweep( 'date_created', self::days_ago( 30 ) ) );

		$this->assertSame( [], self::raw_event( $old ) );
		$this->assertSame( [], self::raw_event( $older ) );
		$this->assertNotSame( [], self::raw_event( $new ) );
	}

	/**
	 * A swept row's cached copy and every cached list go, through core's
	 * wp_cache_delete_multiple() and wp_cache_set_last_changed().
	 *
	 * @return void
	 */
	public function test_a_sweep_forgets_the_cached_rows_and_lists(): void {
		[ $old ] = self::old_events( [ [ 'done', 40 ] ] );

		$this->assertNotFalse( acme_get_event( $old ), 'Read once, so it is cached.' );
		$listed  = acme_count_events( [ 'status' => 'done' ] );
		$changed = did_action( 'wp_cache_set_last_changed' );

		$this->assertSame( 1, ( new Event() )->sweep( 'date_created', self::days_ago( 30 ) ) );

		$this->assertSame( $changed + 1, did_action( 'wp_cache_set_last_changed' ) );
		$this->assertEmpty( acme_get_event( $old ), 'The cached row is gone, not served stale.' );
		$this->assertSame( $listed - 1, acme_count_events( [ 'status' => 'done' ] ), 'Cached lists see it.' );
	}

	/**
	 * A dry run counts exactly what a real run then removes, and removes nothing.
	 *
	 * @return void
	 */
	public function test_a_dry_sweep_only_counts(): void {
		$ids = self::old_events( [ [ 'done', 40 ], [ 'done', 50 ], [ 'done', 1 ] ] );

		$sent = self::queries(
			function (): void {
				$this->assertSame( 2, ( new Event() )->sweep( 'date_created', self::days_ago( 30 ), dry: true ) );
			}
		);

		$this->assertSame( [], preg_grep( '/^\s*DELETE/i', $sent ), 'Nothing deleted.' );

		foreach ( $ids as $id ) {
			$this->assertNotSame( [], self::raw_event( $id ) );
		}

		$this->assertSame( 2, ( new Event() )->sweep( 'date_created', self::days_ago( 30 ) ) );
	}

	/**
	 * At most $limit rows a run, the oldest ids first.
	 *
	 * @return void
	 */
	public function test_a_sweep_is_bounded(): void {
		[ $a, $b, $c ] = self::old_events( [ [ 'done', 40 ], [ 'done', 40 ], [ 'done', 40 ] ] );

		$this->assertSame( 2, ( new Event() )->sweep( 'date_created', self::days_ago( 30 ), limit: 2 ) );
		$this->assertSame( [], self::raw_event( $a ) );
		$this->assertSame( [], self::raw_event( $b ) );
		$this->assertNotSame( [], self::raw_event( $c ) );

		$this->assertSame( 1, ( new Event() )->sweep( 'date_created', self::days_ago( 30 ), limit: 2, dry: true ), 'A dry run is bounded too.' );
	}

	/**
	 * Only rows with a value, or without one.
	 *
	 * @return void
	 */
	public function test_a_sweep_keeps_to_its_conditions(): void {
		[ $done, $failed, $pending ] = self::old_events( [ [ 'done', 40 ], [ 'failed', 40 ], [ 'pending', 40 ] ] );
		$query                       = new Event();
		$cut                         = self::days_ago( 30 );

		$this->assertSame( 0, $query->sweep( 'date_created', $cut, [ 'status' => [] ] ), 'In nothing is nothing.' );
		$this->assertSame( 1, $query->sweep( 'date_created', $cut, [ 'status' => 'done' ] ) );
		$this->assertSame( [], self::raw_event( $done ) );
		$this->assertSame( 1, $query->sweep( 'date_created', $cut, except: [ 'status' => [ 'pending', 'processing' ] ] ) );
		$this->assertSame( [], self::raw_event( $failed ) );
		$this->assertNotSame( [], self::raw_event( $pending ) );
		$this->assertSame( 1, $query->sweep( 'date_created', $cut, except: [ 'status' => [] ] ), 'Except nothing rules nothing out.' );
		$this->assertSame( [], self::raw_event( $pending ) );
	}

	/**
	 * A swept row's meta goes with it; another row's stays.
	 *
	 * @return void
	 */
	public function test_a_sweep_takes_the_rows_meta(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'acme_widgets' ) );

		$old = (int) acme_add_widget( [ 'name' => 'Old' ] );
		$new = (int) acme_add_widget( [ 'name' => 'New' ] );

		acme_add_widget_meta( $old, 'colour', 'red' );
		acme_add_widget_meta( $new, 'colour', 'blue' );

		$this->assertSame( 'red', acme_get_widget_meta( $old, 'colour', true ), 'Cached.' );

		$wpdb->update( $wpdb->prefix . 'acme_widgets', [ 'date_created' => self::days_ago( 40 ) ], [ 'id' => $old ] );

		$this->assertSame( 1, ( new Widget() )->sweep( 'date_created', self::days_ago( 30 ) ) );

		$meta = $wpdb->prefix . 'acme_widgetmeta';

		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE acme_widget_id = %d', $meta, $old ) ) );
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE acme_widget_id = %d', $meta, $new ) ) );
		$this->assertSame( '', acme_get_widget_meta( $old, 'colour', true ), 'Its cached meta went too.' );
		$this->assertSame( 'blue', acme_get_widget_meta( $new, 'colour', true ) );
	}

	/**
	 * A swept row leaves the cached row and the cached lists.
	 *
	 * @return void
	 */
	public function test_a_sweep_clears_the_cache(): void {
		[ $id ] = self::old_events( [ [ 'done', 40 ] ] );

		$this->assertNotFalse( acme_get_event( $id ), 'Cached.' );
		$this->assertContains( $id, array_map( static fn( $e ): int => (int) $e->id, acme_get_events( [ 'number' => 100 ] ) ), 'Listed and cached.' );
		$this->assertSame( 1, acme_count_events(), 'Counted and cached.' );

		( new Event() )->sweep( 'date_created', self::days_ago( 30 ) );

		$this->assertFalse( acme_get_event( $id ) );
		$this->assertNotContains( $id, array_map( static fn( $e ): int => (int) $e->id, acme_get_events( [ 'number' => 100 ] ) ) );
		$this->assertSame( 0, acme_count_events(), 'The cached count is not reused.' );
	}

	/**
	 * What sweep() refuses, before any SQL is sent.
	 *
	 * @return void
	 */
	public function test_sweep_refuses(): void {
		[ $id ] = self::old_events( [ [ 'done', 40 ] ] );
		$query  = new Event();
		$cut    = self::days_ago( 30 );

		$sent = self::queries(
			function () use ( $query, $cut ): void {
				$this->assertSame( 0, $query->sweep( 'nope', $cut ), 'Not a column.' );
				$this->assertSame( 0, $query->sweep( 'id', $cut ), 'Not the id.' );
				$this->assertSame( 0, $query->sweep( 'date_created', '' ), 'No date.' );
				$this->assertSame( 0, $query->sweep( 'date_created', $cut, limit: 0 ), 'No room.' );
				$this->assertSame( 0, $query->sweep( 'date_created', $cut, [ 'nope' => 'x' ] ), 'Not a column to match.' );
				$this->assertSame( 0, $query->sweep( 'date_created', $cut, except: [ 'nope' => 'x' ] ), 'Not a column to rule out.' );
			}
		);

		$this->assertSame( [], $sent );
		$this->assertNotSame( [], self::raw_event( $id ) );
	}
}
