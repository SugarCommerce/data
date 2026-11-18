<?php
/**
 * Creating a plugin's tables, and noticing when one has gone.
 *
 * @package     SugarCommerce\Data
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Installation and self-repair.
 *
 * BerlinDB hooks each table's own upgrade routine to admin_init, which is
 * enough for a version bump but not for a first install: a front end
 * request, a REST call or an incoming webhook can all arrive before anybody
 * has opened wp-admin, and every one of them would find the tables missing.
 * So {@see self::activate()} installs on activation as well, when the plugin
 * is definitely being set up and the user is definitely waiting.
 *
 * And BerlinDB decides whether to act by comparing version numbers, never by
 * looking for the table. {@see self::maybe_repair()} looks.
 *
 * @since 1.0.0
 */
final class Installer {

	/**
	 * Whether the probe table has been seen. Only a yes is remembered.
	 *
	 * @since 1.0.0
	 */
	private bool $installed = false;

	/**
	 * Constructor.
	 *
	 * @param Components  $components The plugin's components.
	 * @param string|null $probe      The table, unprefixed, whose presence
	 *                                says the plugin is installed. Default
	 *                                the first registered component's.
	 *
	 * @since 1.0.0
	 */
	public function __construct(
		private readonly Components $components,
		private readonly ?string $probe = null
	) {
	}

	/**
	 * Register the self-healing check.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function init(): void {
		// After BerlinDB's own maybe_upgrade(), which runs at the default
		// priority, so anything it was going to fix is already fixed.
		add_action( 'admin_init', [ $this, 'maybe_repair' ], 20 );
	}

	/**
	 * The transient that rate-limits the repair check.
	 *
	 * @return string `{prefix}_schema_checked`.
	 * @since 1.0.0
	 */
	public function check_transient(): string {
		return $this->components->hook( 'schema_checked' );
	}

