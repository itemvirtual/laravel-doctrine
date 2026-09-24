<?php

namespace Itemvirtual\LaravelDoctrine\Tests\Schema;

use Doctrine\DBAL\Schema\SchemaConfig;
use Itemvirtual\LaravelDoctrine\Schema\SchemaBuilder;
use Itemvirtual\LaravelDoctrine\Schema\XmlMappingReader;
use PHPUnit\Framework\TestCase;

class SchemaBuilderTest extends TestCase
{
    /** @test */
    public function it_builds_tables_columns_and_foreign_keys()
    {
        $schema = (new SchemaBuilder())->build($this->readEntities(), new SchemaConfig());

        $this->assertTrue($schema->hasTable('categories'));
        $this->assertTrue($schema->hasTable('posts'));
        $this->assertTrue($schema->hasTable('tags'));
        $this->assertTrue($schema->hasTable('posts_tags'));

        $posts = $schema->getTable('posts');
        $this->assertTrue($posts->hasColumn('category_id'));

        $foreignKeys = $posts->getForeignKeys();
        $this->assertCount(1, $foreignKeys);

        $foreignKey = reset($foreignKeys);
        $this->assertSame('categories', $foreignKey->getForeignTableName());
        $this->assertSame(['category_id'], $foreignKey->getLocalColumns());
        $this->assertSame(['id'], $foreignKey->getForeignColumns());
        $this->assertSame('CASCADE', $foreignKey->onDelete());
    }

    /** @test */
    public function it_adds_many_to_one_foreign_key_columns_right_after_the_id_regardless_of_xml_order()
    {
        $schema = (new SchemaBuilder())->build($this->readEntities(), new SchemaConfig());

        // Posts.dcm.xml declares <field name="title"> before the <many-to-one field="category">, but
        // category_id must still land right after id, not trail after title.
        $columnNames = array_map(fn ($column) => $column->getName(), $schema->getTable('posts')->getColumns());
        $this->assertSame(['id', 'category_id', 'title'], array_values($columnNames));
    }

    /** @test */
    public function it_builds_the_pivot_table_of_a_many_to_many_relation()
    {
        $schema = (new SchemaBuilder())->build($this->readEntities(), new SchemaConfig());

        $pivot = $schema->getTable('posts_tags');
        $this->assertTrue($pivot->hasColumn('post_id'));
        $this->assertTrue($pivot->hasColumn('tag_id'));
        $this->assertCount(2, $pivot->getForeignKeys());

        $primaryKey = $pivot->getPrimaryKey();
        $this->assertSame(['post_id', 'tag_id'], $primaryKey->getColumns());
    }

    /** @test */
    public function it_applies_field_options_to_columns()
    {
        $schema = (new SchemaBuilder())->build($this->readEntities(), new SchemaConfig());

        $position = $schema->getTable('categories')->getColumn('position');
        $this->assertTrue($position->getUnsigned());

        $active = $schema->getTable('categories')->getColumn('active');
        $this->assertSame('1', $active->getDefault());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function readEntities(): array
    {
        return (new XmlMappingReader())->read(__DIR__ . '/../fixtures/xml-mappings');
    }
}
