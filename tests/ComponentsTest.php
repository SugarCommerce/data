<?php
/**
 * Registering components.
 *
 * @package SugarCommerce\Data
 */

declare( strict_types=1 );

namespace SugarCommerce\Data\Tests;

use Acme\Database\Queries;
use Acme\Database\Rows;
use Acme\Database\Schemas;
use Acme\Database\Tables;
use SugarCommerce\Data\Component;
use SugarCommerce\Data\Components;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The registry, its hooks, and the tables behind it.
 */
final class ComponentsTest extends TestCase {

	/**
	 * The fixture's components and whether each has meta.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function components(): array {
		return [
			'widget'  => [ 'widget', true ],
			'event'   => [ 'event', false ],
			'counter' => [ 'counter', false ],
		];
	}

	/**
	 * A fresh registry, for tests that register their own.
	 *
	 * @param string $prefix Its prefix.
	 *
	 * @return Components
	 */
	private static function fresh( string $prefix = 'other' ): Components {
		return new Components( $prefix );
	}

	/**
	 * The classes of the widget component.
	 *
	 * @return array<string, string|false>
	 */
	private static function widget_classes(): array {
		return [
			'schema' => Schemas\Widgets::class,
			'table'  => Tables\Widgets::class,
			'query'  => Queries\Widget::class,
			'row'    => Rows\Widget::class,
			'meta'   => Tables\WidgetMeta::class,
		];
	}

	/**
	 * Each component is registered and its tables exist.
	 *
	 * @param string $name     Component name.
	 * @param bool   $has_meta Whether it has meta.
	 *
	 * @return void
	 */
	#[DataProvider( 'components' )]
	public function test_the_component_is_installed( string $name, bool $has_meta ): void {
		global $wpdb;

		$component = acme_get_component( $name );

		$this->assertInstanceOf( Component::class, $component );
		$this->assertTrue( $component->table->exists() );
		$this->assertSame( $has_meta, $component->has_meta() );
		$this->assertCount( $has_meta ? 2 : 1, $component->tables() );
		$this->assertStringStartsWith( $wpdb->prefix . 'acme_', $component->table->table_name );

		if ( $has_meta ) {
			$this->assertTrue( $component->meta->exists() );
		}
	}

	/**
	 * A component registered where nothing installs -- a visitor's page, a
	 * REST request -- names its tables on $wpdb at once and builds them
	 * (and their schemas) only when asked; the metadata functions work
	 * before then.
	 *
	 * @return void
	 */
	public function test_tables_are_built_when_asked_for(): void {
		global $wpdb;

		$components = self::fresh( 'lazy' );
		$built      = static fn( Component $component ): array => ( new \ReflectionProperty( Component::class, 'built' ) )->getValue( $component );

		// As if nothing had named them yet.
		$names = [ $wpdb->acme_widgets, $wpdb->acme_widgetmeta, $wpdb->tables ];

		unset( $wpdb->acme_widgets, $wpdb->acme_widgetmeta );
		$wpdb->tables = array_values( array_diff( $wpdb->tables, [ 'acme_widgets', 'acme_widgetmeta' ] ) );

		add_filter( 'lazy_build_tables_now', '__return_false' );

		$component = $components->register( 'widget', self::widget_classes() );

		remove_filter( 'lazy_build_tables_now', '__return_false' );

		$this->assertSame( [], $built( $component ), 'nothing built yet' );
		$this->assertSame( $wpdb->prefix . 'acme_widgets', $wpdb->acme_widgets );
		$this->assertSame( $wpdb->prefix . 'acme_widgetmeta', $wpdb->acme_widgetmeta );
		$this->assertContains( 'acme_widgetmeta', $wpdb->tables );

		// Meta through WordPress's own functions, with no table object built.
		$id = acme_add_widget( [ 'name' => 'Lazy' ] );

		acme_update_widget_meta( $id, 'colour', 'green' );

		$this->assertSame( 'green', acme_get_widget_meta( $id, 'colour', true ) );
		$this->assertTrue( $component->has_meta() );
		$this->assertArrayNotHasKey( 'meta', $built( $component ) );

		// Asked for, built -- once.
		$table = $component->table;

		$this->assertTrue( $table->exists() );
		$this->assertSame( $table, $component->table() );
		$this->assertTrue( isset( $component->meta ) );
		$this->assertCount( 2, $component->tables() );

		// Where things install, at once.
		$eager = self::fresh( 'eager' )->register( 'widget', self::widget_classes() );

		$this->assertArrayHasKey( 'table', $built( $eager ) );

		[ $wpdb->acme_widgets, $wpdb->acme_widgetmeta, $wpdb->tables ] = $names;
	}

