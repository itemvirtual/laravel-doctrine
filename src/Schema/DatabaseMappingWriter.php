<?php

namespace Itemvirtual\LaravelDoctrine\Schema;

use DOMDocument;
use DOMElement;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentDate;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentTime;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp;
use Doctrine\DBAL\Schema\Exception\InvalidState;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Illuminate\Support\Str;

/**
 * Writes doctrine XML mapping files (*.dcm.xml) from an introspected database schema.
 *
 * Foreign keys are reverse-engineered as <many-to-one> associations. Many-to-many join tables (a composite
 * primary key made entirely of foreign keys, nothing else) are skipped instead — see isPureJoinTable().
 */
class DatabaseMappingWriter
{
    private const NS = 'http://doctrine-project.org/schemas/orm/doctrine-mapping';
    private const XSI_NS = 'http://www.w3.org/2001/XMLSchema-instance';
    private const SCHEMA_LOCATION = self::NS . ' https://www.doctrine-project.org/schemas/orm/doctrine-mapping.xsd';

    /**
     * @param Table[] $tables
     * @param string[] $onlyTables
     * @return array{written: string[], skipped: string[], manyToMany: array<string, string>} Entity names that
     *         were written; join-table names skipped as their own entity with no relation attached anywhere
     *         (left for the user to map by hand); and join-table name => owning entity name for the ones
     *         successfully attached as a <many-to-many> instead.
     */
    public function write(array $tables, string $destinationPath, array $onlyTables = []): array
    {
        // Detected against every table regardless of $onlyTables, so a many-to-many still shows up on an
        // entity that's being (re)generated even if the join table or the other related entity isn't.
        $manyToManyByOwner = $this->detectManyToMany($tables);

        $written = [];
        $skipped = [];
        $manyToMany = [];

        foreach ($tables as $table) {
            if ($onlyTables && !in_array($table->getName(), $onlyTables, true)) {
                continue;
            }

            if ($this->isPureJoinTable($table)) {
                $skipped[] = $table->getName();
                continue;
            }

            $entityName = ucfirst(Str::camel($table->getName()));
            $relations = $manyToManyByOwner[$table->getName()] ?? [];

            file_put_contents(
                rtrim($destinationPath, '/') . '/' . $entityName . '.dcm.xml',
                $this->buildXml($table, $entityName, $relations),
            );

            foreach ($relations as $relation) {
                $manyToMany[$relation['joinTable']] = $entityName;
            }

            $written[] = $entityName;
        }

        $skipped = array_values(array_diff($skipped, array_keys($manyToMany)));

        return ['written' => $written, 'skipped' => $skipped, 'manyToMany' => $manyToMany];
    }

    /**
     * Every plain (two-sided) many-to-many join table, keyed by the name of the table that will own the
     * relation — the one whose foreign key column comes first, matching how Laravel itself names and declares
     * these pivot tables (e.g. course_course_topic: course_id before course_topic_id, so Courses owns it).
     *
     * @param Table[] $tables
     * @return array<string, array<int, array{targetTable: string, joinTable: string, ownJoinColumn: string, ownReferencedColumn: string, ownOnDelete: ?string, targetJoinColumn: string, targetReferencedColumn: string, targetOnDelete: ?string}>>
     */
    private function detectManyToMany(array $tables): array
    {
        $result = [];

        foreach ($tables as $table) {
            if (!$this->isPureJoinTable($table)) {
                continue;
            }

            $foreignKeys = array_values($table->getForeignKeys());

            if (count($foreignKeys) !== 2) {
                // Not a plain two-sided pivot (self-referencing, or more than two sides); still skipped as
                // its own entity, but left for the user to map by hand.
                continue;
            }

            $columnOrder = array_values(array_map(static fn(Column $c) => $c->getName(), $table->getColumns()));
            usort($foreignKeys, static function (ForeignKeyConstraint $a, ForeignKeyConstraint $b) use ($columnOrder) {
                return array_search($a->getReferencingColumnNames()[0]->toString(), $columnOrder, true)
                    <=> array_search($b->getReferencingColumnNames()[0]->toString(), $columnOrder, true);
            });

            [$ownForeignKey, $targetForeignKey] = $foreignKeys;
            $ownTable = $ownForeignKey->getReferencedTableName()->toString();

            $result[$ownTable][] = [
                'targetTable' => $targetForeignKey->getReferencedTableName()->toString(),
                'joinTable' => $table->getName(),
                'ownJoinColumn' => $this->localColumnNames($ownForeignKey)[0],
                'ownReferencedColumn' => $this->referencedColumnNames($ownForeignKey)[0],
                'ownOnDelete' => $this->onDeleteAttribute($ownForeignKey),
                'targetJoinColumn' => $this->localColumnNames($targetForeignKey)[0],
                'targetReferencedColumn' => $this->referencedColumnNames($targetForeignKey)[0],
                'targetOnDelete' => $this->onDeleteAttribute($targetForeignKey),
            ];
        }

        return $result;
    }

