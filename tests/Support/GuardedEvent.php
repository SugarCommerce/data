<?php
/**
 * The events query, on the guarded Schema.
 *
 * @package SugarCommerce\Data
 */

declare( strict_types=1 );

namespace SugarCommerce\Data\Tests\Support;

use Acme\Database\Queries\Event;

/**
 * The events query, guarded, with a cache of its own.
 */
class GuardedEvent extends Event {

	/**
	 * The guarded Schema.
	 *
	 * @var string
	 */
	protected $table_schema = GuardedEvents::class;

	/**
	 * Its own cache group.
	 *
	 * @var string
	 */
	protected $cache_group = 'acme_guarded_events';
}
