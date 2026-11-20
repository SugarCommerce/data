<?php
/**
 * What every query of a class shares: its Schema, the answers its columns
 * give, and which of them the current user may read.
 *
 * @package     SugarCommerce\Data
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Data\Traits\Query;

use BerlinDB\Database\Kern\Schema;
use WeakMap;

defined( 'ABSPATH' ) || exit;

/**
 * What every query of a class shares: its Schema, the answers its columns
 * give, and which of them the current user may read.
 *
 * BerlinDB builds a query's Schema afresh for every query object, every
 * Column in it booted and validated again, and a plugin makes a query
 * object for every row it gets by id. A webhook that touches a few
 * thousand rows built fifty thousand Columns, to describe the same dozen
 * tables over and over. Nothing in a Schema depends on the query that holds
 * it -- it is the table's declaration -- so the first query of a class
 * builds it and every later one is handed that one.
 *
 * Then every row read is checked column by column: the column found by
 * name, by filtering the whole Schema, and the current user asked whether
 * they may see it. Five thousand rows of forty columns is two hundred
 * thousand of each, for an answer that is the same for every row. The
 * lookups are remembered for each Schema, and which columns a user may
 * read is worked out once per user; a row read from the cache is then cut
 * down to those columns in one step.
 *
 * @since 1.0.0
 */
trait Shared {

	/**
	 * Each Schema class, built once.
	 *
	 * Class name => the one Schema every query of it uses.
	 *
	 * @var array<string, Schema>
	 * @since 1.0.0
	 */
	private static array $schemas = [];

	/**
	 * What each Schema's columns have answered.
	 *
	 * Keyed by the Schema itself, so one passed in at run time, rather than
	 * named by class, has its own and takes them with it when it goes.
	 *
	 * @var WeakMap<Schema, array<string, mixed>>|null
	 * @since 1.0.0
	 */
	private static ?WeakMap $answers = null;

