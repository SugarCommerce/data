<?php
/**
 * The widgets query, on the counted Schema.
 *
 * @package SugarCommerce\Data
 */

declare( strict_types=1 );

namespace SugarCommerce\Data\Tests\Support;

use Acme\Database\Queries\Widget;

/**
 * The widgets query, counted.
 */
class CountedWidget extends Widget {

	/**
	 * The counted Schema.
	 *
	 * @var string
	 */
	protected $table_schema = CountedWidgets::class;
}
