<?php
/**
 * The public API for events.
 *
 * @package     Acme
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

use Acme\Database\Queries\Event;
use Acme\Database\Rows\Event as EventRow;

defined( 'ABSPATH' ) || exit;

/**
 * Get an event by id.
 *
 * @param int $id The event id.
 *
 * @return EventRow|false The event, or false when there is not one.
 * @since 1.0.0
 */
function acme_get_event( int $id = 0 ) {
	$item = $id > 0
		? ( new Event() )->get_item( $id )
		: false;

	/**
	 * Filter an event as it is read.
	 *
	 * @param EventRow|false $item The event.
	 * @param int             $id   The id asked for.
	 *
	 * @since 1.0.0
	 */
	$item = apply_filters( 'acme_get_event', $item, $id );

	return $item;
}

/**
 * Get an event by any unique column.
 *
 * @param string $field Column name.
 * @param mixed  $value Value to match.
 *
 * @return EventRow|false
 * @since 1.0.0
 */
function acme_get_event_by( string $field = '', $value = '' ) {
	$item = ( '' !== $field && '' !== $value && null !== $value )
		? ( new Event() )->get_item_by( $field, $value )
		: false;

	/**
	 * Filter an event looked up by column.
	 *
	 * @param EventRow|false $item  The event.
	 * @param string          $field Column name.
	 * @param mixed           $value Value matched.
	 *
	 * @since 1.0.0
	 */
	$item = apply_filters( 'acme_get_event_by', $item, $field, $value );

	return $item;
}

/**
 * Get one field from an event, without hydrating the whole row.
 *
 * @param int    $id    The event id.
 * @param string $field Column name.
 *
 * @return mixed|false
 * @since 1.0.0
 */
function acme_get_event_field( int $id = 0, string $field = '' ) {
	$item = acme_get_event( $id );

	return ( $item && isset( $item->{$field} ) )
		? $item->{$field}
		: false;
}

/**
 * Query events.
 *
 * @param array $args Query arguments, as understood by BerlinDB.
 *
 * @return array<int, EventRow>
 * @since 1.0.0
 */
function acme_get_events( array $args = array() ): array {
	/**
	 * Filter the arguments before events are queried.
	 *
	 * @param array $args Query arguments.
	 *
	 * @since 1.0.0
	 */
	$args = apply_filters( 'acme_get_events_args', $args );

	// 'number' is left alone when already set: a caller asking for all of
	// them has to say so, but a caller that said nothing gets a bounded
	// query rather than the whole table.
	$defaults = array( 'number' => 100 );
	$args     = wp_parse_args( $args, $defaults );

	$items = ( new Event() )->query( $args );

	/**
	 * Filter the events returned.
	 *
	 * @param array $items The events.
	 * @param array $args  Query arguments.
	 *
	 * @since 1.0.0
	 */
	$items = (array) apply_filters( 'acme_get_events', $items, $args );

	return $items;
}

/**
 * Count events.
 *
 * @param array $args Query arguments.
 *
 * @return int
 * @since 1.0.0
 */
function acme_count_events( array $args = array() ): int {
	$args  = array_merge(
		$args,
		array(
			'count'  => true,
			'number' => 0,
		)
	);
	$count = (int) ( new Event() )->query( $args );

	/**
	 * Filter the event count.
	 *
	 * @param int   $count The count.
	 * @param array $args  Query arguments.
	 *
	 * @since 1.0.0
	 */
	$count = (int) apply_filters( 'acme_count_events', $count, $args );

	return $count;
}

/**
 * Add an event.
 *
 * @param array $data Column values.
 *
 * @return int|false The new id, or false when it could not be written.
 * @since 1.0.0
 */
function acme_add_event( array $data = array() ) {
	/**
	 * Filter the data before an event is created.
	 *
	 * @param array $data Column values.
	 *
	 * @since 1.0.0
	 */
	$data = apply_filters( 'acme_add_event_data', $data );

	if ( empty( $data ) ) {
		return false;
	}

	/**
	 * Fires before an event is created.
	 *
	 * @param array $data Column values.
	 *
	 * @since 1.0.0
	 */
	do_action( 'acme_before_add_event', $data );

	$id = ( new Event() )->add_item( $data );

	if ( empty( $id ) ) {
		return false;
	}

	/**
	 * Fires once an event has been created.
	 *
	 * @param int   $id   The new id.
	 * @param array $data Column values.
	 *
	 * @since 1.0.0
	 */
	do_action( 'acme_add_event', $id, $data );

	return (int) $id;
}

/**
 * Update an event.
 *
 * @param int   $id   The event id.
 * @param array $data Column values to change.
 *
 * @return bool
 * @since 1.0.0
 */
function acme_update_event( int $id = 0, array $data = array() ): bool {
	if ( $id <= 0 ) {
		return false;
	}

	/**
	 * Filter the data before an event is updated.
	 *
	 * @param array $data Column values.
	 * @param int   $id   The event id.
	 *
	 * @since 1.0.0
	 */
	$data = apply_filters( 'acme_update_event_data', $data, $id );

	if ( empty( $data ) ) {
		return false;
	}

	/**
	 * Fires before an event is updated.
	 *
	 * @param int   $id   The event id.
	 * @param array $data Column values.
	 *
	 * @since 1.0.0
	 */
	do_action( 'acme_before_update_event', $id, $data );

	$updated = (bool) ( new Event() )->update_item( $id, $data );

	if ( $updated ) {
		/**
		 * Fires once an event has been updated.
		 *
		 * @param int   $id   The event id.
		 * @param array $data Column values.
		 *
		 * @since 1.0.0
		 */
		do_action( 'acme_update_event', $id, $data );
	}

	return $updated;
}

/**
 * Delete an event.
 *
 * @param int $id The event id.
 *
 * @return bool
 * @since 1.0.0
 */
function acme_delete_event( int $id = 0 ): bool {
	if ( $id <= 0 ) {
		return false;
	}

	/**
	 * Fires before an event is deleted, while it can still be read.
	 *
	 * @param int $id The event id.
	 *
	 * @since 1.0.0
	 */
	do_action( 'acme_before_delete_event', $id );

	$deleted = (bool) ( new Event() )->delete_item( $id );

	if ( $deleted ) {
		/**
		 * Fires once an event has been deleted.
		 *
		 * @param int $id The id it had.
		 *
		 * @since 1.0.0
		 */
		do_action( 'acme_delete_event', $id );
	}

	return $deleted;
}
