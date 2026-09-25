<?php

namespace Itemvirtual\LaravelDoctrine\Schema;

use Doctrine\DBAL\Schema\DefaultExpression\CurrentDate;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentTime;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaConfig;
use Doctrine\DBAL\Schema\Table;
use RuntimeException;

/**
 * Builds a Doctrine\DBAL\Schema\Schema from the intermediate array structure produced by XmlMappingReader.
 */
class SchemaBuilder
{
    /**
     * @param array<string, array<string, mixed>> $entities Entity definitions keyed by entity name.
     */
    public function build(array $entities, SchemaConfig $schemaConfig): Schema
    {
        $schema = new Schema([], [], $schemaConfig);

        foreach ($entities as $entity) {
            $this->buildTable($schema, $entity, $entities);
        }

        // Many-to-many pivot tables are resolved in a second pass so that a bidirectional relation, declared
        // on both entities, is only turned into a single join table instead of two.
        foreach ($entities as $entity) {
            $this->buildManyToMany($schema, $entity, $entities);
        }

        return $schema;
    }

    /**
     * Builds the entity's own table: id first, then every <many-to-one> foreign key column, then the regular
     * <field> columns, regardless of the order they are declared in in the XML — foreign keys always sit
     * right after the id, like Laravel's own migrations, instead of trailing after every other column.
     *
     * @param array<string, mixed> $entity
     * @param array<string, array<string, mixed>> $entities
     */
    private function buildTable(Schema $schema, array $entity, array $entities): Table
    {
        $table = $schema->createTable($entity['table']);

        $id = $entity['id'];
        $idOptions = [
            'notnull' => true,
            'autoincrement' => $id['identity'],
            'unsigned' => $this->isUnsignedId($entity),
        ];
        if (($id['length'] ?? null) !== null) {
            $idOptions['length'] = $id['length'];
        }
        $table->addColumn($id['column'], $id['type'], $idOptions);
        $table->setPrimaryKey([$id['column']]);

        foreach ($entity['columns'] as $column) {
            if ($column['type'] === 'manyToOne') {
                $this->addManyToOneColumns($table, $column['relation'], $entity, $entities);
            }
        }

        foreach ($entity['columns'] as $column) {
            if ($column['type'] === 'field') {
                $field = $column['field'];
                $this->addColumn($table, $field['column'], $field['type'], $field);

                if ($field['unique']) {
                    $table->addUniqueIndex([$field['column']]);
                }
            }
        }

        foreach ($entity['indexes'] as $index) {
            if ($index['unique']) {
                $table->addUniqueIndex($index['columns'], $index['name']);
            } else {
                $table->addIndex($index['columns'], $index['name']);
            }
        }

        foreach ($entity['uniqueConstraints'] as $constraint) {
            $table->addUniqueIndex($constraint['columns'], $constraint['name']);
        }

        return $table;
    }

    /**
     * @param array<string, mixed> $field
     */
    private function addColumn(Table $table, string $name, string $type, array $field): void
    {
        $options = ['notnull' => !$field['nullable']];

        if ($field['length'] !== null) {
            $options['length'] = $field['length'];
        }
        if ($field['precision'] !== null) {
            $options['precision'] = $field['precision'];
        }
        if ($field['scale'] !== null) {
            $options['scale'] = $field['scale'];
        }

        $table->addColumn($name, $type, array_merge($options, $this->columnOptions($field['options'])));
    }

    /**
     * @param array<string, mixed> $mappingOptions
     * @return array<string, mixed>
     */
    private function columnOptions(array $mappingOptions): array
    {
        $options = [];

        if (array_key_exists('unsigned', $mappingOptions)) {
            $options['unsigned'] = filter_var($mappingOptions['unsigned'], FILTER_VALIDATE_BOOLEAN);
        }

        if (array_key_exists('fixed', $mappingOptions)) {
            $options['fixed'] = filter_var($mappingOptions['fixed'], FILTER_VALIDATE_BOOLEAN);
        }

        if (array_key_exists('default', $mappingOptions)) {
            $options['default'] = $this->defaultValueOption($mappingOptions['default']);
        }

        if (array_key_exists('comment', $mappingOptions)) {
            $options['comment'] = $mappingOptions['comment'];
        }

        return $options;
    }

    /**
     * CURRENT_TIMESTAMP/CURRENT_DATE/CURRENT_TIME are recognized as DBAL's expression objects instead of being
     * passed through as a plain string default: DBAL only treats the bare string as that expression through a
     * deprecated fallback (string comparison against the platform's own SQL for it), so relying on it would
     * both trigger a deprecation notice and risk breaking on a future DBAL version.
     */
    private function defaultValueOption(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        return match ($value) {
            'CURRENT_TIMESTAMP' => new CurrentTimestamp(),
            'CURRENT_DATE' => new CurrentDate(),
            'CURRENT_TIME' => new CurrentTime(),
            default => $value,
        };
    }

