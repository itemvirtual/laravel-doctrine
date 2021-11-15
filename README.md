# Laravel Doctrine

[![Latest Version on Packagist](https://img.shields.io/packagist/v/itemvirtual/laravel-doctrine.svg?style=flat-square)](https://packagist.org/packages/itemvirtual/laravel-doctrine)
[![Total Downloads](https://img.shields.io/packagist/dt/itemvirtual/laravel-doctrine.svg?style=flat-square)](https://packagist.org/packages/itemvirtual/laravel-doctrine)

Doctrine Console Commands for Laravel framework.  
This package is just to keep your database in sync (instead of migrations).  
Update, validate and generate xml-mappings from the database.

## Installation

You can install the package via composer:

``` bash
composer require itemvirtual/laravel-doctrine
```

In order to edit the default configuration you may execute: (with `--force` option to update)
``` bash
php artisan vendor:publish --provider="Itemvirtual\LaravelDoctrine\LaravelDoctrineServiceProvider" --tag=config
```

Laravel comes with some predefined migrations, you can put them in place with this publish
``` bash
php artisan vendor:publish --provider="Itemvirtual\LaravelDoctrine\LaravelDoctrineServiceProvider" --tag=laravel_default_migrations
```

## Usage

#### · Generate xml-mappings from database
> This command is only useful when you have an existing database, It should not be necessary for you to call this method multiple times

You can provide the destination path where the generated files will be saved.  
You also have the option to only generate the mappings for certain tables.

``` bash
php artisan doctrine:generate-mappings [--path=destination/path/to/xml-mappings] [--table=<table_name>]+
php artisan doctrine:generate-mappings --path=database/doctrine/xml-mappings --table=ursers --table=password_resets
```
Options:
```
  --path[=PATH]     The path where your xml-mapping files will be generated
  --table[=TABLE]   The database tables to be generated (multiple values allowed)
```

#### · Validate mappings and database
Check if the associations are defined correctly, and their mappings are in sync with the database.  
You can remove all your entities before perform validating.

``` bash
php artisan doctrine:validate [-R | --remove-entities]
```
Options:
```
  -R, --remove-entities  Delete current entities before generating new ones
```

#### · Update database
Run the queries to update your database or preview them without querying.  
You can remove all of your entities before upgrading.  
Every time you run this command, `doctrine:generate-entities` is called
``` bash
php artisan doctrine:update [-D | --dump-sql] [-R | --remove-entities]
php artisan doctrine:update -DR
```
Options:
```
  -D, --dump-sql         Dumps generated SQL statements to the console (does not execute them)
  -R, --remove-entities  Delete current entities before generating new ones
```

#### · Cache clear
Sometimes you can get missing entity errors, deleting cached data can help to fix it.  

``` bash
php artisan doctrine:clear-cache [--flush]

# This command will run these three commands at once, you can run them separately if you want 
php artisan doctrine:clear-cache:metadata [--flush]
php artisan doctrine:clear-cache:query [--flush]
php artisan doctrine:clear-cache:result [--flush]
```
Options:
```
  --flush    If defined, cache entries will be flushed instead of deleted/invalidated
```

#### · Generate migrations (for testing)
For testing purposes, you will need your project migrations. You can generate it with the following command.  
By default, they will be generated in `tests/database/migrations`
``` bash
php artisan doctrine:migrations-generate [destination/path]
php artisan doctrine:migrations-generate --tables users,password_resets --ignore users,password_resets
```
Arguments:
```
  path    If defined, it will generate the files in the given path, by default
```
Options:
```
  -R, --remove                     Remove previous generated migration files
  -O, --output                     View migrations package console output
  -S, --single-file[=SINGLE-FILE]  Generate all migrations in a single file [default: "true"]
  -T, --tables[=TABLES]            A list of Tables or Views you wish to Generate Migrations separated by comma: users,products,labels
  -I, --ignore[=IGNORE]            A list of Tables or Views you wish to ignore, separated by comma: users,products,labels
```

### Available commands for the "doctrine" namespace
``` bash
  doctrine:clear-cache           Clear metadata, query and result cache of the various cache drivers
  doctrine:clear-cache:metadata  Clear all metadata cache of the various cache drivers
  doctrine:clear-cache:query     Clear all query cache of the various cache drivers
  doctrine:clear-cache:result    Clear all result cache of the various cache drivers
  doctrine:convert-mapping       Convert mapping information between supported formats
  doctrine:generate-entities     Generate entity classes and method stubs from your mapping information (xml-mappings)
  doctrine:generate-mappings     Generate xml-mappings from your database
  doctrine:remove-entities       Remove all entities
  doctrine:update                Update the database (or dump SQL) based on the entities information
  doctrine:validate              Validate mappings and synchronization with the database
  doctrine:migrations-generate   Generate laravel migration files from database 
```
You can see the arguments and options of each of them with the help command
```
php artisan -help <command>
```

### Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Credits

- [Sergio](https://github.com/sergio-item)
- [Itemvirtual](https://github.com/itemvirtual)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Doctrine XML Mapping documentation and examples

[Doctrine documentation](https://www.doctrine-project.org/projects/doctrine-orm/en/2.8/reference/xml-mapping.html)