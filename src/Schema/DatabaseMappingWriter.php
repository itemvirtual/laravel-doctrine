<?php

namespace Itemvirtual\LaravelDoctrine\Schema;

use DOMDocument;
use DOMElement;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Illuminate\Support\Str;

/**
 * Writes doctrine XML mapping files (*.dcm.xml) from an introspected database schema.
 *
 * This only reverse-engineers plain columns, indexes and unique constraints; foreign keys are not turned into
 * many-to-one/many-to-many associations, they stay as plain fields. Add associations by hand afterwards if needed.
 */
class DatabaseMappingWriter
{
    private const NS = 'http://doctrine-project.org/schemas/orm/doctrine-mapping';
    private const XSI_NS = 'http://www.w3.org/2001/XMLSchema-instance';
    private const SCHEMA_LOCATION = self::NS . ' https://www.doctrine-project.org/schemas/orm/doctrine-mapping.xsd';

    /**
     * @param Table[] $tables
     * @param string[] $onlyTables
     * @return string[] Entity names that were written
     */
    public function write(array $tables, string $destinationPath, array $onlyTables = []): array
    {
        $written = [];

        foreach ($tables as $table) {
            if ($onlyTables && !in_array($table->getName(), $onlyTables, true)) {
                continue;
            }

            $entityName = ucfirst(Str::camel($table->getName()));

            file_put_contents(
                rtrim($destinationPath, '/') . '/' . $entityName . '.dcm.xml',
                $this->buildXml($table, $entityName),
            );

            $written[] = $entityName;
        }

        return $written;
    }

    private function buildXml(Table $table, string $entityName): string
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

        $this->appendUniqueConstraints($doc, $entity, $table);
        $this->appendIndexes($doc, $entity, $table);

        $primaryKey = $table->getPrimaryKey();
        $idColumns = $primaryKey ? $primaryKey->getColumns() : [];

        foreach ($table->getColumns() as $column) {
            if (in_array($column->getName(), $idColumns, true)) {
                $entity->appendChild($this->buildIdElement($doc, $column));
                continue;
            }

            $entity->appendChild($this->buildFieldElement($doc, $column));
        }

        return (string)$doc->saveXML();
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

    private function appendFieldOptions(DOMDocument $doc, DOMElement $field, Column $column): void
    {
        $options = [];

        if ($column->getUnsigned()) {
            $options['unsigned'] = null;
        }
        if ($column->getFixed()) {
            $options['fixed'] = null;
        }
        if ($column->getDefault() !== null) {
            $options['default'] = (string)$column->getDefault();
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

        $field->appendChild($optionsEl);
    }

    private function appendIndexes(DOMDocument $doc, DOMElement $entity, Table $table): void
    {
        $indexes = array_filter($table->getIndexes(), static fn($index) => !$index->isPrimary() && !$index->isUnique());

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
