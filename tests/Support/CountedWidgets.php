<?php
/**
 * The widgets Schema, counting its Columns and its column lookups.
 *
 * @package SugarCommerce\Data
 */

declare( strict_types=1 );

namespace SugarCommerce\Data\Tests\Support;

use Acme\Database\Schemas\Widgets;

/**
 * The widgets Schema, counted.
 */
class CountedWidgets extends Widgets {

	/**
	 * How many times the columns have been filtered.
	 *
	 * @var int
	 */
	public static int $lookups = 0;

	/**
	 * Its columns are counted ones.
	 *
	 * @var string
	 */
	protected $column = CountedColumn::class;

	/**
	 * Count the lookup, then make it.
	 *
	 * @param array<string, mixed> $args     Property => value to match.
	 * @param string               $operator `and`, `or` or `not`.
	 * @param bool|string          $field    A property to return from each.
	 *
	 * @return array<int|string, mixed>
	 */
	public function get_filtered_columns( $args = [], $operator = 'and', $field = false ) {
		++self::$lookups;

		return parent::get_filtered_columns( $args, $operator, $field );
	}
}
