<?php
/**
 * The Acme fixture plugin: its registry, wrappers and generated functions.
 *
 * What a real plugin's bootstrap does with this library, in miniature.
 *
 * @package SugarCommerce\Data
 */

declare( strict_types=1 );

use Acme\Database\Components\Core;
use SugarCommerce\Data\Component;
use SugarCommerce\Data\Components;

/**
 * The plugin's registry.
 *
 * @return Components
 */
function acme_components(): Components {
	return Components::instance( 'acme', [ 'probe' => 'widgets' ] );
}

/**
 * Register a component: what the generated Core class calls.
 *
 * @param string               $name Component name.
 * @param array<string, mixed> $args Class names.
 *
 * @return Component|null
 */
function acme_register_component( string $name = '', array $args = [] ): ?Component {
	return acme_components()->register( $name, $args );
}

/**
 * One component.
 *
 * @param string $name Component name.
 *
 * @return Component|null
 */
function acme_get_component( string $name ): ?Component {
	return acme_components()->get( $name );
}

/**
 * Every component.
 *
 * @return array<string, Component>
 */
function acme_get_components(): array {
	return acme_components()->all();
}

/**
 * The currency a new widget is given.
 *
 * @return string
 */
function acme_get_default_currency(): string {
	return (string) get_option( 'acme_currency', 'USD' );
}

foreach ( [ 'widgets', 'events', 'counters' ] as $acme_file ) {
	require_once __DIR__ . "/functions/{$acme_file}.php";
}

Core::init();
acme_components()->boot();
acme_components()->installer->init();
