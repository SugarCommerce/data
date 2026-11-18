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

The table and meta table are built at once (BerlinDB's tables hook their own
upgrades); the query and row are class names, built when used.
`$components->get( 'ticket' )` returns the `Component`: `->table`, `->meta`,
`->has_meta()`, `->tables()`, `->class_for( 'query' )`, `->query()`. An
add-on that loads after the plugin has missed the action; it checks
`myshop_components()->is_ready()` and registers outright.

## Hooks

Every hook is the prefix plus a name, so two plugins bundling this library
never hear each other.

| Hook | When |
|---|---|
| `{prefix}_register_components` | Register here |
| `{prefix}_register_component_args` (filter) | Swap a class before it is built -- how an add-on decorates a row |
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
hooks fire. `myshop_components()->adjust( 'discount', $id, 'use_count', 1 )`
also fires `{prefix}_count_adjusted`, and `->ask( 'order', $id, 'get_total' )`
reads a row through the plugin's getter and asks it one of its methods.

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

## Moving from arraypress/wp-*

From `arraypress/wp-register-components` (`ArrayPress\RegisterComponents`).
Every class, method and hook keeps its name under `SugarCommerce\Data`.

| Old | New |
|---|---|
| `ArrayPress\RegisterComponents\Components` | `SugarCommerce\Data\Components` |
| `ArrayPress\RegisterComponents\Component` | `SugarCommerce\Data\Component` |
| `ArrayPress\RegisterComponents\Installer` | `SugarCommerce\Data\Installer` |
| `ArrayPress\RegisterComponents\Query` | `SugarCommerce\Data\Query` |
| `class Query extends \…\Vendor\ArrayPress\RegisterComponents\Query {}` | `class Query extends \…\Vendor\SugarCommerce\Data\Query {}` |
| a hand-written `DELETE FROM … WHERE date_created < %s … LIMIT %d` | `$query->sweep( 'date_created', $cutoff, … )` |
| `berlindb/core: dev-master` | unchanged: `dev-master`, tested at `a22276b` |

New: `Query::sweep()`.

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later
* BerlinDB 3.1 (`berlindb/core` dev-master)

## License

GPL-2.0-or-later
