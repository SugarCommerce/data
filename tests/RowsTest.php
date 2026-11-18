<?php
/**
 * Asking a row, and moving a count, by component name.
 *
 * @package SugarCommerce\Data
 */

declare( strict_types=1 );

namespace SugarCommerce\Data\Tests;

use Acme\Database\Rows\Widget;
use SugarCommerce\Data\Components;

/**
 * Components::ask() and Components::adjust().
 */
final class RowsTest extends TestCase {

	/**
	 * A row is asked through the plugin's getter, so its filter applies.
	 *
	 * @return void
	 */
	public function test_ask_goes_through_the_getter(): void {
		$id = (int) acme_add_widget( [ 'name' => 'Asked' ] );

		add_filter(
			'acme_get_widget',
			static function ( $widget ) {
				if ( $widget ) {
					$widget->name = 'Filtered';
				}

				return $widget;
			}
		);

		$this->assertSame( 'Filtered!', acme_components()->ask( 'widget', $id, 'label', [ '!' ] ) );
	}

	/**
	 * No row, no method or no id: the answer for "none".
	 *
	 * @return void
	 */
	public function test_ask_answers_none(): void {
		$id = (int) acme_add_widget( [ 'name' => 'Asked' ] );

		$this->assertSame( 'none', acme_components()->ask( 'widget', 0, 'label', [], 'none' ) );
		$this->assertSame( 'none', acme_components()->ask( 'widget', $id + 1000, 'label', [], 'none' ) );
		$this->assertSame( 'none', acme_components()->ask( 'widget', $id, 'no_such_method', [], 'none' ) );
		$this->assertSame( 'none', acme_components()->ask( 'nothing', $id, 'label', [], 'none' ) );
	}

	/**
	 * Without a getter function, the component's query reads the row.
	 *
	 * @return void
	 */
	public function test_ask_without_a_getter(): void {
		$id       = (int) acme_add_widget( [ 'name' => 'Direct' ] );
		$registry = new Components( 'acme', [ 'function_prefix' => 'no_such_plugin' ] );

		$registry->register( 'widget', [ 'query' => acme_get_component( 'widget' )->class_for( 'query' ) ] );

		$this->assertSame( 'Direct?', $registry->ask( 'widget', $id, 'label', [ '?' ] ) );
		$this->assertInstanceOf( Widget::class, acme_get_widget( $id ) );
	}

	/**
	 * A count moved by name, announced with the prefix.
	 *
	 * @return void
	 */
	public function test_adjust_by_name(): void {
		$id    = (int) acme_add_widget( [ 'name' => 'Counted' ] );
		$heard = [];

		add_action(
			'acme_count_adjusted',
			static function ( ...$args ) use ( &$heard ): void {
				$heard[] = $args;
			},
			10,
			4
		);

		$this->assertTrue( acme_components()->adjust( 'widget', $id, 'use_count', 2 ) );
		$this->assertSame( 2, (int) acme_get_widget( $id )->use_count );
		$this->assertSame( [ [ 'widget', $id, 'use_count', 2 ] ], $heard );

		$this->assertFalse( acme_components()->adjust( 'widget', $id, 'nope', 1 ) );
		$this->assertFalse( acme_components()->adjust( 'nothing', $id, 'use_count', 1 ) );
		$this->assertCount( 1, $heard, 'Only a count that moved is announced.' );
	}

	/**
	 * A component on BerlinDB's own Query has no adjust().
	 *
	 * @return void
	 */
	public function test_adjust_needs_this_librarys_query(): void {
		$registry = new Components( 'plain' );
		$plain    = get_class(
			new class() extends \BerlinDB\Database\Kern\Query {
			}
		);

		$registry->register( 'thing', [ 'query' => $plain ] );

		$this->assertFalse( $registry->adjust( 'thing', 1, 'use_count', 1 ) );
	}
}
