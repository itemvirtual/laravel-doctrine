<?php

namespace Itemvirtual\LaravelDoctrine\Schema;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Parses doctrine XML mapping files (*.dcm.xml) into a plain array structure,
 * without touching doctrine/orm or requiring the mapped entity classes to exist.
 */
class XmlMappingReader
{
    private const NS = 'http://doctrine-project.org/schemas/orm/doctrine-mapping';

    /**
     * @return array<string, array<string, mixed>> Entity definitions keyed by entity name.
     */
    public function read(string $path): array
    {
        $entities = [];

        foreach (File::glob(rtrim($path, '/') . '/*.dcm.xml') as $file) {
            foreach ($this->readFile($file) as $name => $entity) {
                $entities[$name] = $entity;
            }
        }

        return $entities;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function readFile(string $file): array
    {
        $doc = new DOMDocument();

        if (!$doc->load($file)) {
            throw new RuntimeException('Unable to parse xml-mapping file: ' . $file);
        }

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('orm', self::NS);

        $entities = [];
        foreach ($xpath->query('//orm:entity') as $entityNode) {
            $entity = $this->readEntity($xpath, $entityNode);
            $entities[$entity['name']] = $entity;
        }

        return $entities;
    }

    /**
     * @return array<string, mixed>
     */
    private function readEntity(DOMXPath $xpath, DOMElement $node): array
    {
        $name = $node->getAttribute('name');

        return [
            'name' => $name,
            'table' => $node->getAttribute('table') ?: $name,
            'id' => $this->readId($xpath, $node),
            'columns' => $this->readColumns($xpath, $node),
            'indexes' => $this->readIndexes($xpath, $node),
            'uniqueConstraints' => $this->readUniqueConstraints($xpath, $node),
            'manyToMany' => $this->readManyToMany($xpath, $node),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readId(DOMXPath $xpath, DOMElement $entityNode): array
    {
        $idNode = $xpath->query('orm:id', $entityNode)->item(0);

        if (!$idNode instanceof DOMElement) {
            throw new RuntimeException('Entity "' . $entityNode->getAttribute('name') . '" has no <id> mapping.');
        }

        $generatorNode = $xpath->query('orm:generator', $idNode)->item(0);

        return [
            'column' => $idNode->getAttribute('column') ?: $idNode->getAttribute('name'),
            'type' => $idNode->getAttribute('type') ?: 'integer',
            'length' => $this->intOrNull($idNode->getAttribute('length')),
            'identity' => $generatorNode instanceof DOMElement
                && strtoupper($generatorNode->getAttribute('strategy') ?: 'AUTO') === 'IDENTITY',
            'options' => $this->readOptions($xpath, $idNode),
        ];
    }

    /**
     * <field> and <many-to-one> are read together, in document order, so that the columns they produce on the
     * entity's own table follow whatever order they are declared in the XML (for example, to keep a foreign
     * key column next to the id instead of always trailing after the regular fields).
     *
     * @return array<int, array<string, mixed>>
     */
    private function readColumns(DOMXPath $xpath, DOMElement $entityNode): array
    {
        $columns = [];

        foreach ($xpath->query('orm:field|orm:many-to-one', $entityNode) as $node) {
            $columns[] = $node->localName === 'field'
                ? ['type' => 'field', 'field' => $this->readField($xpath, $node)]
                : ['type' => 'manyToOne', 'relation' => $this->readManyToOneRelation($xpath, $node)];
        }

        return $columns;
    }

    /**
     * @return array<string, mixed>
     */
    private function readField(DOMXPath $xpath, DOMElement $fieldNode): array
    {
        return [
            'column' => $fieldNode->getAttribute('column') ?: $fieldNode->getAttribute('name'),
            'type' => $fieldNode->getAttribute('type') ?: 'string',
            'length' => $this->intOrNull($fieldNode->getAttribute('length')),
            'precision' => $this->intOrNull($fieldNode->getAttribute('precision')),
            'scale' => $this->intOrNull($fieldNode->getAttribute('scale')),
            'nullable' => $this->boolAttr($fieldNode, 'nullable', false),
            'unique' => $this->boolAttr($fieldNode, 'unique', false),
            'options' => $this->readOptions($xpath, $fieldNode),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readIndexes(DOMXPath $xpath, DOMElement $entityNode): array
    {
        $indexes = [];

        foreach ($xpath->query('orm:indexes/orm:index', $entityNode) as $indexNode) {
            $indexes[] = [
                'name' => $indexNode->getAttribute('name') ?: null,
                'unique' => $this->boolAttr($indexNode, 'unique', false),
                'columns' => $this->splitColumns($indexNode->getAttribute('columns')),
            ];
        }

        return $indexes;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readUniqueConstraints(DOMXPath $xpath, DOMElement $entityNode): array
    {
        $constraints = [];

        foreach ($xpath->query('orm:unique-constraints/orm:unique-constraint', $entityNode) as $node) {
            $constraints[] = [
                'name' => $node->getAttribute('name') ?: null,
                'columns' => $this->splitColumns($node->getAttribute('columns')),
            ];
        }

        return $constraints;
    }

    /**
     * @return array<string, mixed>
     */
    private function readManyToOneRelation(DOMXPath $xpath, DOMElement $node): array
    {
        return [
            'field' => $node->getAttribute('field'),
            'targetEntity' => $this->shortEntityName($node->getAttribute('target-entity')),
            'joinColumns' => $this->readJoinColumns($xpath, $node),
        ];
    }

    /**
     * Only the owning side (without mapped-by) generates a join table; the inverse side is ignored.
     *
     * @return array<int, array<string, mixed>>
     */
    private function readManyToMany(DOMXPath $xpath, DOMElement $entityNode): array
    {
        $relations = [];

        foreach ($xpath->query('orm:many-to-many', $entityNode) as $node) {
            if ($node->getAttribute('mapped-by') !== '') {
                continue;
            }

            $joinTableNode = $xpath->query('orm:join-table', $node)->item(0);

            if (!$joinTableNode instanceof DOMElement) {
                continue;
            }

            $relations[] = [
                'field' => $node->getAttribute('field'),
                'targetEntity' => $this->shortEntityName($node->getAttribute('target-entity')),
                'joinTable' => [
                    'name' => $joinTableNode->getAttribute('name'),
                    'joinColumns' => $this->readJoinColumns($xpath, $joinTableNode, 'orm:join-columns/orm:join-column'),
                    'inverseJoinColumns' => $this->readJoinColumns($xpath, $joinTableNode, 'orm:inverse-join-columns/orm:join-column'),
                ],
            ];
        }

        return $relations;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readJoinColumns(
        DOMXPath   $xpath,
        DOMElement $node,
        string     $expr = 'orm:join-column|orm:join-columns/orm:join-column',
    ): array
    {
        $columns = [];

        foreach ($xpath->query($expr, $node) as $joinColumnNode) {
            $columns[] = [
                'name' => $joinColumnNode->getAttribute('name') ?: null,
                'referencedColumnName' => $joinColumnNode->getAttribute('referenced-column-name') ?: 'id',
                'nullable' => $this->boolAttr($joinColumnNode, 'nullable', true),
                'unique' => $this->boolAttr($joinColumnNode, 'unique', false),
                'onDelete' => $joinColumnNode->getAttribute('on-delete') ?: null,
            ];
        }

        return $columns;
    }

    /**
     * @return array<string, mixed>
     */
    private function readOptions(DOMXPath $xpath, DOMElement $node): array
    {
        $optionsNode = $xpath->query('orm:options', $node)->item(0);

        if (!$optionsNode instanceof DOMElement) {
            return [];
        }

        $options = [];
        foreach ($xpath->query('orm:option', $optionsNode) as $optionNode) {
            $value = trim($optionNode->textContent);
            $options[$optionNode->getAttribute('name')] = $value === '' ? true : $value;
        }

        return $options;
    }

    /**
     * @return string[]
     */
    private function splitColumns(string $columns): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $columns))));
    }

    private function boolAttr(DOMElement $node, string $name, bool $default): bool
    {
        if (!$node->hasAttribute($name)) {
            return $default;
        }

        return filter_var($node->getAttribute($name), FILTER_VALIDATE_BOOLEAN);
    }

    private function intOrNull(string $value): ?int
    {
        return $value === '' ? null : (int)$value;
    }

    /**
     * target-entity may be a bare logical name (matching another <entity name="...">) or a FQCN; only the
     * short name is needed to resolve it against the entities parsed from the mappings directory.
     */
    private function shortEntityName(string $targetEntity): string
    {
        $parts = explode('\\', $targetEntity);

        return end($parts);
    }
}
