<?php
/**
 * One registered component.
 *
 * @package     SugarCommerce\Data
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Data;

defined( 'ABSPATH' ) || exit;

/**
 * A component is one object type and the classes behind it.
 *
 * Registering one is how a plugin, or any add-on to it, gets a table: the
 * schema describes the columns, the table installs and upgrades them, the
 * query reads rows and the row is what comes back. Nothing else has to be
 * written to have a working, cached, meta-capable object type.
 *
 * Nothing is constructed here but what a request needs. The table names
 * are put on $wpdb at once -- the metadata functions and every query read
 * them -- but BerlinDB's Table objects, which build each table's whole
 * schema to have its CREATE TABLE ready, are built when something asks for
 * them (`->table`, `->meta`, tables()): the installer, the upgrade check on
 * admin_init, a screen that lists them. In the admin, under cron and on the
 * command line they are built at once, as before, so BerlinDB's own upgrade
 * routine is hooked; a visitor's page or a REST request, which never
 * installs anything, is spared some forty schemas. A Query is built per call
 * with its own arguments, and a Row from a result set.
 *
 * @since 1.0.0
 */
final class Component {

	/**
	 * Component name, e.g. "product".
	 *
	 * @since 1.0.0
	 */
	public readonly string $name;

	/**
	 * The table classes, by `table` and `meta`.
	 *
	 * @var array<string, string>
	 * @since 1.0.0
	 */
	private readonly array $tables;

	/**
	 * The tables built so far, by `table` and `meta`.
	 *
	 * @var array<string, object|null>
	 * @since 1.0.0
	 */
	private array $built = [];

	/**
	 * Class names, constructed on demand.
	 *
	 * @var array<string, string>
	 * @since 1.0.0
	 */
	private readonly array $classes;

	/**
	 * Constructor.
	 *
	 * @param string               $name Component name.
	 * @param array<string, mixed> $args Class names: schema, table, query,
	 *                                   row, and optionally meta and
	 *                                   controller; `eager`, whether to
	 *                                   build the tables now (see eager()).
	 *
	 * @since 1.0.0
	 */
	public function __construct( string $name, array $args ) {
		$this->name = $name;

		$tables = [];

		foreach ( [ 'table', 'meta' ] as $key ) {
			$class = $args[ $key ] ?? null;

			if ( is_string( $class ) && '' !== $class && class_exists( $class ) ) {
				$tables[ $key ] = $class;

				self::name_table( $class );
			}
		}

		$this->tables = $tables;

		if ( (bool) ( $args['eager'] ?? self::eager() ) ) {
			$this->table();
			$this->meta();
		}

		$classes = [];

		foreach ( [ 'schema', 'query', 'row', 'controller' ] as $key ) {
			if ( ! empty( $args[ $key ] ) && is_string( $args[ $key ] ) ) {
				$classes[ $key ] = $args[ $key ];
			}
		}

		$this->classes = $classes;

		// A REST controller does need to exist now -- it registers routes on
		// rest_api_init, which has already been scheduled by the time a
		// component is registered.
		if ( isset( $classes['controller'] ) && class_exists( $classes['controller'] ) ) {
			new $classes['controller']();
		}
	}

	/**
	 * The class name registered for one interface.
	 *
	 * @param string $interface One of schema, query, row, controller.
	 *
	 * @return string|null
	 * @since 1.0.0
	 */
	public function class_for( string $interface ): ?string {
		return $this->classes[ $interface ] ?? null;
	}

	/**
	 * A new query object for this component.
	 *
	 * @param array<string, mixed> $args Query arguments, as BerlinDB takes them.
	 *
	 * @return object|null Null when no query class is registered.
	 * @since 1.0.0
	 */
	public function query( array $args = [] ): ?object {
		$class = $this->class_for( 'query' );

		if ( null === $class || ! class_exists( $class ) ) {
			return null;
		}

		return new $class( $args );
	}

	/**
	 * Whether this component stores meta.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function has_meta(): bool {
		return isset( $this->tables['meta'] );
	}

	/**
	 * The primary table, built the first time it is asked for.
	 *
	 * @return object|null
	 * @since 1.0.0
	 */
	public function table(): ?object {
		return $this->build( 'table' );
	}

	/**
	 * The meta table, built the first time it is asked for.
	 *
	 * @return object|null
	 * @since 1.0.0
	 */
	public function meta(): ?object {
		return $this->build( 'meta' );
	}

	/**
	 * `->table` and `->meta`, read as the properties they were.
	 *
	 * @param string $name `table` or `meta`.
	 *
	 * @return object|null
	 * @since 1.0.0
	 */
	public function __get( string $name ): ?object {
		return in_array( $name, [ 'table', 'meta' ], true ) ? $this->build( $name ) : null;
	}

	/**
	 * Whether `->table` or `->meta` is there.
	 *
	 * @param string $name `table` or `meta`.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function __isset( string $name ): bool {
		return null !== $this->__get( $name );
	}

	/**
	 * The instantiated tables: the primary, and the meta table if any.
	 *
	 * @return array<int, object>
	 * @since 1.0.0
	 */
	public function tables(): array {
		return array_values( array_filter( [ $this->table(), $this->meta() ] ) );
	}

	/**
	 * Whether tables are built when registered: in the admin, where
	 * BerlinDB's upgrade check runs on admin_init; under cron; on the
	 * command line, which installs and tests.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public static function eager(): bool {
		return is_admin() || wp_doing_cron() || 'cli' === PHP_SAPI;
	}

	/**
	 * One of the tables, built the first time it is asked for.
	 *
	 * @param string $key `table` or `meta`.
	 *
	 * @return object|null
	 * @since 1.0.0
	 */
	private function build( string $key ): ?object {
		if ( ! array_key_exists( $key, $this->built ) ) {
			$class               = $this->tables[ $key ] ?? null;
			$this->built[ $key ] = null === $class ? null : new $class();
		}

		return $this->built[ $key ];
	}

	/**
	 * Put a table's name on $wpdb, as BerlinDB's Table does when it is
	 * built: `$wpdb->sugarcart_orders`, and the name in `$wpdb->tables`.
	 *
	 * Read from the class's own defaults -- its name, prefix and whether it
	 * is global -- without building it.
	 *
	 * @param string $class The table class.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private static function name_table( string $class ): void {
		global $wpdb;

		$defaults = ( new \ReflectionClass( $class ) )->getDefaultProperties();
		$name     = (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) ( $defaults['name'] ?? '' ) ) );
		$prefix   = trim( (string) ( $defaults['prefix'] ?? '' ) );

		if ( '' === $name ) {
			return;
		}

		$named  = '' === $prefix || str_starts_with( $name, $prefix . '_' ) ? $name : $prefix . '_' . $name;
		$global = ! empty( $defaults['global'] );
		$group  = $global ? 'ms_global_tables' : 'tables';

		$wpdb->{$named} = $wpdb->get_blog_prefix( $global ? 0 : null ) . $named;

		if ( ! isset( $wpdb->{$group} ) || ! is_array( $wpdb->{$group} ) ) {
			$wpdb->{$group} = [];
		}

		if ( ! in_array( $named, $wpdb->{$group}, true ) ) {
			$wpdb->{$group}[] = $named;
		}
	}
}
