<?php
/**
 * A plugin's registered components.
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
 * The registry of one plugin's object types.
 *
 * One per plugin, named by its prefix: every hook it fires is that prefix
 * followed by what happened -- `{prefix}_register_components`,
 * `{prefix}_register_component_args` -- so two plugins using this library
 * at once never hear each other, and a plugin that had its own registry
 * before keeps every hook name it had.
 *
 * The plugin's add-ons register on the same instance, through the same
 * action as the plugin's own tables. If the plugin's tables took a private
 * path, the public one would be the part nobody tested.
 *
 * @since 1.0.0
 */
final class Components {

	/**
	 * Instances by prefix.
	 *
	 * @var array<string, self>
	 * @since 1.0.0
	 */
	private static array $instances = [];

	/**
	 * Registered components, keyed by name.
	 *
	 * @var array<string, Component>
	 * @since 1.0.0
	 */
	private array $components = [];

	/**
	 * Whether registration has been opened.
	 *
	 * @since 1.0.0
	 */
	private bool $ready = false;

	/**
	 * Hooks are named from this.
	 *
	 * @since 1.0.0
	 */
	public readonly string $hook_prefix;

	/**
	 * The plugin's CRUD functions are named from this.
	 *
	 * @since 1.0.0
	 */
	public readonly string $function_prefix;

	/**
	 * Installing, finding missing tables and repairing them.
	 *
	 * @since 1.0.0
	 */
	public readonly Installer $installer;

	/**
	 * Constructor.
	 *
	 * @param string               $prefix The plugin's prefix, e.g. `myplugin`.
	 * @param array<string, mixed> $args   {
	 *     Optional settings.
	 *
	 *     @type string $hook_prefix     Hooks are named from this. Default the prefix.
	 *     @type string $function_prefix The plugin's CRUD functions are named
	 *                                   from this: `{function_prefix}_get_{item}()`.
	 *                                   Default the prefix.
	 *     @type string $probe           The table, unprefixed, whose presence
	 *                                   says the plugin is installed. Default
	 *                                   the first registered component's.
	 * }
	 *
	 * @since 1.0.0
	 */
	public function __construct(
		public readonly string $prefix,
		array $args = []
	) {
		$this->hook_prefix     = (string) ( $args['hook_prefix'] ?? $prefix );
		$this->function_prefix = (string) ( $args['function_prefix'] ?? $prefix );
		$this->installer       = new Installer( $this, isset( $args['probe'] ) ? (string) $args['probe'] : null );
	}

	/**
	 * The shared instance for a prefix, made on first use.
	 *
	 * @param string               $prefix The plugin's prefix.
	 * @param array<string, mixed> $args   Settings, used the first time only.
	 *
	 * @return self
	 * @since 1.0.0
	 */
	public static function instance( string $prefix, array $args = [] ): self {
		return self::$instances[ $prefix ] ??= new self( $prefix, $args );
	}

	/**
	 * A hook's full name.
	 *
	 * @param string $name What happened, e.g. `register_components`.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function hook( string $name ): string {
		return $this->hook_prefix . '_' . $name;
	}

	/**
	 * Add a component.
	 *
	 * @param string               $name Component name, e.g. "product".
	 * @param array<string, mixed> $args Class names: schema, table, query,
	 *                                   row, and optionally meta and
	 *                                   controller.
	 *
	 * @return Component|null Null when the name or arguments were unusable.
	 * @since 1.0.0
	 */
	public function register( string $name, array $args ): ?Component {
		$name = sanitize_key( $name );

		if ( '' === $name || [] === $args ) {
			return null;
		}

		/**
		 * Filter a component's classes before it is built.
		 *
		 * Swapping the row class here is how an add-on decorates an object
		 * without forking the component that owns it.
		 *
		 * @param array<string, mixed> $args Class names.
		 * @param string               $name Component name.
		 *
		 * @since 1.0.0
		 */
		$args = (array) apply_filters( $this->hook( 'register_component_args' ), $args, $name );

		/**
		 * Fires before a component is registered.
		 *
		 * @param string               $name Component name.
		 * @param array<string, mixed> $args Class names.
		 *
		 * @since 1.0.0
		 */
		do_action( $this->hook( 'pre_register_component' ), $name, $args );

		/**
		 * Filters whether a component's tables are built as it is registered,
		 * or the first time something asks for them. Built at once under cron
		 * and on the command line; in the admin, only those whose stored
		 * version is behind (Component::build_outdated()).
		 *
		 * @param bool   $eager Whether to build them now.
		 * @param string $name  The component.
		 */
		$args['eager'] = (bool) apply_filters( $this->hook( 'build_tables_now' ), $args['eager'] ?? Component::eager(), $name );

		$component = new Component( $name, $args );

		$this->components[ $name ] = $component;

		/**
		 * Fires once a component is registered and its tables exist.
		 *
		 * @param Component $component The component.
		 * @param string    $name      Component name.
		 *
		 * @since 1.0.0
		 */
		do_action( $this->hook( 'register_component' ), $component, $name );

		return $component;
	}

