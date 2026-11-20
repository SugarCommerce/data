<?php
/**
 * Bounded clean-ups: sweep().
 *
 * @package     SugarCommerce\Data
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Data\Traits\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Bounded clean-ups: sweep().
 *
 * Rows older than a date removed a batch at a time with their meta, or with
 * $dry only counted, from one WHERE.
 *
 * @since 1.0.0
 */
trait Sweep {

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
}
