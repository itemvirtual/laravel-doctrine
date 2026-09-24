<?php

namespace Itemvirtual\LaravelDoctrine\Tests\Schema;

use Itemvirtual\LaravelDoctrine\Schema\XmlMappingReader;
use PHPUnit\Framework\TestCase;

class XmlMappingReaderTest extends TestCase
{
    /** @test */
    public function it_reads_simple_fields_options_indexes_and_unique_constraints()
    {
        $entities = (new XmlMappingReader())->read($this->fixturesPath());

        $categories = $entities['Categories'];

        $this->assertSame('categories', $categories['table']);
        $this->assertSame('id', $categories['id']['column']);
        $this->assertSame('bigint', $categories['id']['type']);
        $this->assertTrue($categories['id']['identity']);

        $active = $this->fieldByColumn($categories['columns'], 'active');
        $this->assertSame('1', $active['options']['default']);

        $position = $this->fieldByColumn($categories['columns'], 'position');
        $this->assertTrue($position['options']['unsigned']);

        $this->assertSame(['categories_slug_unique'], array_column($categories['uniqueConstraints'], 'name'));
        $this->assertSame(['categories_name_index'], array_column($categories['indexes'], 'name'));
    }

    /** @test */
    public function it_reads_many_to_one_relations()
    {
        $entities = (new XmlMappingReader())->read($this->fixturesPath());

        $posts = $entities['Posts'];
        $manyToOne = array_values(array_filter($posts['columns'], fn ($column) => $column['type'] === 'manyToOne'));
        $this->assertCount(1, $manyToOne);

        $relation = $manyToOne[0]['relation'];
        $this->assertSame('Categories', $relation['targetEntity']);
        $this->assertSame('category_id', $relation['joinColumns'][0]['name']);
        $this->assertSame('CASCADE', $relation['joinColumns'][0]['onDelete']);
        $this->assertFalse($relation['joinColumns'][0]['nullable']);
    }

    /** @test */
    public function it_preserves_the_declaration_order_of_fields_and_many_to_one_relations()
    {
        $entities = (new XmlMappingReader())->read($this->fixturesPath());

        $columns = $entities['Posts']['columns'];

        $this->assertSame('field', $columns[0]['type']);
        $this->assertSame('title', $columns[0]['field']['column']);
        $this->assertSame('manyToOne', $columns[1]['type']);
        $this->assertSame('Categories', $columns[1]['relation']['targetEntity']);
    }

    /** @test */
    public function it_reads_the_owning_side_of_many_to_many_and_skips_the_inverse_side()
    {
        $entities = (new XmlMappingReader())->read($this->fixturesPath());

        $this->assertCount(1, $entities['Posts']['manyToMany']);
        $this->assertSame('posts_tags', $entities['Posts']['manyToMany'][0]['joinTable']['name']);

        $this->assertCount(0, $entities['Tags']['manyToMany']);
    }

    private function fixturesPath(): string
    {
        return __DIR__ . '/../fixtures/xml-mappings';
    }

    /**
     * @param array<int, array<string, mixed>> $columns
     * @return array<string, mixed>
     */
    private function fieldByColumn(array $columns, string $column): array
    {
        foreach ($columns as $entry) {
            if ($entry['type'] === 'field' && $entry['field']['column'] === $column) {
                return $entry['field'];
            }
        }

        $this->fail('Field with column "' . $column . '" not found.');
    }
}