	/**
	 * Recreate any table that has gone missing.
	 *
	 * BerlinDB decides whether to act by comparing version numbers, never by
	 * looking for the table. So a table dropped without its version option
	 * going too -- a partial restore, a half-finished migration, someone
	 * tidying by hand -- reads as current forever, and every query against
	 * it fails with "table doesn't exist" until a person works out why.
	 *
	 * Rate limited to once an hour, because exists() is a SHOW TABLES per
	 * table.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function maybe_repair(): void {
		if ( get_transient( $this->check_transient() ) ) {
			return;
		}

		set_transient( $this->check_transient(), 1, HOUR_IN_SECONDS );

		$missing = $this->missing();

		if ( [] === $missing ) {
			return;
		}

		// A missing table still has its version option, and BerlinDB decides
		// what to do from that alone -- so it would go on reporting the table
		// current. uninstall() drops what is not there (a no-op) and deletes
		// the option; its key is protected, so this is the only way to reach
		// it. install() below writes the version again once CREATE TABLE
		// succeeds, so this is what matters when it does not: no version is
		// left claiming a table that is not there.
		global $wpdb;

		// uninstall() drops before it forgets, and BerlinDB's drop has no
		// IF EXISTS -- so clearing the record of a table that is already
		// gone logs "Unknown table" every time. The failure is the expected
		// outcome here, so it is not worth putting in anybody's debug log.
		$suppressed = $wpdb->suppress_errors( true );

		foreach ( $this->tables() as $table ) {
			if ( ! $table->exists() ) {
				$table->uninstall();
			}
		}

		$wpdb->suppress_errors( $suppressed );

		$created = $this->install();

		/**
		 * Fires when a missing table had to be recreated.
		 *
		 * @param array<int, string> $created The tables created.
		 * @param array<int, string> $missing The tables found missing.
		 *
		 * @since 1.0.0
		 */
		do_action( $this->components->hook( 'schema_repaired' ), $created, $missing );
	}

	/**
	 * Create every registered table, from the activation hook.
	 *
	 * Activation happens on its own request, before plugins_loaded has run
	 * for the plugin, so nothing may be registered yet: $register is called
	 * first to hook the plugin's own components, and registration is opened.
	 * Safe to run again: each table is asked whether it exists first, and
	 * BerlinDB's own version check handles the upgrade case.
	 *
	 * @param callable|null $register Hooks the plugin's components, when
	 *                                registration has not run yet.
	 *
	 * @return array<int, string> The tables created.
	 * @since 1.0.0
	 */
	public function activate( ?callable $register = null ): array {
		if ( ! $this->components->is_ready() ) {
			if ( null !== $register ) {
				$register();
			}

			$this->components->boot();
		}

		$created = $this->install();

		/**
		 * Fires once the plugin's tables exist, on activation.
		 *
		 * The hook for an add-on that needs to seed data on activation.
		 *
		 * @since 1.0.0
		 */
		do_action( $this->components->hook( 'activated' ) );

		return $created;
	}

	/**
	 * Install anything missing.
	 *
	 * @return array<int, string> The tables created, for logging or tests.
	 * @since 1.0.0
	 */
	public function install(): array {
		$created = [];

		foreach ( $this->tables() as $table ) {
			if ( $table->exists() ) {
				continue;
			}

			// install() writes the version option, which is what BerlinDB's
			// later admin_init check reads. Calling create() directly would
			// make the table and leave it looking permanently out of date.
			$table->install();

			if ( $table->exists() ) {
				$created[] = $table->table_name;
			}
		}

		return $created;
	}

	/**
	 * The registered tables that are not there.
	 *
	 * A table can go missing without the version option going with it.
	 * BerlinDB only compares versions, so it would report everything current
	 * while the table it describes is gone.
	 *
	 * @return array<int, string> Their full names.
	 * @since 1.0.0
	 */
	public function missing(): array {
		$missing = [];

		foreach ( $this->tables() as $table ) {
			if ( ! $table->exists() ) {
				$missing[] = $table->table_name;
			}
		}

		return $missing;
	}

	/**
	 * Whether the plugin's tables are there to be queried.
	 *
	 * One SHOW TABLES, against the probe table. The alternative is that
	 * anything running before install -- a panel registering itself on the
	 * very first admin page load -- queries a table that does not exist and
	 * prints a database error above the screen.
	 *
	 * Only a yes is remembered. Tables come into existence during a request:
	 * on activation, on an upgrade, and in a test suite that builds them
	 * after the plugin has loaded. A no cached before that point makes every
	 * guarded function answer as though there were no data, for the rest of
	 * the request -- silently, because each of those guards returns an empty
	 * result rather than an error. It cannot fail twice on the same database,
	 * which is what makes it so easy to miss. A yes cannot go stale the same
	 * way: nothing drops these tables mid-request except uninstall, which
	 * ends it.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function tables_installed(): bool {
		if ( $this->installed ) {
			return true;
		}

		global $wpdb;

		$table = $this->probe_table();

		if ( '' === $table ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Whether a table exists, asked of the database itself.
		$this->installed = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		return $this->installed;
	}

	/**
	 * Forget a remembered yes. Tests only.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function reset(): void {
		$this->installed = false;
	}

	/**
	 * The full name of the table tables_installed() looks for.
	 *
	 * @return string Empty when there is nothing to look for.
	 * @since 1.0.0
	 */
	private function probe_table(): string {
		global $wpdb;

		if ( null !== $this->probe ) {
			return $wpdb->prefix . $this->components->prefix . '_' . $this->probe;
		}

		foreach ( $this->components->all() as $component ) {
			if ( $component->table ) {
				return (string) $component->table->table_name;
			}
		}

		return '';
	}

	/**
	 * Every registered table object, primary and meta.
	 *
	 * @return array<int, object>
	 * @since 1.0.0
	 */
	private function tables(): array {
		$tables = [];

		foreach ( $this->components->all() as $component ) {
			foreach ( $component->tables() as $table ) {
				$tables[] = $table;
			}
		}

		return $tables;
	}
}
