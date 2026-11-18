<?php
/**
 * What every query class has in common.
 *
 * @package     SugarCommerce\Data
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Data;

use BerlinDB\Database\Kern\Query as Base;

defined( 'ABSPATH' ) || exit;

/**
 * BerlinDB's Query, with what it is missing.
 *
 * **Defaults that are functions.** A column's `default` in BerlinDB is a
 * literal twice over. It is written into CREATE TABLE, where MySQL wants a
 * constant; and it is passed through the column's own `validate` callback
 * once, while the Schema is being built, long before any row is. So "the
 * store's currency" cannot be a default: it would be read once, at whatever
 * moment the Schema class was first made, and frozen into the table. A
 * query class lists those columns in $insert_defaults instead, and
 * add_item() fills them in -- only on insert, and only when the caller left
 * the column out or empty. An update that does not mention a column must
 * not move it, and one that empties it on purpose is taken at its word.
 *
 * **A stable order.** Sorted by a column many rows share, the database may
 * return tied rows in any order, and a different one for the next page.
 * query() adds the id after the column, so paging never shows a row twice
 * or skips one.
 *
 * **Writes that cannot race.** adjust() moves a count in one statement;
 * claim() lets exactly one of several simultaneous requests have a row;
 * retake() lets exactly one of them take over a row that failed or stalled;
 * tally() counts into a row named by a unique key, creating it the first
 * time. Each is one statement the database settles, where a read followed
 * by a write would let two requests both see "nothing yet".
 *
 * **Bounded clean-ups.** sweep() removes rows older than a date, a batch
 * at a time and their meta with them, or with `$dry` only counts them.
 *
 * Generated query classes extend this. A plugin whose add-ons register their
 * own tables should extend it once in its own namespace and point the
 * generator at that class, so every add-on builds on the plugin's copy.
 *
 * @since 1.0.0
 */
class Query extends Base {

	/**
	 * Columns a new row is given a value for when the caller names none.
	 *
	 * Column => the function that says what.
	 *
	 * @var array<string, callable-string>
	 * @since 1.0.0
	 */
	protected $insert_defaults = [];

	/**
	 * Add a row, filling in what a function has to say.
	 *
	 * @param array<string, mixed> $data Column values.
	 *
	 * @return int|string|false The new id, or false when nothing was written.
	 * @since 1.0.0
	 */
	public function add_item( $data = [] ) {
		$data = (array) $data;

		// An empty write is the caller's own: BerlinDB writes it as a row of
		// column defaults. Filling function defaults into it would make
		// "the caller passed nothing" into a row with something in it.
		if ( [] !== $data ) {
			foreach ( $this->insert_defaults as $column => $default ) {
				$given = $data[ $column ] ?? '';

				if ( ( '' === $given || null === $given ) && is_callable( $default ) ) {
					$data[ $column ] = $default();
				}
			}
		}

		return parent::add_item( $data );
	}

	/**
	 * Run a query, with the row's id to settle ties in the order.
	 *
	 * Sorted by a column many rows can share -- a date to the second, a
	 * status, a country -- the database may return the tied rows in any
	 * order it likes, and in a different one for the next page. A list
	 * paged that way can show a row on two pages and another on none. The
	 * id breaks every tie the same way each time, in the direction asked
	 * for, so page two always starts where page one stopped.
	 *
	 * @param array<string, mixed>|string $query Query arguments.
	 *
	 * @return array<int, object>|int
	 * @since 1.0.0
	 */
	public function query( $query = [] ) {
		$query = wp_parse_args( $query );

		$query['orderby'] = $this->with_tie_break( $query['orderby'] ?? '', (string) ( $query['order'] ?? 'DESC' ) );

		return parent::query( $query );
	}