	/**
	 * The meta type is the prefixed item name, and the meta table has its key.
	 *
	 * @return void
	 */
	public function test_the_meta_type_matches_the_key_column(): void {
		global $wpdb;

		$component = acme_get_component( 'widget' );

		$this->assertSame( 'acme_widget', $component->query()->get_meta_type() );
		$this->assertContains( 'acme_widget_id', $wpdb->get_col( "SHOW COLUMNS FROM {$component->meta->table_name}" ) );

		$id = acme_add_widget( [ 'name' => 'Meta' ] );

		acme_update_widget_meta( $id, 'colour', 'blue' );

		$this->assertSame( 'blue', acme_get_widget_meta( $id, 'colour', true ) );
	}

	/**
	 * Everything the generated Core registers is registered, and the
	 * classes are the ones it named.
	 *
	 * @return void
	 */
	public function test_the_generated_core_registers_everything(): void {
		$this->assertSame( [ 'widget', 'event', 'counter' ], array_keys( acme_get_components() ) );
		$this->assertTrue( acme_components()->is_ready() );

		$widget = acme_get_component( 'widget' );

		$this->assertSame( Queries\Widget::class, $widget->class_for( 'query' ) );
		$this->assertSame( Rows\Widget::class, $widget->class_for( 'row' ) );
		$this->assertSame( Schemas\Widgets::class, $widget->class_for( 'schema' ) );
		$this->assertNull( $widget->class_for( 'controller' ) );
		$this->assertInstanceOf( Queries\Widget::class, $widget->query() );
	}

	/**
	 * The shared instance is one per prefix.
	 *
	 * @return void
	 */
	public function test_one_instance_per_prefix(): void {
		$this->assertSame( acme_components(), Components::instance( 'acme' ) );
		$this->assertNotSame( acme_components(), Components::instance( 'acme_two' ) );
		$this->assertSame( 'acme', acme_components()->prefix );
	}

	/**
	 * Every hook is named from the prefix, in order.
	 *
	 * @return void
	 */
	public function test_hooks_are_named_from_the_prefix(): void {
		$heard    = [];
		$registry = self::fresh( 'shop' );

		foreach ( [ 'register_component_args', 'get_component', 'get_components' ] as $filter ) {
			add_filter(
				"shop_{$filter}",
				static function ( $value ) use ( &$heard, $filter ) {
					$heard[] = $filter;

					return $value;
				}
			);
		}

		foreach ( [ 'pre_register_component', 'register_component', 'register_components', 'components_registered' ] as $action ) {
			add_action(
				"shop_{$action}",
				static function () use ( &$heard, $action ): void {
					$heard[] = $action;
				}
			);
		}

		add_action( 'shop_register_components', static fn() => $registry->register( 'widget', self::widget_classes() ) );

		$registry->boot();
		$registry->get( 'widget' );
		$registry->all();

		$this->assertSame(
			[ 'register_components', 'register_component_args', 'pre_register_component', 'register_component', 'components_registered', 'get_component', 'get_components' ],
			$heard
		);
		$this->assertSame( 'shop_count_adjusted', $registry->hook( 'count_adjusted' ) );
	}

	/**
	 * Another plugin's registry hears none of this one's hooks.
	 *
	 * @return void
	 */
	public function test_two_plugins_do_not_meet(): void {
		$heard = 0;

		add_action(
			'shop_register_component',
			static function () use ( &$heard ): void {
				++$heard;
			}
		);

		self::fresh( 'other' )->register( 'widget', self::widget_classes() );

		$this->assertSame( 0, $heard );
		$this->assertNull( self::fresh( 'other' )->get( 'widget' ) );
	}

