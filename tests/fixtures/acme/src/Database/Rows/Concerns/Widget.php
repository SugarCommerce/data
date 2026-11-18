<?php
/**
 * What a widget can be asked beyond its columns. Written by hand.
 *
 * @package Acme\Database\Rows
 */

namespace Acme\Database\Rows\Concerns;

defined( 'ABSPATH' ) || exit;

/**
 * Widget helpers.
 */
trait Widget {

	/**
	 * The widget's name, with something after it.
	 *
	 * @param string $suffix What to add.
	 *
	 * @return string
	 */
	public function label( string $suffix = '' ): string {
		return $this->name . $suffix;
	}
}
