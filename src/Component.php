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
 * Only the table and its meta table are constructed here. BerlinDB's Table
 * registers itself against $wpdb and hooks its own upgrade routine, so it has
 * to exist on every request -- but a Query is built per call with its own
 * arguments, and a Row is built from a result set. Instantiating those at
 * registration would be a hundred objects per request to throw away unused,
 * which is what the class names are for.
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
	 * The instantiated primary table.
	 *
	 * @since 1.0.0
	 */
	public readonly ?object $table;

	/**
	 * The instantiated meta table, when the component has one.
	 *
	 * @since 1.0.0
	 */
	public readonly ?object $meta;

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
	 *                                   controller.
	 *
	 * @since 1.0.0
	 */
	public function __construct( string $name, array $args ) {
		$this->name = $name;

		$this->table = self::construct( $args['table'] ?? null );
		$this->meta  = self::construct( $args['meta'] ?? null );

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
		return null !== $this->meta;
	}

	/**
	 * The instantiated tables: the primary, and the meta table if any.
	 *
	 * @return array<int, object>
	 * @since 1.0.0
	 */
	public function tables(): array {
		return array_values( array_filter( [ $this->table, $this->meta ] ) );
	}

	/**
	 * Build one interface class, when it names a real one.
	 *
	 * @param mixed $class Class name, or anything else.
	 *
	 * @return object|null
	 * @since 1.0.0
	 */
	private static function construct( mixed $class ): ?object {
		if ( ! is_string( $class ) || '' === $class || ! class_exists( $class ) ) {
			return null;
		}

		return new $class();
	}
}