	/**
	 * Move a stored count up or down in one statement.
	 *
	 * A count read, added to and written back loses a step whenever two
	 * requests do it at once -- two orders using one discount in the same
	 * second counted as one. The database does the sum itself here, so
	 * every step lands. Never below nought: a count that would go under
	 * stops at it.
	 *
	 * `date_modified` is stamped as update_item() would, so whatever reads
	 * changes by that date still sees the row change. The row's cached copy
	 * and the cached lists are dropped afterwards, which update_item() does
	 * for itself and a statement of our own has to do by hand.
	 *
	 * @param int    $id     The row.
	 * @param string $column The count: one of the table's own columns.
	 * @param int    $by     How far, up when positive and down when negative.
	 *
	 * @return bool Whether a row was changed.
	 * @since 1.0.0
	 */
	public function adjust( int $id, string $column, int $by ): bool {
		global $wpdb;

		$primary = $this->get_primary_column_name();

		if ( $id < 1 || 0 === $by || $primary === $column || ! $this->has_column( $column ) ) {
			return false;
		}

		// CAST before the sum, not after: on an unsigned column MySQL works
		// `count - 1` out as unsigned, and nought less one is an "out of
		// range" error rather than a number GREATEST could catch.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One statement is the point, and the caches are cleared just below.
		$changed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i
				SET %i = GREATEST( CAST( %i AS SIGNED ) + %d, 0 ),
					date_modified = %s
				WHERE %i = %d',
				$this->get_table_name(),
				$column,
				$column,
				$by,
				current_time( 'mysql', true ),
				$primary,
				$id
			)
		);

		$this->forget( $id );

		return (bool) $changed;
	}

	/**
	 * Add a row that only one request may add: the first.
	 *
	 * The row has to carry a value for a unique index -- an event's id, a
	 * request's key. Two requests arriving together both try to insert;
	 * the database lets one through and refuses the other, which is a
	 * guarantee a read-then-write check cannot make ("is it there? no --
	 * both of them"). The refusal is the answer, not a problem, so it is
	 * kept out of the error log.
	 *
	 * Pass the plugin's own add function as $insert when its hooks should
	 * fire for the new row; by default the row goes in through add_item().
	 *
	 * @param array<string, mixed>                       $data   The row.
	 * @param callable(array<string, mixed>): mixed|null $insert What inserts it.
	 *
	 * @return int|null The new row's id, or null when it was already there
	 *                  (or could not be written for another reason).
	 * @since 1.0.0
	 */
	public function claim( array $data, ?callable $insert = null ): ?int {
		global $wpdb;

		$suppressed = $wpdb->suppress_errors( true );

		try {
			$id = null === $insert ? $this->add_item( $data ) : $insert( $data );
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}

		return empty( $id ) ? null : (int) $id;
	}

	/**
	 * Take over a row that failed or stalled, if nobody else has.
	 *
	 * What a claimed row is for can fail -- the handler threw -- or stall,
	 * when the request died before it could say either way. A retry of the
	 * same thing should then be allowed to try again, but only one retry:
	 * two arriving together must not both run.
	 *
	 * $from names the states a row may be taken from, and how long it must
	 * have sat unchanged in each first: `[ 'failed' => 0, 'pending' => 300 ]`
	 * takes a failed row at once and a pending one after five minutes. One
	 * UPDATE does the check and the change, so the database lets exactly one
	 * request have it and tells the others nothing matched.
	 *
	 * `date_modified` moves in the same statement, because MySQL reports
	 * rows changed rather than rows matched: pending to pending alone would
	 * change nothing and read as "not taken".
	 *
	 * @param int                  $id     The row.
	 * @param array<string, int>   $from   State => seconds unchanged before it may be taken.
	 * @param array<string, mixed> $set    What to write when it is taken, such as the state.
	 * @param string               $column The column holding the state.
	 *
	 * @return bool Whether this request took it.
	 * @since 1.0.0
	 */
	public function retake( int $id, array $from, array $set = [], string $column = 'status' ): bool {
		global $wpdb;

		$primary = $this->get_primary_column_name();

		if ( $id < 1 || [] === $from || ! $this->has_column( $column ) || ! $this->has_column( 'date_modified' ) ) {
			return false;
		}

		$now   = time();
		$parts = [];
		$args  = [ $this->get_table_name() ];

		foreach ( $set as $name => $value ) {
			if ( $primary === $name || 'date_modified' === $name || ! $this->has_column( (string) $name ) ) {
				return false;
			}

			$parts[] = '%i = %s';
			array_push( $args, (string) $name, (string) $value );
		}

		$parts[] = 'date_modified = %s';
		$args[]  = gmdate( 'Y-m-d H:i:s', $now );

		$where = [];
		$when  = [];

		foreach ( $from as $state => $seconds ) {
			if ( (int) $seconds > 0 ) {
				$where[] = '( %i = %s AND date_modified < %s )';
				array_push( $when, $column, (string) $state, gmdate( 'Y-m-d H:i:s', $now - (int) $seconds ) );
			} else {
				$where[] = '%i = %s';
				array_push( $when, $column, (string) $state );
			}
		}

		array_push( $args, $primary, $id, ...$when );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One statement on purpose, its answer must not be a cached one, and the SQL is built only from placeholders.
		$taken = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET ' . implode( ', ', $parts ) . ' WHERE %i = %d AND ( ' . implode( ' OR ', $where ) . ' )',
				...$args
			)
		);
		// phpcs:enable

		$this->forget( $id );

		return (bool) $taken;
	}

	/**
	 * Count one more of something, and say how many that makes.
	 *
	 * The row is named by a unique key and made the first time; after that
	 * its count goes up. `INSERT … ON DUPLICATE KEY UPDATE` is one
	 * statement and MySQL takes the row's lock for it, so twenty requests
	 * together are counted one to twenty -- where a transient, read, added
	 * to and written back, would count them as one.
	 *
	 * The count is read after the write, so what comes back is at least
	 * this request's own. Another arriving in between makes it one higher,
	 * which errs towards refusing and never towards letting two through on
	 * one allowance.
	 *
	 * @param array<string, scalar> $key    The unique column(s) and their values.
	 * @param string                $column The count.
	 * @param array<string, scalar> $insert Other columns, written only when the row is made.
	 * @param int                   $by     How many to add.
	 *
	 * @return int The count, this one included. 0 when nothing could be counted.
	 * @since 1.0.0
	 */
	public function tally( array $key, string $column, array $insert = [], int $by = 1 ): int {
		global $wpdb;

		$primary = $this->get_primary_column_name();

		if ( [] === $key || $by < 1 || $primary === $column || ! $this->has_column( $column ) ) {
			return 0;
		}

		foreach ( array_keys( $key + $insert ) as $name ) {
			if ( $primary === $name || $column === $name || ! $this->has_column( (string) $name ) ) {
				return 0;
			}
		}

		$now     = current_time( 'mysql', true );
		$columns = array_merge( array_keys( $key ), [ $column ], array_keys( $insert ) );
		$values  = array_merge( array_values( $key ), [ $by ], array_values( $insert ) );
		$holders = array_merge( array_fill( 0, count( $key ), '%s' ), [ '%d' ], array_fill( 0, count( $insert ), '%s' ) );

		foreach ( [ 'date_created', 'date_modified' ] as $stamp ) {
			if ( $this->has_column( $stamp ) && ! in_array( $stamp, $columns, true ) ) {
				$columns[] = $stamp;
				$values[]  = $now;
				$holders[] = '%s';
			}
		}

		$update = '%i = %i + %d' . ( $this->has_column( 'date_modified' ) ? ', date_modified = VALUES( date_modified )' : '' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The one statement that makes the count atomic, which BerlinDB does not offer, read back uncached. The SQL is built only from placeholders.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i ( ' . implode( ', ', array_fill( 0, count( $columns ), '%i' ) ) . ' )
				VALUES ( ' . implode( ', ', $holders ) . ' )
				ON DUPLICATE KEY UPDATE ' . $update,
				$this->get_table_name(),
				...$columns,
				...$values,
				...[ $column, $column, $by ]
			)
		);

		$where = implode( ' AND ', array_fill( 0, count( $key ), '%i = %s' ) );
		$pairs = [];

		foreach ( $key as $name => $value ) {
			array_push( $pairs, $name, (string) $value );
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT %i AS id, %i AS tally FROM %i WHERE {$where}",
				$primary,
				$column,
				$this->get_table_name(),
				...$pairs
			)
		);
		// phpcs:enable

		if ( ! is_object( $row ) ) {
			return 0;
		}

		$this->forget( (int) $row->id );

		return (int) $row->tally;
	}

	/**
	 * Remove rows older than a date, a bounded batch at a time -- or only
	 * count them.
	 *
	 * Housekeeping: expired rate-limit buckets, refusals past their days,
	 * activity past its retention. Each wants the same few lines -- one
	 * condition on a date, perhaps a type in or out, a limit so a first run
	 * on a big table cannot hold the lock for a minute -- and each copy
	 * written by hand forgets something different: the limit, the meta rows
	 * left behind, the cached lists that still show the rows.
	 *
	 * `$dry` counts what a run would remove, from the same condition. Written
	 * as two branches at every call site, the dry run is the branch nobody
	 * tests, and one day a "dry" run deletes for real; here both share one
	 * WHERE.
	 *
	 * The ids are read first and the rows deleted by id, so a meta table
	 * loses exactly the rows' own meta. No delete hooks fire: this is a
	 * table being tidied, not somebody deleting something. Where a row's
	 * going matters to anybody, use delete_item().
	 *
	 * @param string                           $column The date: one of the table's own columns.
	 * @param string                           $before Rows dated before this (`Y-m-d H:i:s`, UTC) go.
	 * @param array<string, scalar|array<int, scalar>> $where  Column => value, or => a list of values, that a row must have.
	 * @param array<string, scalar|array<int, scalar>> $except Column => value, or => a list of values, that a row must not have.
	 * @param int                              $limit  At most this many in one run.
	 * @param bool                             $dry    Only count them.
	 *
	 * @return int How many were removed, or with $dry would be.
	 * @since 1.0.0
	 */
	public function sweep( string $column, string $before, array $where = [], array $except = [], int $limit = 1000, bool $dry = false ): int {
		global $wpdb;

		$primary = $this->get_primary_column_name();

		if ( '' === $before || $limit < 1 || $primary === $column || ! $this->has_column( $column ) ) {
			return 0;
		}

		$clauses = [ '%i < %s' ];
		$args    = [ $column, $before ];

		foreach ( [
			'IN'     => $where,
			'NOT IN' => $except,
		] as $operator => $pairs ) {
			foreach ( $pairs as $name => $value ) {
				if ( ! $this->has_column( (string) $name ) ) {
					return 0;
				}

				$values = array_values( (array) $value );

				// Nothing a row must be in matches nothing; nothing it must
				// not be in rules nothing out.
				if ( [] === $values ) {
					if ( 'IN' === $operator ) {
						return 0;
					}

					continue;
				}

				$clauses[] = '%i ' . $operator . ' ( ' . implode( ', ', array_fill( 0, count( $values ), '%s' ) ) . ' )';

				array_push( $args, (string) $name, ...array_map( 'strval', $values ) );
			}
		}

		$table = $this->get_table_name();
		$sql   = implode( ' AND ', $clauses );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Housekeeping BerlinDB does not offer, read past the cache it then clears. The SQL is built only from placeholders.
		$ids = array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT %i FROM %i WHERE {$sql} ORDER BY %i ASC LIMIT %d",
					$primary,
					$table,
					...[ ...$args, $primary, $limit ]
				)
			)
		);

		if ( $dry || [] === $ids ) {
			return count( $ids );
		}

		$in      = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$removed = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE %i IN ( {$in} )", $table, $primary, ...$ids ) );

		$type = $this->get_meta_type();
		$meta = (string) ( $wpdb->{$type . 'meta'} ?? '' );

		if ( '' !== $meta ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE %i IN ( {$in} )", $meta, $type . '_id', ...$ids ) );
		}
		// phpcs:enable

		wp_cache_delete_multiple( $ids, $this->cache_group );

		if ( '' !== $meta ) {
			wp_cache_delete_multiple( $ids, $type . '_meta' );
		}

		wp_cache_set_last_changed( $this->cache_group );

		return $removed;
	}

	/**
	 * Whether the table has a column.
	 *
	 * @param string $column The column.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	protected function has_column( string $column ): bool {
		return in_array( $column, $this->get_column_names(), true );
	}

	/**
	 * Drop a row's cached copy and every cached list.
	 *
	 * What update_item() does for itself, and a statement written here has
	 * to do by hand: BerlinDB's own cache cleaners are private.
	 *
	 * @param int $id The row.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	protected function forget( int $id ): void {
		wp_cache_delete( $id, $this->cache_group );
		wp_cache_set_last_changed( $this->cache_group );
	}

	/**
	 * An order by one column, with the id after it.
	 *
	 * Anything other than a single column name is left as it came: no
	 * order at all, an order already given as several columns, or the id
	 * itself.
	 *
	 * @param mixed  $orderby What to sort by.
	 * @param string $order   `ASC` or `DESC`.
	 *
	 * @return mixed
	 * @since 1.0.0
	 */
	private function with_tie_break( mixed $orderby, string $order ): mixed {
		$primary = $this->get_primary_column_name();

		if ( ! is_string( $orderby ) || '' === $orderby || 'none' === $orderby || $primary === $orderby ) {
			return '' === $orderby ? '' : $orderby;
		}

		if ( 1 !== preg_match( '/^[a-z0-9_]+$/i', $orderby ) ) {
			return $orderby;
		}

		$order = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';

		return [
			$orderby => $order,
			$primary => $order,
		];
	}
}
