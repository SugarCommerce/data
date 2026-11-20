# Data

Give a WordPress plugin, and its add-ons, custom tables as registered
components on BerlinDB -- installed on activation, repaired when one goes
missing, and with the writes BerlinDB leaves out.

## What it does

BerlinDB gives a plugin cached, queryable custom tables. What every plugin
then writes for itself is the rest: a registry so add-ons can add tables
through the same door, an install that does not wait for wp-admin, a check
for a table that has vanished while its version option says it is fine,
defaults that are worked out rather than literal, a sort order that pages
cleanly, counts and "first one wins" rows that two requests at once cannot
get wrong, and a clean-up of old rows that does not leave their meta behind.

This is that, once. Pair it with
[sugarcommerce/schema](https://github.com/sugarcommerce/schema), which
generates the table classes from one JSON file and makes them extend this
library's Query.

## Features

* Register an object type and its tables, meta table included, in one call,
  from the plugin or any add-on to it
* Install every table on activation, and recreate one that has gone missing
* Fill a new row's column from a function, such as the store's currency
* Page through rows that tie on the sort column without skipping or repeating
* Move a count up or down in one statement, never below nought
* Let exactly one of several simultaneous requests claim a row -- or take
  over one that failed
* Count attempts into a named row atomically, for rate limits
* Clear out rows past their date a batch at a time, meta and cache with them,
  or count them first with a dry run
* Build each table's Schema once a request, not once per query, and decide
  who may read which column once per user, not per column of every row

## Installation

```bash
composer require sugarcommerce/data
```

## Quick start

The plugin's bootstrap, with the classes sugarcommerce/schema generated:

```php
use SugarCommerce\Data\Components;
use MyShop\Database\Components\Core;

function myshop_components(): Components {
	return Components::instance( 'myshop', [ 'probe' => 'orders' ] );
}

// What the generated Core class calls for each table, and what add-ons call.
function myshop_register_component( string $name, array $args ) {
	return myshop_components()->register( $name, $args );
}

add_action( 'plugins_loaded', function () {
	Core::init();                           // hooks myshop_register_components
	myshop_components()->boot();            // fires it
	myshop_components()->installer->init(); // the hourly missing-table check
} );

register_activation_hook( __FILE__, function () {
	myshop_components()->installer->activate( [ Core::class, 'init' ] );
} );
```

Then anywhere: `myshop_get_order( 12 )`, `myshop_add_order( [ ... ] )` -- the
CRUD functions sugarcommerce/schema generated.

## Registering a component

```php
add_action( 'myshop_register_components', function () {
	myshop_register_component( 'ticket', [
		'schema' => Schemas\Tickets::class,
		'table'  => Tables\Tickets::class,
		'query'  => Queries\Ticket::class,
		'row'    => Rows\Ticket::class,
		'meta'   => Tables\TicketMeta::class, // optional
	] );
} );
```

The table names are put on `$wpdb` at once (`$wpdb->myshop_tickets`, and
the meta table the metadata functions read). The table objects -- each
builds its whole schema to have its CREATE TABLE ready -- are built at once
under cron and on the command line, and everywhere else the first time
something asks for them: a visitor's page or a REST request installs
nothing, and is spared every schema. In the admin a table is built on
`admin_init` (priority 1) only when the version stored for it is behind its
class's (`Component::outdated()` reads the option BerlinDB keeps, from the
class's defaults), in time for BerlinDB's own upgrade check at 10; an admin
page whose tables are current builds none. `{prefix}_build_tables_now`
(filter) decides whether they are all built at once. The query and row are class names, built when used.
`$components->get( 'ticket' )` returns the `Component`: `->table` (or
`->table()`), `->meta`, `->has_meta()`, `->tables()`,
`->class_for( 'query' )`, `->query()`. An
add-on that loads after the plugin has missed the action; it checks
`myshop_components()->is_ready()` and registers outright.

## Hooks

Every hook is the prefix plus a name, so two plugins bundling this library
never hear each other.

| Hook | When |
|---|---|
| `{prefix}_register_components` | Register here |
| `{prefix}_register_component_args` (filter) | Swap a class before it is built -- how an add-on decorates a row |
| `{prefix}_build_tables_now` (filter) | Whether a component's tables are built as it registers |
| `{prefix}_pre_register_component`, `{prefix}_register_component` | Around each registration |
| `{prefix}_components_registered` | Once all are in |
| `{prefix}_get_component`, `{prefix}_get_components` (filters) | On lookup |
| `{prefix}_activated` | After the tables exist on activation |
| `{prefix}_schema_repaired` | A missing table was recreated |
| `{prefix}_count_adjusted` | `adjust()` moved a count |

`hook_prefix` and `function_prefix` can differ from the prefix:
`new Components( 'myshop', [ 'hook_prefix' => 'myshop_db' ] )`.

## Installing and repair

`installer->activate( $register )` registers if nothing has yet (activation
runs before `plugins_loaded`), installs whatever is missing, and fires
`{prefix}_activated`. BerlinDB only compares version numbers, so a table
dropped while its version option stayed reads as current for ever.
`installer->init()` hooks `maybe_repair()` to `admin_init` after BerlinDB's
own upgrade: at most once an hour it looks for each table, forgets the
version of any that is gone, and recreates it. `installer->tables_installed()`
answers "can I query yet?" with one `SHOW TABLES`, and remembers only a yes.

## The query base

Generated query classes extend `SugarCommerce\Data\Query`, BerlinDB's Query
with:

```php
// A new row that leaves `currency` empty gets myshop_get_currency() -- from
// "default_function" in the schema. Updates never touch it.
protected $insert_defaults = [ 'currency' => 'myshop_get_currency' ];

$query->adjust( $id, 'use_count', 1 );     // UPDATE ... GREATEST( col + 1, 0 )
$query->claim( [ 'event_ref' => $ref ] );  // the id, or null when another request got there first
$query->retake( $id, [ 'failed' => 0, 'pending' => 300 ], [ 'status' => 'pending' ] ); // one retry only
$query->tally( [ 'bucket' => $hash ], 'hits', [ 'date_expires' => $expires ] );       // the count, this one included
$query->sweep( 'date_created', $cutoff, except: [ 'type' => $kept ], limit: 5000 );   // rows removed
```

`query()` puts the id after any single sort column, in the same direction,
so page two always starts where page one stopped. `claim()` keeps the
expected duplicate-key error out of the log; pass the plugin's add function
as its second argument to have its hooks fire. `sweep()` reads the ids first
and deletes by id, so a meta table loses exactly those rows' meta; `where`
and `except` take a value or a list, `dry: true` only counts, and no delete
hooks fire. Both it and `adjust()` drop the cached rows and lists through
core's `wp_cache_delete_multiple()` and `wp_cache_set_last_changed()`, so the
`wp_cache_set_last_changed` action fires as it does for core's tables. `myshop_components()->adjust( 'discount', $id, 'use_count', 1 )`
also fires `{prefix}_count_adjusted`, and `->ask( 'order', $id, 'get_total' )`
reads a row through the plugin's getter and asks it one of its methods.

Rows changed with SQL of your own -- an `UPDATE ... JOIN`, a set at once --
are never seen by BerlinDB, so their cached copies read as they were until
you forget them:

```php
$wpdb->query( "UPDATE {$orders} o JOIN ... SET o.customer_id = ..." );
$query->forget_items( $ids ); // cached copies dropped, lists and lookups by other cached columns started afresh
```

It drops the rows from the group BerlinDB really caches them in (the query's
cache group with its prefix), bumps that group's `last_changed` once, and
does nothing while `wp_suspend_cache_invalidation()` is on.

## Built once

BerlinDB builds a query's Schema -- every Column in it, booted and
validated -- for each query object, and a plugin makes a query object for
every row it gets by id: one webhook that touched a few thousand orders
built 55,000 Columns. Every row it read was then checked column by column:
the column found by filtering the whole Schema, and `current_user_can()`
asked for it, for every column of every row.

`SugarCommerce\Data\Query` builds each Schema class once a request and
hands it to every later query of that class, remembers what the Schema's
columns have answered, and works out once per user which columns they may
read -- so a cached row is cut down to those in one step. There is nothing
to configure. On a 40-column table:

| | BerlinDB | This |
|---|---|---|
| 2,000 `new Query()` | 3.1 s, 80,000 Columns built | 0.7 s, 40 |
| `query()` of 5,000 cached rows | 1.5 s, 200,000 capability checks | 0.06 s, none |
| 5,000 × `new Query()` then `get_item()` | 11 s | 2.3 s |

What to know:

* A Schema is the table's declaration. Change it in the Schema class, not
  at run time through `$query->get_schema()->add_column()`: that would now
  reach every query of the class, and lookups already answered would not
  see it. A Schema passed in as an object
  (`new Query( [ 'table_schema' => $schema ] )`) is that query's own, as
  before.
* Which columns a user may read is worked out once per user per request.
  `wp_set_current_user()` switches the answer, and switching back switches
  it back; a capability given to the same user later in the request is not
  seen until the next one. Writes are checked by BerlinDB, as before.
* Only a row asked for by id and already cached is read this way. Anything
  else goes to BerlinDB's own `get_item_by()`, which reads it and caches it
  for next time.

## Bundling with Strauss

The plugin bundles this library and BerlinDB with a prefix, and gives its
add-ons one class to build on:

```php
namespace MyShop\Database;

class Query extends \MyShop\Vendor\SugarCommerce\Data\Query {}
```

Its own generated classes and every add-on's extend `MyShop\Database\Query`
(sugarcommerce/schema's `bases.query`), and add-ons register on the plugin's
registry. An add-on must not bundle a second copy: two registries mean two
installs and two repair checks. A separate plugin bundles its own.

Leave BerlinDB's `autoloader.php` out of the copy. It keeps BerlinDB 2.x's
class names as strings Strauss does not rewrite and `class_alias()`es the
prefixed classes to global `BerlinDB\Database\*` names -- a fatal next to any
other plugin bundling BerlinDB. Nothing here needs it:

```json
"strauss": {
  "exclude_from_copy": { "file_patterns": [ "^berlindb/core/autoloader\\.php$" ] }
}
```

## Security

* SQL injection: every statement goes through `$wpdb->prepare()`, column and
  table names as `%i`, and a column a caller names must be one of the
  table's own before it is used.
* Double spending under load: counts, claims, retakes and tallies are one
  statement each, so two requests at once cannot both see "nothing yet".
* A clean-up that runs away: `sweep()` deletes a bounded batch by id, so it
  cannot hold a lock on a large table or take rows a condition did not name.

## Layout

* `src/Components.php`, `Component.php`, `Installer.php`: the registry, one
  table's classes, and installing and repairing them.
* `src/Query.php`: the base query class, with insert defaults and the stable
  order; `src/Traits/Query/Writes.php` (adjust, claim, retake, tally) and
  `Sweep.php` are its race-free writes and its clean-up, and `Shared.php`
  the one Schema a class, its remembered lookups and who may read what.

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later
* BerlinDB 3.1 (`berlindb/core` dev-master)

## License

GPL-2.0-or-later