    /**
     * A table whose primary key is entirely made of columns that are also foreign keys, with no other columns
     * — i.e. a plain many-to-many join table (Laravel's belongsToMany convention), not a real entity of its
     * own. This package's XML format only supports a single <id> per entity, so reverse-engineering one of
     * these would silently keep only the first id column and lose the rest; it's already representable as a
     * <many-to-many><join-table> on one of the two related entities instead.
     */
    private function isPureJoinTable(Table $table): bool
    {
        $primaryKey = $table->getPrimaryKey();
        $pkColumns = $primaryKey ? $primaryKey->getColumns() : [];

        if (count($pkColumns) < 2) {
            return false;
        }

        $allColumns = array_map(static fn(Column $column) => $column->getName(), $table->getColumns());

        if (array_diff($allColumns, $pkColumns) !== []) {
            return false;
        }

        $foreignKeyColumns = [];
        foreach ($table->getForeignKeys() as $foreignKey) {
            array_push($foreignKeyColumns, ...$this->localColumnNames($foreignKey));
        }

        return array_diff($pkColumns, $foreignKeyColumns) === [];
    }

    /**
     * @param array<int, array{targetTable: string, joinTable: string, ownJoinColumn: string, ownReferencedColumn: string, ownOnDelete: ?string, targetJoinColumn: string, targetReferencedColumn: string, targetOnDelete: ?string}> $manyToMany
     */
    private function buildXml(Table $table, string $entityName, array $manyToMany): string
    {
        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->formatOutput = true;

        $mapping = $doc->createElementNS(self::NS, 'doctrine-mapping');
        $mapping->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', self::XSI_NS);
        $mapping->setAttributeNS(self::XSI_NS, 'xsi:schemaLocation', self::SCHEMA_LOCATION);
        $doc->appendChild($mapping);

        $entity = $doc->createElement('entity');
        $entity->setAttribute('name', $entityName);
        $entity->setAttribute('table', $table->getName());
        $mapping->appendChild($entity);

        $foreignKeys = array_values($table->getForeignKeys());
        $foreignKeyColumns = [];
        foreach ($foreignKeys as $foreignKey) {
            array_push($foreignKeyColumns, ...$this->localColumnNames($foreignKey));
        }

        $this->appendUniqueConstraints($doc, $entity, $table);
        $this->appendIndexes($doc, $entity, $table, $foreignKeys);

        $primaryKey = $table->getPrimaryKey();
        $idColumns = $primaryKey ? $primaryKey->getColumns() : [];

        foreach ($table->getColumns() as $column) {
            if (in_array($column->getName(), $idColumns, true)) {
                $entity->appendChild($this->buildIdElement($doc, $column));
                continue;
            }

            if (in_array($column->getName(), $foreignKeyColumns, true)) {
                // Represented as a <many-to-one> below instead of a plain field.
                continue;
            }

            $entity->appendChild($this->buildFieldElement($doc, $column));
        }

        foreach ($foreignKeys as $foreignKey) {
            $entity->appendChild($this->buildManyToOneElement($doc, $foreignKey));
        }

        foreach ($manyToMany as $relation) {
            $entity->appendChild($this->buildManyToManyElement($doc, $relation));
        }

        return (string)$doc->saveXML();
    }

    /**
     * @return string[]
     */
    private function localColumnNames(ForeignKeyConstraint $foreignKey): array
    {
        return array_map(static fn($name) => $name->toString(), $foreignKey->getReferencingColumnNames());
    }

    /**
     * @return string[]
     */
    private function referencedColumnNames(ForeignKeyConstraint $foreignKey): array
    {
        return array_map(static fn($name) => $name->toString(), $foreignKey->getReferencedColumnNames());
    }

