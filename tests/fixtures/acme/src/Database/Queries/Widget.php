<?php
/**
 * Querying widgets.
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
 * Reads widgets.
 *
 * @since 1.0.0
 */
class Widget extends Query {

	/**
	 * Table name, without any prefix.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	protected $table_name = 'widgets';

	/**
	 * Table prefix.
	 *
	 * Must match the table's. It also drives get_meta_type(), which decides
	 * the acme_widget_id column the meta table is keyed on.
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
	protected $table_alias = 'w';

	/**
	 * Class describing the columns.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	protected $table_schema = '\\Acme\\Database\\Schemas\\Widgets';

	/**
	 * Singular item name.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	protected $item_name = 'widget';

	/**
	 * Plural item name.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	protected $item_name_plural = 'widgets';

	/**
	 * What a result row becomes.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	protected $item_shape = '\\Acme\\Database\\Rows\\Widget';

	/**
	 * Cache group.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	protected $cache_group = 'acme_widgets';

	/**
	 * Columns a new row is given a value for when the caller names none.
	 *
	 * Column => the function that says what. From "default_function" in
	 * bin/schema.json.
	 *
	 * @var array<string, callable-string>
	 * @since 1.0.0
	 */
	protected $insert_defaults = array(
		'currency' => 'acme_get_default_currency',
	);
}
