<?php
/**
 * A Column that counts how many of it are built.
 *
 * @package SugarCommerce\Data
 */

declare( strict_types=1 );

namespace SugarCommerce\Data\Tests\Support;

use BerlinDB\Database\Kern\Column;

/**
 * BerlinDB's Column, counted.
 */
class CountedColumn extends Column {

	/**
	 * How many have been built.
	 *
	 * @var int
	 */
	public static int $built = 0;

	/**
	 * Count it, then build it.
	 *
	 * @param array<string, mixed>|object $args The column's definition.
	 */
	public function __construct( $args = [] ) {
		++self::$built;

		parent::__construct( $args );
	}
}
