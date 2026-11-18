<?php
/**
 * The public API for counters.
 *
 * @package     Acme
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

use Acme\Database\Queries\Counter;
use Acme\Database\Rows\Counter as CounterRow;

defined( 'ABSPATH' ) || exit;

/**
 * Get a counter by id.
 *
 * @param int $id The counter id.
 *
 * @return CounterRow|false The counter, or false when there is not one.
 * @since 1.0.0
 */
function acme_get_counter( int $id = 0 ) {
	$item = $id > 0
		? ( new Counter() )->get_item( $id )
		: false;

	/**
	 * Filter a counter as it is read.
	 *
	 * @param CounterRow|false $item The counter.
	 * @param int             $id   The id asked for.
	 *
	 * @since 1.0.0
	 */
	$item = apply_filters( 'acme_get_counter', $item, $id );

	return $item;
}

/**
 * Get a counter by any unique column.
 *
 * @param string $field Column name.
 * @param mixed  $value Value to match.
 *
 * @return CounterRow|false
 * @since 1.0.0
 */
function acme_get_counter_by( string $field = '', $value = '' ) {
	$item = ( '' !== $field && '' !== $value && null !== $value )
		? ( new Counter() )->get_item_by( $field, $value )
		: false;

	/**
	 * Filter a counter looked up by column.
	 *
	 * @param CounterRow|false $item  The counter.
	 * @param string          $field Column name.
	 * @param mixed           $value Value matched.
	 *
	 * @since 1.0.0
	 */
	$item = apply_filters( 'acme_get_counter_by', $item, $field, $value );

	return $item;
}

/**
 * Get one field from a counter, without hydrating the whole row.
 *
 * @param int    $id    The counter id.
 * @param string $field Column name.
 *
 * @return mixed|false
 * @since 1.0.0
 */
function acme_get_counter_field( int $id = 0, string $field = '' ) {
	$item = acme_get_counter( $id );

	return ( $item && isset( $item->{$field} ) )
		? $item->{$field}
		: false;
}

/**
 * Query counters.
 *
 * @param array $args Query arguments, as understood by BerlinDB.
 *
 * @return array<int, CounterRow>
 * @since 1.0.0
 */
function acme_get_counters( array $args = array() ): array {
	/**
	 * Filter the arguments before counters are queried.
	 *
	 * @param array $args Query arguments.
	 *
	 * @since 1.0.0
	 */
	$args = apply_filters( 'acme_get_counters_args', $args );

	// 'number' is left alone when already set: a caller asking for all of
	// them has to say so, but a caller that said nothing gets a bounded
	// query rather than the whole table.
	$defaults = array( 'number' => 100 );
	$args     = wp_parse_args( $args, $defaults );

	$items = ( new Counter() )->query( $args );

	/**
	 * Filter the counters returned.
	 *
	 * @param array $items The counters.
	 * @param array $args  Query arguments.
	 *
	 * @since 1.0.0
	 */
	$items = (array) apply_filters( 'acme_get_counters', $items, $args );

	return $items;
}

/**
 * Count counters.
 *
 * @param array $args Query arguments.
 *
 * @return int
 * @since 1.0.0
 */
function acme_count_counters( array $args = array() ): int {
	$args  = array_merge(
		$args,
		array(
			'count'  => true,
			'number' => 0,
		)
	);
	$count = (int) ( new Counter() )->query( $args );

	/**
	 * Filter the counter count.
	 *
	 * @param int   $count The count.
	 * @param array $args  Query arguments.
	 *
	 * @since 1.0.0
	 */
	$count = (int) apply_filters( 'acme_count_counters', $count, $args );

	return $count;
}

/**
 * Add a counter.
 *
 * @param array $data Column values.
 *
 * @return int|false The new id, or false when it could not be written.
 * @since 1.0.0
 */
function acme_add_counter( array $data = array() ) {
	/**
	 * Filter the data before a counter is created.
	 *
	 * @param array $data Column values.
	 *
	 * @since 1.0.0
	 */
	$data = apply_filters( 'acme_add_counter_data', $data );

	if ( empty( $data ) ) {
		return false;
	}

	/**
	 * Fires before a counter is created.
	 *
	 * @param array $data Column values.
	 *
	 * @since 1.0.0
	 */
	do_action( 'acme_before_add_counter', $data );

	$id = ( new Counter() )->add_item( $data );

	if ( empty( $id ) ) {
		return false;
	}

	/**
	 * Fires once a counter has been created.
	 *
	 * @param int   $id   The new id.
	 * @param array $data Column values.
	 *
	 * @since 1.0.0
	 */
	do_action( 'acme_add_counter', $id, $data );

	return (int) $id;
}

/**
 * Update a counter.
 *
 * @param int   $id   The counter id.
 * @param array $data Column values to change.
 *
 * @return bool
 * @since 1.0.0
 */
function acme_update_counter( int $id = 0, array $data = array() ): bool {
	if ( $id <= 0 ) {
		return false;
	}

	/**
	 * Filter the data before a counter is updated.
	 *
	 * @param array $data Column values.
	 * @param int   $id   The counter id.
	 *
	 * @since 1.0.0
	 */
	$data = apply_filters( 'acme_update_counter_data', $data, $id );

	if ( empty( $data ) ) {
		return false;
	}

	/**
	 * Fires before a counter is updated.
	 *
	 * @param int   $id   The counter id.
	 * @param array $data Column values.
	 *
	 * @since 1.0.0
	 */
	do_action( 'acme_before_update_counter', $id, $data );

	$updated = (bool) ( new Counter() )->update_item( $id, $data );

	if ( $updated ) {
		/**
		 * Fires once a counter has been updated.
		 *
		 * @param int   $id   The counter id.
		 * @param array $data Column values.
		 *
		 * @since 1.0.0
		 */
		do_action( 'acme_update_counter', $id, $data );
	}

	return $updated;
}

/**
 * Delete a counter.
 *
 * @param int $id The counter id.
 *
 * @return bool
 * @since 1.0.0
 */
function acme_delete_counter( int $id = 0 ): bool {
	if ( $id <= 0 ) {
		return false;
	}

	/**
	 * Fires before a counter is deleted, while it can still be read.
	 *
	 * @param int $id The counter id.
	 *
	 * @since 1.0.0
	 */
	do_action( 'acme_before_delete_counter', $id );

	$deleted = (bool) ( new Counter() )->delete_item( $id );

	if ( $deleted ) {
		/**
		 * Fires once a counter has been deleted.
		 *
		 * @param int $id The id it had.
		 *
		 * @since 1.0.0
		 */
		do_action( 'acme_delete_counter', $id );
	}

	return $deleted;
}
