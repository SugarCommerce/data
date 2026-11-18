<?php
/**
 * Something sold, with a count of how often.
 *
 * @package     Acme\Database\Schemas
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

namespace Acme\Database\Schemas;

use BerlinDB\Database\Kern\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Something sold, with a count of how often.
 *
 * Generated from bin/schema.json -- edit that and re-run
 * vendor/bin/schema generate rather than changing this by hand.
 *
 * @since 1.0.0
 */
class Widgets extends Schema {

	/**
	 * Columns.
	 *
	 * @var array
	 * @since 1.0.0
	 */
	public $columns = array(

		array(
			'name'      => 'id',
			'type'      => 'bigint',
			'length'    => '20',
			'unsigned'  => true,
			'sortable'  => true,
			'cache_key' => true,
			'extra'     => 'auto_increment',
		),
		array(
			'name'       => 'name',
			'type'       => 'varchar',
			'length'     => '100',
			'searchable' => true,
			'sortable'   => true,
			'default'    => '',
		),
		array(
			'name'       => 'status',
			'type'       => 'varchar',
			'length'     => '20',
			'sortable'   => true,
			'transition' => true,
			'default'    => 'active',
		),
		array(
			'name'     => 'price',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => false,
			'sortable' => true,
			'default'  => '0',
		),
		array(
			'name'    => 'currency',
			'type'    => 'char',
			'length'  => '3',
			'default' => '',
		),
		array(
			'name'     => 'use_count',
			'type'     => 'int',
			'length'   => '11',
			'unsigned' => true,
			'default'  => '0',
		),
		array(
			'name'       => 'date_sold',
			'type'       => 'datetime',
			'sortable'   => true,
			'allow_null' => true,
		),
		array(
			'name'       => 'date_created',
			'type'       => 'datetime',
			'sortable'   => true,
			'date_query' => true,
			'created'    => true,
			'default'    => '',
		),
		array(
			'name'       => 'date_modified',
			'type'       => 'datetime',
			'sortable'   => true,
			'date_query' => true,
			'modified'   => true,
			'default'    => '',
		),

	);

	/**
	 * Indexes.
	 *
	 * BerlinDB 3.0 builds CREATE TABLE from these, and does not derive
	 * PRIMARY from a column's flag -- it has to be declared here or the
	 * table is created without a primary key.
	 *
	 * @var array
	 * @since 1.0.0
	 */
	public $indexes = array(

		array(
			'name'    => 'primary',
			'columns' => array( 'id' ),
			'type'    => 'primary',
		),
		array(
			'name'    => 'status',
			'columns' => array( 'status' ),
		),
		array(
			'name'    => 'date_created',
			'columns' => array( 'date_created' ),
		),

	);
}
