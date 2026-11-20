<?php
/**
 * The events Schema with a column only managers may read, one nobody may,
 * one of JSON, and a second cached column.
 *
 * @package SugarCommerce\Data
 */

declare( strict_types=1 );

namespace SugarCommerce\Data\Tests\Support;

use Acme\Database\Schemas\Events;

/**
 * The events Schema: `error` needs manage_options to read, `attempts` names
 * no capability so nobody may, `payload` is JSON, and rows are cached by
 * `event_ref` too.
 */
class GuardedEvents extends Events {

	/**
	 * Change the four columns, then build it.
	 *
	 * @param array<string, mixed> $args Schema arguments.
	 */
	public function __construct( $args = [] ) {
		foreach ( $this->columns as $index => $column ) {
			if ( 'event_ref' === $column['name'] ) {
				$this->columns[ $index ]['cache_key'] = true;
			}

			if ( 'error' === $column['name'] ) {
				$this->columns[ $index ]['caps'] = [ 'select' => 'manage_options' ];
			}

			if ( 'attempts' === $column['name'] ) {
				$this->columns[ $index ]['caps'] = [ 'select' => '' ];
			}

			if ( 'payload' === $column['name'] ) {
				$this->columns[ $index ]['type'] = 'json';
			}
		}

		parent::__construct( $args );
	}
}
