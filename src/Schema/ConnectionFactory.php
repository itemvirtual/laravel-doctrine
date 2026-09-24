<?php

namespace Itemvirtual\LaravelDoctrine\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\SchemaConfig;

/**
 * Creates a plain doctrine/dbal Connection from the package configuration, independent of doctrine/orm.
 */
class ConnectionFactory
{
    public static function create(): Connection
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => config('laravel-doctrine.db_host'),
            'port' => config('laravel-doctrine.db_port'),
            'user' => config('laravel-doctrine.db_username'),
            'password' => config('laravel-doctrine.db_password'),
            'dbname' => config('laravel-doctrine.db_database'),
            'charset' => config('laravel-doctrine.db_charset', 'utf8mb4'),
        ]);

        $connection->getDatabasePlatform()->registerDoctrineTypeMapping('enum', 'string');

        $schemaFilter = config('laravel-doctrine.schema_filter');
        if ($schemaFilter) {
            $connection->getConfiguration()->setSchemaAssetsFilter(
                static fn(string $tableName): bool => !preg_match($schemaFilter, $tableName),
            );
        }

        return $connection;
    }

    /**
     * A SchemaConfig carrying the same default table charset/collation already used by the tables in the
     * current database, so that newly created tables don't produce a spurious ALTER TABLE ... CHARACTER
     * SET/COLLATE diff against existing ones. `@@collation_database` is only the server-assigned default for
     * the schema and can differ from the collation the application actually uses on every table it creates
     * (for example, Laravel's `database.php` sets an explicit `collation` regardless of the server default),
     * so the collation shared by most existing tables is a more reliable signal than the schema default.
     */
    public static function schemaConfig(Connection $connection): SchemaConfig
    {
        $schemaConfig = $connection->createSchemaManager()->createSchemaConfig();

        $collation = $connection->fetchOne(
            'SELECT TABLE_COLLATION FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_COLLATION IS NOT NULL
             GROUP BY TABLE_COLLATION ORDER BY COUNT(*) DESC LIMIT 1',
        ) ?: $connection->fetchOne('SELECT @@collation_database');

        if ($collation) {
            $schemaConfig->setDefaultTableOptions(array_merge(
                $schemaConfig->getDefaultTableOptions(),
                ['collation' => $collation],
            ));
        }

        return $schemaConfig;
    }
}
