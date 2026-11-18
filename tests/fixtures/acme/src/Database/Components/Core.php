<?php
/**
 * Registering this plugin's own components.
 *
 * @package     Acme\Database\Components
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

namespace Acme\Database\Components;

use Acme\Database\Queries;

defined( 'ABSPATH' ) || exit;
use Acme\Database\Rows;
use Acme\Database\Schemas;
use Acme\Database\Tables;

/**
 * The components this plugin ships with.
 *
 * Registered on the same action an add-on would use, deliberately: if the
 * core components took a private path, the public one would be the part
 * nobody tested. Anything that breaks for an add-on breaks here first.
 *
 * Generated from bin/schema.json.
 *
 * @since 1.0.0
 */
final class Core {

	/**
	 * Hook registration.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function init(): void {
		add_action( 'acme_register_components', array( self::class, 'register' ) );
	}

	/**
	 * Register every component.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function register(): void {
		acme_register_component(
			'widget',
			array(
				'schema' => Schemas\Widgets::class,
				'table'  => Tables\Widgets::class,
				'query'  => Queries\Widget::class,
				'row'    => Rows\Widget::class,
				'meta'   => Tables\WidgetMeta::class,
			)
		);

		acme_register_component(
			'event',
			array(
				'schema' => Schemas\Events::class,
				'table'  => Tables\Events::class,
				'query'  => Queries\Event::class,
				'row'    => Rows\Event::class,
				'meta'   => false,
			)
		);

		acme_register_component(
			'counter',
			array(
				'schema' => Schemas\Counters::class,
				'table'  => Tables\Counters::class,
				'query'  => Queries\Counter::class,
				'row'    => Rows\Counter::class,
				'meta'   => false,
			)
		);
	}
}