    private function buildManyToOneElement(DOMDocument $doc, ForeignKeyConstraint $foreignKey): DOMElement
    {
        $localColumns = $this->localColumnNames($foreignKey);
        $referencedColumns = $this->referencedColumnNames($foreignKey);
        $referencedTable = $foreignKey->getReferencedTableName()->toString();
        $onDelete = $this->onDeleteAttribute($foreignKey);

        $manyToOne = $doc->createElement('many-to-one');
        $manyToOne->setAttribute('field', $this->relationFieldName($localColumns[0], $referencedTable));
        $manyToOne->setAttribute('target-entity', ucfirst(Str::camel($referencedTable)));

        $joinColumns = $doc->createElement('join-columns');

        foreach ($localColumns as $i => $localColumn) {
            $joinColumns->appendChild($this->buildJoinColumn(
                $doc,
                $localColumn,
                $referencedColumns[$i] ?? $referencedColumns[0],
                $onDelete,
            ));
        }

        $manyToOne->appendChild($joinColumns);

        return $manyToOne;
    }

    /**
     * @param array{targetTable: string, joinTable: string, ownJoinColumn: string, ownReferencedColumn: string, ownOnDelete: ?string, targetJoinColumn: string, targetReferencedColumn: string, targetOnDelete: ?string} $relation
     */
    private function buildManyToManyElement(DOMDocument $doc, array $relation): DOMElement
    {
        $manyToMany = $doc->createElement('many-to-many');
        $manyToMany->setAttribute('field', Str::camel($relation['targetTable']));
        $manyToMany->setAttribute('target-entity', ucfirst(Str::camel($relation['targetTable'])));

        $joinTable = $doc->createElement('join-table');
        $joinTable->setAttribute('name', $relation['joinTable']);

        $joinColumns = $doc->createElement('join-columns');
        $joinColumns->appendChild($this->buildJoinColumn(
            $doc,
            $relation['ownJoinColumn'],
            $relation['ownReferencedColumn'],
            $relation['ownOnDelete'],
        ));
        $joinTable->appendChild($joinColumns);

        $inverseJoinColumns = $doc->createElement('inverse-join-columns');
        $inverseJoinColumns->appendChild($this->buildJoinColumn(
            $doc,
            $relation['targetJoinColumn'],
            $relation['targetReferencedColumn'],
            $relation['targetOnDelete'],
        ));
        $joinTable->appendChild($inverseJoinColumns);

        $manyToMany->appendChild($joinTable);

        return $manyToMany;
    }

    private function buildJoinColumn(DOMDocument $doc, string $name, string $referencedColumnName, ?string $onDelete): DOMElement
    {
        $joinColumn = $doc->createElement('join-column');
        $joinColumn->setAttribute('name', $name);
        $joinColumn->setAttribute('referenced-column-name', $referencedColumnName);

        if ($onDelete !== null) {
            $joinColumn->setAttribute('on-delete', $onDelete);
        }

        return $joinColumn;
    }

    /**
     * A field name derived from the foreign key column itself (e.g. "course_id" -> "course") rather than
     * guessed from the referenced table's name, since a custom column name (e.g. "owner_id") carries more
     * intent than the target table ever could.
     */
    private function relationFieldName(string $localColumn, string $referencedTable): string
    {
        if (str_ends_with($localColumn, '_id')) {
            return Str::camel(substr($localColumn, 0, -3));
        }

        return Str::camel(Str::singular($referencedTable));
    }

    /**
     * RESTRICT/NO ACTION are what MySQL reports for a foreign key that never specified an ON DELETE clause, so
     * writing them out explicitly would be a false positive: it would add an ON DELETE clause that wasn't
     * there before, the next time the mappings are read back and compared against the database.
     */
    private function onDeleteAttribute(ForeignKeyConstraint $foreignKey): ?string
    {
        try {
            $action = $foreignKey->getOnDeleteAction();
        } catch (InvalidState) {
            return null;
        }

        return in_array($action, [ReferentialAction::RESTRICT, ReferentialAction::NO_ACTION], true)
            ? null
            : $action->value;
    }

    private function buildIdElement(DOMDocument $doc, Column $column): DOMElement
    {
        $id = $doc->createElement('id');
        $id->setAttribute('name', Str::camel($column->getName()));
        $id->setAttribute('type', Type::lookupName($column->getType()));
        $id->setAttribute('column', $column->getName());

        if ($column->getAutoincrement()) {
            $generator = $doc->createElement('generator');
            $generator->setAttribute('strategy', 'IDENTITY');
            $id->appendChild($generator);
        }

        $this->appendFieldOptions($doc, $id, $column);

        return $id;
    }

