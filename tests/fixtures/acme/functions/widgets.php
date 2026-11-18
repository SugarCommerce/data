<?php
/**
 * The public API for widgets.
 *
 * @package     Acme
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

use Acme\Database\Queries\Widget;
use Acme\Database\Rows\Widget as WidgetRow;

defined( 'ABSPATH' ) || exit;

/**
 * Get a widget by id.
 *
 * @param int $id The widget id.
 *
 * @return WidgetRow|false The widget, or false when there is not one.
 * @since 1.0.0
 */
function acme_get_widget( int $id = 0 ) {
	$item = $id > 0
		? ( new Widget() )->get_item( $id )
		: false;

	/**
	 * Filter a widget as it is read.
	 *
	 * @param WidgetRow|false $item The widget.
	 * @param int             $id   The id asked for.
	 *
	 * @since 1.0.0
	 */
	$item = apply_filters( 'acme_get_widget', $item, $id );

	return $item;
}

/**
 * Get a widget by any unique column.
 *
 * @param string $field Column name.
 * @param mixed  $value Value to match.
 *
 * @return WidgetRow|false
 * @since 1.0.0
 */
function acme_get_widget_by( string $field = '', $value = '' ) {
	$item = ( '' !== $field && '' !== $value && null !== $value )
		? ( new Widget() )->get_item_by( $field, $value )
		: false;

	/**
	 * Filter a widget looked up by column.
	 *
	 * @param WidgetRow|false $item  The widget.
	 * @param string          $field Column name.
	 * @param mixed           $value Value matched.
	 *
	 * @since 1.0.0
	 */
	$item = apply_filters( 'acme_get_widget_by', $item, $field, $value );

	return $item;
}

/**
 * Get one field from a widget, without hydrating the whole row.
 *
 * @param int    $id    The widget id.
 * @param string $field Column name.
 *
 * @return mixed|false
 * @since 1.0.0
 */
function acme_get_widget_field( int $id = 0, string $field = '' ) {
	$item = acme_get_widget( $id );

	return ( $item && isset( $item->{$field} ) )
		? $item->{$field}
		: false;
}

/**
 * Query widgets.
 *
 * @param array $args Query arguments, as understood by BerlinDB.
 *
 * @return array<int, WidgetRow>
 * @since 1.0.0
 */
function acme_get_widgets( array $args = array() ): array {
	/**
	 * Filter the arguments before widgets are queried.
	 *
	 * @param array $args Query arguments.
	 *
	 * @since 1.0.0
	 */
	$args = apply_filters( 'acme_get_widgets_args', $args );

	// 'number' is left alone when already set: a caller asking for all of
	// them has to say so, but a caller that said nothing gets a bounded
	// query rather than the whole table.
	$defaults = array( 'number' => 100 );
	$args     = wp_parse_args( $args, $defaults );

	$items = ( new Widget() )->query( $args );

	/**
	 * Filter the widgets returned.
	 *
	 * @param array $items The widgets.
	 * @param array $args  Query arguments.
	 *
	 * @since 1.0.0
	 */
	$items = (array) apply_filters( 'acme_get_widgets', $items, $args );

	return $items;
}

/**
 * Count widgets.
 *
 * @param array $args Query arguments.
 *
 * @return int
 * @since 1.0.0
 */
function acme_count_widgets( array $args = array() ): int {
	$args  = array_merge(
		$args,
		array(
			'count'  => true,
			'number' => 0,
		)
	);
	$count = (int) ( new Widget() )->query( $args );

	/**
	 * Filter the widget count.
	 *
	 * @param int   $count The count.
	 * @param array $args  Query arguments.
	 *
	 * @since 1.0.0
	 */
	$count = (int) apply_filters( 'acme_count_widgets', $count, $args );

	return $count;
}

/**
 * Add a widget.
 *
 * @param array $data Column values.
 *
 * @return int|false The new id, or false when it could not be written.
 * @since 1.0.0
 */