	/**
	 * One component by name.
	 *
	 * @param string $name Component name.
	 *
	 * @return Component|null
	 * @since 1.0.0
	 */
	public function get( string $name ): ?Component {
		$component = $this->components[ sanitize_key( $name ) ] ?? null;

		/**
		 * Filter the component returned for a name.
		 *
		 * @param Component|null $component The component, or null.
		 * @param string         $name      Component name.
		 *
		 * @since 1.0.0
		 */
		$component = apply_filters( $this->hook( 'get_component' ), $component, $name );

		return $component instanceof Component ? $component : null;
	}

	/**
	 * Every registered component.
	 *
	 * @return array<string, Component>
	 * @since 1.0.0
	 */
	public function all(): array {
		/**
		 * Filter the full component list.
		 *
		 * @param array<string, Component> $components Registered components.
		 *
		 * @since 1.0.0
		 */
		return (array) apply_filters( $this->hook( 'get_components' ), $this->components );
	}

	/**
	 * Open registration, then let add-ons join in.
	 *
	 * Called once, from the plugin's bootstrap. Anything registering later
	 * has missed the action, which is why {@see self::is_ready()} exists --
	 * a plugin loading after this one can check and register outright
	 * rather than hooking something that has already fired.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function boot(): void {
		if ( $this->ready ) {
			return;
		}

		$this->ready = true;

		/**
		 * Fires when components should be registered.
		 *
		 * @since 1.0.0
		 */
		do_action( $this->hook( 'register_components' ) );

		/**
		 * Fires once every component exists.
		 *
		 * @param array<string, Component> $components Registered components.
		 *
		 * @since 1.0.0
		 */
		do_action( $this->hook( 'components_registered' ), $this->components );
	}

	/**
	 * Whether registration has already run.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function is_ready(): bool {
		return $this->ready;
	}

	/**
	 * Read a row by id and ask it one of its own methods.
	 *
	 * What a by-id helper -- "the order number of order 12" -- has in common
	 * with every other: the row is read through the plugin's public getter,
	 * `{function_prefix}_get_{item}()`, so the getter's filter and the object
	 * cache apply, and the answer is the row's own. Without such a getter the
	 * row is read through the component's query class.
	 *
	 * @param string       $item   The component's name.
	 * @param int          $id     The row.
	 * @param string       $method Which of the row's methods to ask.
	 * @param array<mixed> $args   What to pass that method.
	 * @param mixed        $none   The answer when there is no such row.
	 *
	 * @return mixed
	 * @since 1.0.0
	 */
	public function ask( string $item, int $id, string $method, array $args = [], mixed $none = null ): mixed {
		$row = $id > 0 ? $this->row( $item, $id ) : false;

		if ( ! is_object( $row ) || ! method_exists( $row, $method ) ) {
			return $none;
		}

		return $row->$method( ...$args );
	}

	/**
	 * Move one count on one row, in one statement.
	 *
	 * Through the component's own query, so it has to be one of this
	 * library's {@see Query} classes: BerlinDB's own has no adjust(). Does
	 * not fire the row's update hooks -- a count going up is not an edit
	 * somebody made.
	 *
	 * @param string $item   The component's name.
	 * @param int    $id     The row.
	 * @param string $column The count.
	 * @param int    $by     How far, signed.
	 *
	 * @return bool Whether the count moved.
	 * @since 1.0.0
	 */
	public function adjust( string $item, int $id, string $column, int $by ): bool {
		$query = $this->get( $item )?->query();

		if ( ! $query instanceof Query || ! $query->adjust( $id, $column, $by ) ) {
			return false;
		}

		/**
		 * Fires when a stored count has been moved.
		 *
		 * @param string $item   The component's name, such as `discount`.
		 * @param int    $id     The row.
		 * @param string $column The count, such as `use_count`.
		 * @param int    $by     How far it moved, signed.
		 *
		 * @since 1.0.0
		 */
		do_action( $this->hook( 'count_adjusted' ), $item, $id, $column, $by );

		return true;
	}

	/**
	 * Forget every component and open registration again. Tests only.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function reset(): void {
		$this->components = [];
		$this->ready      = false;

		$this->installer->reset();
	}

	/**
	 * One row, through the plugin's getter when there is one.
	 *
	 * @param string $item The component's name.
	 * @param int    $id   The row.
	 *
	 * @return mixed
	 * @since 1.0.0
	 */
	private function row( string $item, int $id ): mixed {
		$getter = $this->function_prefix . '_get_' . $item;

		if ( function_exists( $getter ) ) {
			return $getter( $id );
		}

		return $this->get( $item )?->query()?->get_item( $id );
	}
}