    private function buildFieldElement(DOMDocument $doc, Column $column): DOMElement
    {
        $typeName = Type::lookupName($column->getType());

        $field = $doc->createElement('field');
        $field->setAttribute('name', Str::camel($column->getName()));
        $field->setAttribute('type', $typeName);
        $field->setAttribute('column', $column->getName());

        if ($column->getLength() !== null) {
            $field->setAttribute('length', (string)$column->getLength());
        }

        if ($typeName === 'decimal') {
            $field->setAttribute('precision', (string)($column->getPrecision() ?? 10));
            $field->setAttribute('scale', (string)$column->getScale());
        }

        $field->setAttribute('nullable', $column->getNotnull() ? 'false' : 'true');

        $this->appendFieldOptions($doc, $field, $column);

        return $field;
    }

    /**
     * Appends an <options> element for either a <field> or an <id>.
     */
    private function appendFieldOptions(DOMDocument $doc, DOMElement $element, Column $column): void
    {
        $options = [];

        if ($column->getUnsigned()) {
            $options['unsigned'] = null;
        }
        if ($column->getFixed()) {
            $options['fixed'] = null;
        }
        if ($column->getDefault() !== null) {
            $options['default'] = $this->defaultValueForXml($column->getDefault());
        }
        if ($column->getComment() !== '') {
            $options['comment'] = $column->getComment();
        }

        if (!$options) {
            return;
        }

        $optionsEl = $doc->createElement('options');

        foreach ($options as $name => $value) {
            $optionEl = $doc->createElement('option');
            $optionEl->setAttribute('name', $name);

            if ($value !== null) {
                $optionEl->appendChild($doc->createTextNode($value));
            }

            $optionsEl->appendChild($optionEl);
        }

        $element->appendChild($optionsEl);
    }

    /**
     * A column default can be a plain scalar, or one of DBAL's expression objects (for DEFAULT CURRENT_TIMESTAMP
     * and friends) — those don't have a database-specific SQL representation to speak of (MySQL/MariaDB use the
     * same fixed keyword regardless of platform), so they're written as that keyword directly.
     */
    private function defaultValueForXml(mixed $default): string
    {
        return match (true) {
            $default instanceof CurrentTimestamp => 'CURRENT_TIMESTAMP',
            $default instanceof CurrentDate => 'CURRENT_DATE',
            $default instanceof CurrentTime => 'CURRENT_TIME',
            default => (string)$default,
        };
    }

    /**
     * @param ForeignKeyConstraint[] $foreignKeys
     */
    private function appendIndexes(DOMDocument $doc, DOMElement $entity, Table $table, array $foreignKeys): void
    {
        // A foreign key's columns always get an index of their own to support the constraint; SchemaBuilder
        // already creates that index implicitly when it builds the <many-to-one>, so listing it here too
        // would be a duplicate.
        $foreignKeyColumnSets = array_map(fn(ForeignKeyConstraint $fk) => $this->localColumnNames($fk), $foreignKeys);

        $indexes = array_filter($table->getIndexes(), static function ($index) use ($foreignKeyColumnSets) {
            if ($index->isPrimary() || $index->isUnique()) {
                return false;
            }

            return !in_array($index->getColumns(), $foreignKeyColumnSets, true);
        });

        if (!$indexes) {
            return;
        }

        $indexesEl = $doc->createElement('indexes');

        foreach ($indexes as $index) {
            $indexEl = $doc->createElement('index');
            $indexEl->setAttribute('name', $index->getName());
            $indexEl->setAttribute('columns', implode(',', $index->getColumns()));
            $indexesEl->appendChild($indexEl);
        }

        $entity->appendChild($indexesEl);
    }

    private function appendUniqueConstraints(DOMDocument $doc, DOMElement $entity, Table $table): void
    {
        $uniques = array_filter($table->getIndexes(), static fn($index) => !$index->isPrimary() && $index->isUnique());

        if (!$uniques) {
            return;
        }

        $constraintsEl = $doc->createElement('unique-constraints');

        foreach ($uniques as $index) {
            $constraintEl = $doc->createElement('unique-constraint');
            $constraintEl->setAttribute('name', $index->getName());
            $constraintEl->setAttribute('columns', implode(',', $index->getColumns()));
            $constraintsEl->appendChild($constraintEl);
        }

        $entity->appendChild($constraintsEl);
    }
}