function acme_add_widget( array $data = array() ) {
	/**
	 * Filter the data before a widget is created.
	 *
	 * @param array $data Column values.
	 *
	 * @since 1.0.0
	 */
	$data = apply_filters( 'acme_add_widget_data', $data );

	if ( empty( $data ) ) {
		return false;
	}

	/**
	 * Fires before a widget is created.
	 *
	 * @param array $data Column values.
	 *
	 * @since 1.0.0
	 */
	do_action( 'acme_before_add_widget', $data );

	$id = ( new Widget() )->add_item( $data );

	if ( empty( $id ) ) {
		return false;
	}

	/**
	 * Fires once a widget has been created.
	 *
	 * @param int   $id   The new id.
	 * @param array $data Column values.
	 *
	 * @since 1.0.0
	 */
	do_action( 'acme_add_widget', $id, $data );

	return (int) $id;
}

/**
 * Update a widget.
 *
 * @param int   $id   The widget id.
 * @param array $data Column values to change.
 *
 * @return bool
 * @since 1.0.0
 */
function acme_update_widget( int $id = 0, array $data = array() ): bool {
	if ( $id <= 0 ) {
		return false;
	}

	/**
	 * Filter the data before a widget is updated.
	 *
	 * @param array $data Column values.
	 * @param int   $id   The widget id.
	 *
	 * @since 1.0.0
	 */
	$data = apply_filters( 'acme_update_widget_data', $data, $id );

	if ( empty( $data ) ) {
		return false;
	}

	/**
	 * Fires before a widget is updated.
	 *
	 * @param int   $id   The widget id.
	 * @param array $data Column values.
	 *
	 * @since 1.0.0
	 */
	do_action( 'acme_before_update_widget', $id, $data );

	$updated = (bool) ( new Widget() )->update_item( $id, $data );

	if ( $updated ) {
		/**
		 * Fires once a widget has been updated.
		 *
		 * @param int   $id   The widget id.
		 * @param array $data Column values.
		 *
		 * @since 1.0.0
		 */
		do_action( 'acme_update_widget', $id, $data );
	}

	return $updated;
}

/**
 * Delete a widget.
 *
 * @param int $id The widget id.
 *
 * @return bool
 * @since 1.0.0
 */
function acme_delete_widget( int $id = 0 ): bool {
	if ( $id <= 0 ) {
		return false;
	}

	/**
	 * Fires before a widget is deleted, while it can still be read.
	 *
	 * @param int $id The widget id.
	 *
	 * @since 1.0.0
	 */
	do_action( 'acme_before_delete_widget', $id );

	$deleted = (bool) ( new Widget() )->delete_item( $id );

	if ( $deleted ) {
		/**
		 * Fires once a widget has been deleted.
		 *
		 * @param int $id The id it had.
		 *
		 * @since 1.0.0
		 */
		do_action( 'acme_delete_widget', $id );
	}

	return $deleted;
}

/**
 * Add a meta value to a widget.
 *
 * @param int    $id     The widget id.
 * @param string $key    Meta key.
 * @param mixed  $value  Meta value.
 * @param bool   $unique Whether to fail if the key already exists.
 *
 * @return int|false
 * @since 1.0.0
 */
function acme_add_widget_meta( int $id, string $key, $value, bool $unique = false ) {
	return add_metadata( 'acme_widget', $id, $key, $value, $unique );
}

/**
 * Read meta from a widget.
 *
 * @param int    $id     The widget id.
 * @param string $key    Meta key. Empty returns everything.
 * @param bool   $single Whether to return a single value.
 *
 * @return mixed
 * @since 1.0.0
 */
function acme_get_widget_meta( int $id, string $key = '', bool $single = false ) {
	return get_metadata( 'acme_widget', $id, $key, $single );
}

/**
 * Update meta on a widget.
 *
 * @param int    $id    The widget id.
 * @param string $key   Meta key.
 * @param mixed  $value Meta value.
 * @param mixed  $prev  Only update rows matching this previous value.
 *
 * @return int|bool
 * @since 1.0.0
 */
function acme_update_widget_meta( int $id, string $key, $value, $prev = '' ) {
	return update_metadata( 'acme_widget', $id, $key, $value, $prev );
}

/**
 * Delete meta from a widget.
 *
 * @param int    $id    The widget id.
 * @param string $key   Meta key.
 * @param mixed  $value Only delete rows matching this value.
 *
 * @return bool
 * @since 1.0.0
 */
function acme_delete_widget_meta( int $id, string $key, $value = '' ): bool {
	return delete_metadata( 'acme_widget', $id, $key, $value );
}