	/**
	 * Hook prefix and function prefix can differ from the prefix.
	 *
	 * @return void
	 */
	public function test_prefixes_can_differ(): void {
		$registry = new Components(
			'acme',
			[
				'hook_prefix'     => 'acme_hooks',
				'function_prefix' => 'acme_api',
			]
		);

		$this->assertSame( 'acme_hooks_register_components', $registry->hook( 'register_components' ) );
		$this->assertSame( 'acme_api', $registry->function_prefix );
	}

	/**
	 * The args filter can swap a class before the component is built.
	 *
	 * @return void
	 */
	public function test_the_args_filter_can_swap_a_class(): void {
		$registry = self::fresh( 'swap' );

		add_filter(
			'swap_register_component_args',
			static fn( array $args ): array => [ 'row' => \stdClass::class ] + $args
		);

		$this->assertSame( \stdClass::class, $registry->register( 'widget', self::widget_classes() )->class_for( 'row' ) );
	}

	/**
	 * A name or arguments that cannot be used register nothing.
	 *
	 * @return void
	 */
	public function test_unusable_registrations_are_refused(): void {
		$registry = self::fresh();

		$this->assertNull( $registry->register( '', self::widget_classes() ) );
		$this->assertNull( $registry->register( '!!!', self::widget_classes() ) );
		$this->assertNull( $registry->register( 'widget', [] ) );
		$this->assertSame( [], $registry->all() );

		$this->assertSame( 'my_widget', $registry->register( 'My_Widget', self::widget_classes() )->name );
		$this->assertNotNull( $registry->get( 'MY_WIDGET' ) );
	}

	/**
	 * A class that does not exist is not built.
	 *
	 * @return void
	 */
	public function test_missing_classes_are_left_out(): void {
		$component = self::fresh()->register(
			'ghost',
			[
				'table' => 'Nope\\Table',
				'meta'  => false,
				'query' => 'Nope\\Query',
			]
		);

		$this->assertNull( $component->table );
		$this->assertNull( $component->meta );
		$this->assertSame( [], $component->tables() );
		$this->assertNull( $component->query() );
	}

	/**
	 * The get filter can only answer with a component.
	 *
	 * @return void
	 */
	public function test_the_get_filter(): void {
		$registry = self::fresh( 'filtered' );
		$other    = new Component( 'swapped', [] );

		add_filter( 'filtered_get_component', static fn() => $other );

		$this->assertSame( $other, $registry->get( 'anything' ) );

		add_filter( 'filtered_get_component', static fn() => new \stdClass(), 20 );

		$this->assertNull( $registry->get( 'anything' ) );
	}

	/**
	 * Boot runs once; reset opens it again.
	 *
	 * @return void
	 */
	public function test_boot_runs_once(): void {
		$registry = self::fresh( 'once' );
		$fired    = 0;

		add_action(
			'once_register_components',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		$this->assertFalse( $registry->is_ready() );

		$registry->boot();
		$registry->boot();

		$this->assertSame( 1, $fired );
		$this->assertTrue( $registry->is_ready() );

		$registry->register( 'widget', self::widget_classes() );
		$registry->reset();

		$this->assertFalse( $registry->is_ready() );
		$this->assertSame( [], $registry->all() );
	}

	/**
	 * A controller class is built at registration.
	 *
	 * @return void
	 */
	public function test_a_controller_is_built(): void {
		$GLOBALS['rc_controller_built'] = 0;

		$class = get_class(
			new class() {
				/**
				 * Count construction.
				 */
				public function __construct() {
					$GLOBALS['rc_controller_built'] = (int) ( $GLOBALS['rc_controller_built'] ?? 0 ) + 1;
				}
			}
		);

		$GLOBALS['rc_controller_built'] = 0;

		self::fresh()->register( 'api', [ 'controller' => $class ] );

		$this->assertSame( 1, $GLOBALS['rc_controller_built'] );
	}
}
