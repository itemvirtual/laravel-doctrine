# Laravel Doctrine

[![Latest Version on Packagist](https://img.shields.io/packagist/v/itemvirtual/laravel-doctrine.svg?style=flat-square)](https://packagist.org/packages/itemvirtual/laravel-doctrine)
[![Total Downloads](https://img.shields.io/packagist/dt/itemvirtual/laravel-doctrine.svg?style=flat-square)](https://packagist.org/packages/itemvirtual/laravel-doctrine)

Doctrine console commands for the Laravel framework.

This package keeps your database in sync with your Doctrine xml-mappings, as an alternative to migrations.
It can update and validate the database against the mappings, and generate xml-mappings from an existing database.

Since v2 the package only depends on `doctrine/dbal`. Your `database/doctrine/xml-mappings/*.dcm.xml` files are
read directly and compared against the current database schema; **no entity classes are generated anymore**.

###### **Important!**

If you're upgrading from v1, your existing xml-mappings may carry stale options left over from its old
entity-generation round-trip (like `<option name="fixed"/>` on columns that aren't actually fixed-length, or
a leftover `fetch="LAZY"` attribute that v2 never reads). Run the [`_skills/upgrade-v2-xml.md`](_skills/upgrade-v2-xml.md)
skill against your mappings before trusting `doctrine:update`'s output.

## Table of contents

- [Installation](#installation)
- [Getting started](#getting-started)
- [Commands](#commands)
- [Generating Laravel migrations](#generating-laravel-migrations)
- [Configuration](#configuration)
- [Supported xml-mapping elements](#supported-xml-mapping-elements)
- [Troubleshooting](#troubleshooting)
- [References](#references)

## Installation

Install the package via composer

``` bash
composer require itemvirtual/laravel-doctrine
```

Publish the configuration file (add `--force` to overwrite an existing one):

```bash
php artisan vendor:publish --provider="Itemvirtual\LaravelDoctrine\LaravelDoctrineServiceProvider" --tag=config
```

Laravel ships with some predefined migrations. You can put them in place with:

```bash
php artisan vendor:publish --provider="Itemvirtual\LaravelDoctrine\LaravelDoctrineServiceProvider" --tag=laravel_default_migrations
```

## Getting started

1. Create your xml-mappings in `database/doctrine/xml-mappings`, or generate them from an existing database:

```bash
php artisan doctrine:generate-mappings
```

2. Preview the SQL that would be executed to sync the database:

```bash
php artisan doctrine:update --dump-sql
```

3. Apply the changes:

``` bash
php artisan doctrine:update
```

4. Check that mappings and database are in sync:

``` bash
php artisan doctrine:validate
```

## Commands

| Command                        | Description                                                     |
|--------------------------------|-----------------------------------------------------------------|
| `doctrine:generate-mappings`   | Generate xml-mappings from your database                        |
| `doctrine:update`              | Update the database (or dump the SQL) based on the xml-mappings |
| `doctrine:validate`            | Validate mappings and their synchronization with the database   |
| `doctrine:migrations-generate` | Generate Laravel migration files from the database              |

Run `php artisan help <command>` to see all the arguments and options of a command.

### doctrine:generate-mappings

Generate xml-mappings from an existing database.

> This command is only useful when starting from an existing database. You should not need to run it more than once.

``` bash
php artisan doctrine:generate-mappings [--path=destination/path/to/xml-mappings] [--table=<table_name>]+
```

Example:

```bash
php artisan doctrine:generate-mappings --path=database/doctrine/xml-mappings --table=users --table=password_resets
```

Options:

```
--path[=PATH]     The path where your xml-mapping files will be generated
--table[=TABLE]   The database tables to be generated (multiple values allowed)
```

### doctrine:update

Compare the xml-mappings against the current database schema and run the resulting SQL, or preview it without
running it.

``` bash
php artisan doctrine:update [-D | --dump-sql]
```

Options:

```
-D, --dump-sql    Dump the generated SQL statements to the console (does not execute them)
```

### doctrine:validate

Check that the xml-mapping files are well formed and in sync with the database.

``` bash
php artisan doctrine:validate
```

## Generating Laravel migrations

`doctrine:migrations-generate` creates Laravel migration files from the current database. It is mainly used to
build migrations for your test suite, or to move away from this package to plain Laravel migrations.

``` bash
php artisan doctrine:migrations-generate [path] [options]
```

Arguments:

```
path    Destination path for the generated files [default: database/migrations]
```

Options:

```
-R, --remove                     Remove previously generated migration files
-O, --output                     Show the migrations package console output
-S, --single-file[=SINGLE-FILE]  Generate all migrations in a single file [default: "false"]
-M, --merge-foreign-keys         Merge each table's foreign keys into its own create-table migration instead 
                                 of a separate "add_foreign_keys_to_..." file. Has no effect with --single-file
--date[=DATE]                    Create migrations with the given date/time [default: today at midnight]
-T, --tables[=TABLES]            Comma-separated list of tables or views to generate: users,products,labels
-I, --ignore[=IGNORE]            Comma-separated list of tables or views to ignore: users,products,labels
```

### Single-file migration for testing

Squash everything into a single file:

```bash
php artisan doctrine:migrations-generate tests/database/migrations --single-file=true --date="2020-01-01 00:00:00"
```

### Per-table migrations

Use `--merge-foreign-keys` to keep each table's foreign keys in its own migration instead of separate
`add_foreign_keys_to_...` files. This is the option to use if you want to drop this package in favor of plain
Laravel migrations:

``` bash
php artisan doctrine:migrations-generate --merge-foreign-keys
```

Tables are reordered so the result is safe to run with `php artisan migrate`. Only genuinely circular foreign keys
are left in a separate migration.

### Registering the migrations without running them

Before generating anything, the command asks whether to register the new files in the `migrations` table —
useful since the database already has this schema, so actually running them would fail. Each file gets its own
batch number, so they can be rolled back one at a time. If the table already has entries, it also asks whether
to truncate it first.

## Configuration

All options live in `config/laravel-doctrine.php`, published during [installation](#installation).

### Ignoring tables

If your database has tables that are not managed by this package's xml-mappings (for example, tables owned by
another package), set `schema_filter` to a regex matching their names so that `doctrine:update` never proposes to
drop them:

```php
'schema_filter' => '/^(spatial_ref_sys)$/',
```

### Charset and collation

`doctrine:update` derives each table's default charset from `db_charset` (`utf8mb4` by default) and its default
collation from the collation already used by most of the existing tables in the database, so newly created
tables match the rest of your schema without any per-entity configuration.

## Supported xml-mapping elements

- `<entity name table>`, `<id>` with `<generator strategy="IDENTITY"/>`
- `<field>` types: `bigint`, `integer`, `smallint`, `boolean`, `string`, `text`, `json`, `date`, `datetime`,
  `time`, `decimal`
- `<field>` attributes: `column`, `length`, `nullable`, `unique`, `precision`, `scale`
- `<options>`: `default`, `unsigned`, `fixed`, `comment`
- `<indexes>` / `<unique-constraints>`
- `<many-to-one>` with `<join-column>` / `<join-columns>` (column + foreign key; type and unsigned are taken from
  the target entity's id)
- `<many-to-many>` with `<join-table>` (pivot table with a composite primary key and two foreign keys)

## Troubleshooting

### MySQL prior to 5.7.7: "Specified key was too long"

MySQL versions prior to 5.7.7 may throw the error _"Specified key was too long; max key length is 767 bytes"_.

Any `string` column with `unique="true"` must set a maximum `length="190"`. The same limit applies to `string`
columns used in indexes, so review your indexes as well.

## References

- [Doctrine XML Mapping](https://www.doctrine-project.org/projects/doctrine-orm/en/3.7/reference/xml-mapping.html)
- [Doctrine DBAL Types](https://www.doctrine-project.org/projects/doctrine-dbal/en/4.4/reference/types.html)

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Credits

- [Sergio](https://github.com/sergio-item)
- [Itemvirtual](https://github.com/itemvirtual)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.