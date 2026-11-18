<?php
/**
 * A single widget.
 *
 * @package     Acme\Database\Rows
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

namespace Acme\Database\Rows;

use BerlinDB\Database\Kern\Row;

defined( 'ABSPATH' ) || exit;

/**
 * One widget.
 *
 * Properties are declared rather than left dynamic: PHP 8.2 deprecates
 * dynamic properties, and an undeclared column would be a notice on every
 * read rather than anything obvious.
 *
 * @since 1.0.0
 */
class Widget extends Row {

	use Concerns\Widget;

	/**
	 * @var mixed
	 * @since 1.0.0
	 */
	public $id;

	/**
	 * @var mixed
	 * @since 1.0.0
	 */
	public $name;

	/**
	 * @var mixed
	 * @since 1.0.0
	 */
	public $status;

	/**
	 * @var mixed
	 * @since 1.0.0
	 */
	public $price;

	/**
	 * @var mixed
	 * @since 1.0.0
	 */
	public $currency;

	/**
	 * @var mixed
	 * @since 1.0.0
	 */
	public $use_count;

	/**
	 * @var mixed
	 * @since 1.0.0
	 */
	public $date_sold;

	/**
	 * @var mixed
	 * @since 1.0.0
	 */
	public $date_created;

	/**
	 * @var mixed
	 * @since 1.0.0
	 */
	public $date_modified;

	/**
	 * Constructor.
	 *
	 * @param mixed $item Row from the database.
	 *
	 * @since 1.0.0
	 */
	public function __construct( $item ) {
		parent::__construct( $item );

		$this->id = (int) $this->id;
	}
}