	/**
	 * Get columns, filtered, from what this Schema answered last time.
	 *
	 * Every column lookup BerlinDB makes -- by name, by flag, a field from
	 * each -- comes through here and filters the whole Schema to answer it.
	 * The columns do not change once the Schema is built, so neither does
	 * the answer.
	 *
	 * @param array<string, mixed> $args     Property => value to match.
	 * @param string               $operator `and`, `or` or `not`.
	 * @param bool|string          $field    A property to return from each, or false for the columns.
	 *
	 * @return array<int|string, mixed>
	 * @since 1.0.0
	 */
	#[\Override]
	public function get_columns( $args = [], $operator = 'and', $field = false ) {
		$schema = $this->get_schema();

		// An argument that cannot be written down as a key -- a closure,
		// say -- is answered afresh each time.
		try {
			$key = serialize( [ $args, $operator, $field ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- A lookup key in memory, never stored or read back.
		} catch ( \Throwable ) {
			$key = null;
		}

		if ( null === $schema || null === $key ) {
			return parent::get_columns( $args, $operator, $field );
		}

		self::remember( $schema );

		if ( ! array_key_exists( $key, self::$answers[ $schema ]['columns'] ) ) {
			self::$answers[ $schema ]['columns'][ $key ] = parent::get_columns( $args, $operator, $field );
		}

		return self::$answers[ $schema ]['columns'][ $key ];
	}

	/**
	 * The primary column's name, from what this Schema answered last time.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	#[\Override]
	public function get_primary_column_name() {
		$schema = $this->get_schema();

		if ( null === $schema ) {
			return parent::get_primary_column_name();
		}

		self::remember( $schema );

		self::$answers[ $schema ]['primary'] ??= (string) parent::get_primary_column_name();

		return self::$answers[ $schema ]['primary'];
	}

	/**
	 * Get a row by a column's value, cut down from its cached copy.
	 *
	 * BerlinDB's own get_item_by() checks a cached row one column at a
	 * time: the column looked up by name, then current_user_can() asked
	 * for it, for every column of every row. It is private work this class
	 * cannot reach, so a row asked for by id and already in the cache is
	 * read here instead: kept to the columns the current user may read,
	 * worked out once for them, and shaped as BerlinDB shapes it. Anything
	 * else -- another column, a row not cached yet -- goes to BerlinDB,
	 * which reads it and caches it for next time.
	 *
	 * @param string     $column_name  The column.
	 * @param int|string $column_value Its value.
	 *
	 * @return object|false The row, or false when there is none.
	 * @since 1.0.0
	 */
	#[\Override]
	public function get_item_by( $column_name = '', $column_value = '' ) {
		$row = $this->cached_row( $column_name, $column_value );

		if ( null === $row ) {
			return parent::get_item_by( $column_name, $column_value );
		}

		return $this->shape_row( array_intersect_key( (array) $row, $this->allowed_columns( 'select' ) ) );
	}

	/**
	 * The columns the current user may `select`, `insert`, `update` or
	 * `delete`.
	 *
	 * A column is allowed when its `caps` names a capability for the
	 * method and the user has it, as BerlinDB decides it for each column of
	 * each row. The answer is the same for every row, so it is worked out
	 * once per user, each capability asked once: a request that switches
	 * user with wp_set_current_user() gets the new user's answer, and a
	 * switch back gets the first one's again.
	 *
	 * @param string $method `select`, `insert`, `update` or `delete`.
	 *
	 * @return array<string, true> Column name => true.
	 * @since 1.0.0
	 */
	protected function allowed_columns( string $method ): array {
		$schema = $this->get_schema();

		if ( null === $schema ) {
			return [];
		}

		self::remember( $schema );

		$user = get_current_user_id();

		if ( isset( self::$answers[ $schema ]['allowed'][ $method ][ $user ] ) ) {
			return self::$answers[ $schema ]['allowed'][ $method ][ $user ];
		}

		$allowed = [];
		$asked   = [];

		foreach ( $this->get_column_names() as $name ) {
			// The first column of a name decides for it, as BerlinDB's own
			// lookup by name finds the first.
			if ( isset( $allowed[ $name ] ) ) {
				continue;
			}

			$caps = $this->get_column_field( [ 'name' => $name ], 'caps' );
			$cap  = is_array( $caps ) && isset( $caps[ $method ] ) && is_string( $caps[ $method ] ) ? $caps[ $method ] : '';

			$allowed[ $name ] = ! empty( $cap ) && ( $asked[ $cap ] ??= current_user_can( $cap ) );
		}

		self::$answers[ $schema ]['allowed'][ $method ][ $user ] = array_filter( $allowed );

		return self::$answers[ $schema ]['allowed'][ $method ][ $user ];
	}

	/**
	 * Hand this query the one Schema of its class, building it the first
	 * time.
	 *
	 * Only a Schema named by class is shared. One that cannot be built is
	 * left to BerlinDB, which tries again and logs why it failed.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	protected function share_schema(): void {
		if ( ! is_string( $this->table_schema ) || '' === $this->table_schema ) {
			return;
		}

		$class = ltrim( $this->table_schema, '\\' );

		if ( ! isset( self::$schemas[ $class ] ) ) {
			if ( ! class_exists( $class ) || ! is_a( $class, Schema::class, true ) ) {
				return;
			}

			try {
				$schema = $this->instantiate_class( $class );
			} catch ( \Throwable ) {
				return;
			}

			if ( ! $schema instanceof Schema ) {
				return;
			}

			self::$schemas[ $class ] = $schema;
		}

		$this->table_schema = self::$schemas[ $class ];
	}

	/**
	 * A row by its id from the cache, when that is how it is asked for.
	 *
	 * The id's column has to be one BerlinDB caches rows by, as it does for
	 * its own read; null sends the caller to BerlinDB.
	 *
	 * @param mixed $column The column.
	 * @param mixed $value  Its value.
	 *
	 * @return array<string, mixed>|object|null
	 * @since 1.0.0
	 */
	private function cached_row( mixed $column, mixed $value ): array|object|null {
		if ( ( ! is_int( $value ) && ! is_string( $value ) ) || '' === $value ) {
			return null;
		}

		$primary = $this->get_primary_column_name();

		if ( $primary !== $column || ! in_array( $primary, $this->get_column_names( [ 'cache_key' => true ] ), true ) ) {
			return null;
		}

		$row = wp_cache_get( $value, $this->cache_group );

		return is_array( $row ) || is_object( $row ) ? $row : null;
	}

	/**
	 * Shape a row as BerlinDB shapes one: JSON columns decoded, then the
	 * query's row class, or a plain object when the query asked for fields.
	 *
	 * @param array<string, mixed> $row The row's columns.
	 *
	 * @return object
	 * @since 1.0.0
	 */
	private function shape_row( array $row ): object {
		foreach ( $this->get_columns( [ 'type' => 'json' ] ) as $column ) {
			if ( isset( $row[ $column->name ] ) ) {
				$row[ $column->name ] = $column->cast( $row[ $column->name ] );
			}
		}

		$shape = $this->get_current_string( 'item_shape' );

		if ( 'stdClass' === $shape || null === $shape || '' === $shape ) {
			return (object) $row;
		}

		return $this->instantiate_class( $shape, '', $row ) ?? (object) $row;
	}

	/**
	 * Make room for what a Schema answers, the first time it is asked.
	 *
	 * @param Schema $schema The Schema.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private static function remember( Schema $schema ): void {
		self::$answers ??= new WeakMap();

		if ( ! isset( self::$answers[ $schema ] ) ) {
			self::$answers[ $schema ] = [
				'columns' => [],
				'primary' => null,
				'allowed' => [],
			];
		}
	}
}
