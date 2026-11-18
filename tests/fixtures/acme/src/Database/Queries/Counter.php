<?php
/**
 * Querying counters.
 *
 * @package     Acme\Database\Queries
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

namespace Acme\Database\Queries;

use SugarCommerce\Data\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Reads counters.
 *
 * @since 1.0.0
 */
class Counter extends Query {

	/**
	 * Table name, without any prefix.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	protected $table_name = 'counters';

	/**
	 * Table prefix.
	 *
	 * Must match the table's. It also drives get_meta_type(), which decides
	 * the acme_counter_id column the meta table is keyed on.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	protected $prefix = 'acme';

	/**
	 * Table alias, used in JOINs.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	protected $table_alias = 'c';

	/**
	 * Class describing the columns.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	protected $table_schema = '\\Acme\\Database\\Schemas\\Counters';

	/**
	 * Singular item name.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	protected $item_name = 'counter';

	/**
	 * Plural item name.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	protected $item_name_plural = 'counters';

	/**
	 * What a result row becomes.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	protected $item_shape = '\\Acme\\Database\\Rows\\Counter';

	/**
	 * Cache group.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	protected $cache_group = 'acme_counters';
}
