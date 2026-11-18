<?php
/**
 * Querying events.
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
 * Reads events.
 *
 * @since 1.0.0
 */
class Event extends Query {

	/**
	 * Table name, without any prefix.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	protected $table_name = 'events';

	/**
	 * Table prefix.
	 *
	 * Must match the table's. It also drives get_meta_type(), which decides
	 * the acme_event_id column the meta table is keyed on.
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
	protected $table_alias = 'e';

	/**
	 * Class describing the columns.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	protected $table_schema = '\\Acme\\Database\\Schemas\\Events';

	/**
	 * Singular item name.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	protected $item_name = 'event';

	/**
	 * Plural item name.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	protected $item_name_plural = 'events';

	/**
	 * What a result row becomes.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	protected $item_shape = '\\Acme\\Database\\Rows\\Event';

	/**
	 * Cache group.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	protected $cache_group = 'acme_events';
}
