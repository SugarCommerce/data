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
use SugarCommerce\Data\Traits\Query\Shared;
use SugarCommerce\Data\Traits\Query\Sweep;
use SugarCommerce\Data\Traits\Query\Writes;

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
 * **Built once.** Every query object of a class shares one Schema, built by
 * the first, where BerlinDB builds one -- every Column in it -- for each.
 * Column lookups are remembered for the Schema, and a cached row is cut
 * down to the columns the current user may read in one step, worked out
 * once for that user rather than asked of every column of every row.
 *
 * Generated query classes extend this. A plugin whose add-ons register their
 * own tables should extend it once in its own namespace and point the
 * generator at that class, so every add-on builds on the plugin's copy.
 *
 * @since 1.0.0
 */
class Query extends Base {

	use Shared;
	use Sweep;
	use Writes;

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
	 * Set up the query, on its class's one Schema.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	#[\Override]
	protected function init(): void {
		$this->share_schema();

		parent::init();
	}

	/**
	 * Add a row, filling in what a function has to say.
	 *
	 * @param array<string, mixed> $data Column values.
	 *
	 * @return int|string|false The new id, or false when nothing was written.
	 * @since 1.0.0
	 */
	#[\Override]
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
	#[\Override]
	public function query( $query = [] ) {
		$query = wp_parse_args( $query );

		$query['orderby'] = $this->with_tie_break( $query['orderby'] ?? '', (string) ( $query['order'] ?? 'DESC' ) );

		return parent::query( $query );
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
	 * Drop rows' cached copies, every cached list, and every lookup by
	 * another cached column.
	 *
	 * For a caller that changes rows with SQL of its own -- an UPDATE with
	 * a JOIN, a set at once -- which BerlinDB never sees. Without this the
	 * rows read as they were until the cache expires. What update_item()
	 * does for itself, done by hand: BerlinDB's own cache cleaners are
	 * private. The group is the query's own, prefixed as BerlinDB caches
	 * rows in it; a lookup by another `cache_key` column is started afresh,
	 * since the SQL may have changed that column too. While core's cache
	 * invalidation is suspended, as during an import, nothing is dropped.
	 *
	 * @param array<int, int|string> $ids The rows.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function forget_items( array $ids ): void {
		global $_wp_suspend_cache_invalidation;

		$ids = array_values( array_filter( $ids, static fn( $id ): bool => ( is_int( $id ) || is_string( $id ) ) && '' !== $id ) );

		if ( [] === $ids || ! empty( $_wp_suspend_cache_invalidation ) ) {
			return;
		}

		wp_cache_delete_multiple( $ids, $this->cache_group );
		wp_cache_set_last_changed( $this->cache_group );

		$primary = $this->get_primary_column_name();

		foreach ( $this->get_column_names( [ 'cache_key' => true ] ) as $name ) {
			if ( $primary !== $name ) {
				wp_cache_set_last_changed( "{$this->cache_group}-by-{$name}" );
			}
		}
	}

	/**
	 * Drop a row's cached copy and every cached list.
	 *
	 * @param int $id The row.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	protected function forget( int $id ): void {
		$this->forget_items( [ $id ] );
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