    /**
     * @param array<string, mixed> $relation
     * @param array<string, mixed> $entity
     * @param array<string, array<string, mixed>> $entities
     */
    private function addManyToOneColumns(Table $table, array $relation, array $entity, array $entities): void
    {
        $target = $this->resolveTargetEntity($entities, $entity, $relation['targetEntity']);

        $defaultColumnName = strtolower($relation['field'] ?: $relation['targetEntity']) . '_id';

        $joinColumns = $relation['joinColumns'] ?: [
            [
                'name' => null,
                'referencedColumnName' => $target['id']['column'],
                'nullable' => true,
                'unique' => false,
                'onDelete' => null,
            ]
        ];

        $localColumns = [];
        $foreignColumns = [];

        foreach ($joinColumns as $joinColumn) {
            $columnName = $joinColumn['name'] ?: $defaultColumnName;
            $referencedColumnName = $joinColumn['referencedColumnName'] ?: $target['id']['column'];

            $table->addColumn($columnName, $target['id']['type'], $this->referencedIdColumnOptions($target, !$joinColumn['nullable']));

            if ($joinColumn['unique']) {
                $table->addUniqueIndex([$columnName]);
            }

            $localColumns[] = $columnName;
            $foreignColumns[] = $referencedColumnName;
        }

        $table->addForeignKeyConstraint(
            $target['table'],
            $localColumns,
            $foreignColumns,
            $this->foreignKeyOptions($joinColumns[0]['onDelete'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $entity
     * @param array<string, array<string, mixed>> $entities
     */
    private function buildManyToMany(Schema $schema, array $entity, array $entities): void
    {
        foreach ($entity['manyToMany'] as $relation) {
            $joinTable = $relation['joinTable'];

            if ($schema->hasTable($joinTable['name'])) {
                // Already created from the owning side of a bidirectional relation.
                continue;
            }

            $target = $this->resolveTargetEntity($entities, $entity, $relation['targetEntity']);

            $pivot = $schema->createTable($joinTable['name']);

            $ownColumns = $this->addPivotColumns(
                $pivot,
                $entity,
                $joinTable['joinColumns'],
                strtolower($entity['name']) . '_id',
            );

            $targetColumns = $this->addPivotColumns(
                $pivot,
                $target,
                $joinTable['inverseJoinColumns'],
                strtolower($target['name']) . '_id',
            );

            $pivot->setPrimaryKey(array_merge($ownColumns, $targetColumns));
        }
    }

    /**
     * @param array<string, mixed> $referencedEntity
     * @param array<int, array<string, mixed>> $joinColumns
     * @return string[] Column names added to the pivot table.
     */
    private function addPivotColumns(Table $pivot, array $referencedEntity, array $joinColumns, string $defaultColumnName): array
    {
        if (empty($joinColumns)) {
            $joinColumns = [
                [
                    'name' => null,
                    'referencedColumnName' => $referencedEntity['id']['column'],
                    'onDelete' => null,
                ]
            ];
        }

        $columnNames = [];
        $localColumns = [];
        $foreignColumns = [];

        foreach ($joinColumns as $joinColumn) {
            $columnName = $joinColumn['name'] ?: $defaultColumnName;
            $referencedColumnName = $joinColumn['referencedColumnName'] ?: $referencedEntity['id']['column'];

            $pivot->addColumn($columnName, $referencedEntity['id']['type'], $this->referencedIdColumnOptions($referencedEntity, true));

            $columnNames[] = $columnName;
            $localColumns[] = $columnName;
            $foreignColumns[] = $referencedColumnName;
        }

        $pivot->addForeignKeyConstraint(
            $referencedEntity['table'],
            $localColumns,
            $foreignColumns,
            $this->foreignKeyOptions($joinColumns[0]['onDelete'] ?? null),
        );

        return $columnNames;
    }

    /**
     * @return array<string, string>
     */
    private function foreignKeyOptions(?string $onDelete): array
    {
        return $onDelete ? ['onDelete' => $onDelete] : [];
    }

    /**
     * @param array<string, mixed> $entity
     */
    private function isUnsignedId(array $entity): bool
    {
        $options = $entity['id']['options'] ?? [];

        return array_key_exists('unsigned', $options) && filter_var($options['unsigned'], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The notnull/unsigned/length options for a column that stores a foreign key referencing $entity's id.
     *
     * @param array<string, mixed> $entity
     * @return array<string, mixed>
     */
    private function referencedIdColumnOptions(array $entity, bool $notnull): array
    {
        $options = [
            'notnull' => $notnull,
            'unsigned' => $this->isUnsignedId($entity),
        ];

        if (($entity['id']['length'] ?? null) !== null) {
            $options['length'] = $entity['id']['length'];
        }

        return $options;
    }

    /**
     * @param array<string, array<string, mixed>> $entities
     * @param array<string, mixed> $sourceEntity
     * @return array<string, mixed>
     */
    private function resolveTargetEntity(array $entities, array $sourceEntity, string $targetEntity): array
    {
        if (!isset($entities[$targetEntity])) {
            throw new RuntimeException(sprintf(
                'Entity "%s" references unknown target-entity "%s".',
                $sourceEntity['name'],
                $targetEntity,
            ));
        }

        return $entities[$targetEntity];
    }
}
