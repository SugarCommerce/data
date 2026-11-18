<?php
/**
 * Something that must be handled once: unique on its reference.
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
 * Something that must be handled once: unique on its reference.
 *
 * Generated from bin/schema.json -- edit that and re-run
 * vendor/bin/schema generate rather than changing this by hand.
 *
 * @since 1.0.0
 */
class Events extends Schema {

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
			'name'    => 'event_ref',
			'type'    => 'varchar',
			'length'  => '100',
			'default' => '',
		),
		array(
			'name'       => 'status',
			'type'       => 'varchar',
			'length'     => '20',
			'transition' => true,
			'default'    => 'pending',
		),
		array(
			'name'     => 'attempts',
			'type'     => 'smallint',
			'length'   => '6',
			'unsigned' => true,
			'default'  => '0',
		),
		array(
			'name' => 'error',
			'type' => 'text',
		),
		array(
			'name' => 'payload',
			'type' => 'longtext',
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
			'name'    => 'event_ref',
			'columns' => array( 'event_ref' ),
			'unique'  => true,
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
