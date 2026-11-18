<?php
/**
 * How many times something has been tried lately, one row a name.
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
 * How many times something has been tried lately, one row a name.
 *
 * Generated from bin/schema.json -- edit that and re-run
 * vendor/bin/schema generate rather than changing this by hand.
 *
 * @since 1.0.0
 */
class Counters extends Schema {

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
			'name'    => 'bucket',
			'type'    => 'char',
			'length'  => '64',
			'default' => '',
		),
		array(
			'name'     => 'hits',
			'type'     => 'int',
			'length'   => '11',
			'unsigned' => true,
			'default'  => '0',
		),
		array(
			'name'       => 'date_expires',
			'type'       => 'datetime',
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
			'name'    => 'bucket',
			'columns' => array( 'bucket' ),
			'unique'  => true,
		),
		array(
			'name'    => 'date_expires',
			'columns' => array( 'date_expires' ),
		),
		array(
			'name'    => 'date_created',
			'columns' => array( 'date_created' ),
		),

	);
}
