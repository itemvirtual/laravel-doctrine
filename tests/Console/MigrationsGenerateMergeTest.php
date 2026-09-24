<?php

namespace Itemvirtual\LaravelDoctrine\Tests\Console;

use Illuminate\Console\OutputStyle;
use Illuminate\Filesystem\Filesystem;
use Itemvirtual\LaravelDoctrine\Console\Commands\MigrationsGenerate;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Tests the --merge-foreign-keys post-processing in isolation: real files on a scratch directory, no database
 * and no kitloong migrate:generate call. The fixtures mirror kitloong's actual output format exactly (captured
 * from a real generation run) so the regexes that read/rewrite them are exercised for real.
 */
class MigrationsGenerateMergeTest extends TestCase
{
    private string $dir;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/laravel-doctrine-merge-test-' . uniqid();
        $this->filesystem = new Filesystem();
        $this->filesystem->ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        $this->filesystem->deleteDirectory($this->dir);

        parent::tearDown();
    }

    /** @test */
    public function it_merges_a_simple_dependency_and_creates_the_parent_first()
    {
        $files = [
            $this->writeFixture('2024_01_01_000000_create_parent_table.php', $this->createTableMigration('parent', $this->parentBody())),
            $this->writeFixture('2024_01_01_000000_create_child_table.php', $this->createTableMigration('child', $this->childBody())),
            $this->writeFixture('2024_01_01_000003_add_foreign_keys_to_child_table.php', $this->foreignKeysMigration('child', $this->childForeignKeyLine('parent'), $this->childDropForeignLine())),
        ];

        $this->invokeMerge($files);

        $names = $this->remainingFilenames();
        $this->assertSame(['create_parent_table', 'create_child_table'], $this->tableSuffixes($names, ['create_child_table', 'create_parent_table']));
        $this->assertLessThan($this->timestampOf('create_child_table', $names), $this->timestampOf('create_parent_table', $names));

        $childContent = $this->filesystem->get($this->dir . '/' . $this->fileMatching($names, 'create_child_table'));
        $this->assertStringContainsString("\$table->foreign(['parent_id'])->references(['id'])->on('parent')", $childContent);
    }

    /** @test */
    public function it_gives_every_table_a_sequential_timestamp_even_without_any_foreign_keys()
    {
        $files = [
            $this->writeFixture('2024_01_01_000000_create_a_lonely_table.php', $this->createTableMigration('a_lonely', $this->simpleBody())),
            $this->writeFixture('2024_01_01_000000_create_b_lonely_table.php', $this->createTableMigration('b_lonely', $this->simpleBody())),
            $this->writeFixture('2024_01_01_000000_create_c_lonely_table.php', $this->createTableMigration('c_lonely', $this->simpleBody())),
        ];

        $this->invokeMerge($files);

        $names = $this->remainingFilenames();
        $this->assertCount(3, $names);

        $a = $this->timestampOf('create_a_lonely_table', $names);
        $b = $this->timestampOf('create_b_lonely_table', $names);
        $c = $this->timestampOf('create_c_lonely_table', $names);

        $this->assertLessThan($b, $a);
        $this->assertLessThan($c, $b);
    }

    /** @test */
    public function it_breaks_a_circular_dependency_by_deferring_one_side()
    {
        $files = [
            $this->writeFixture('2024_01_01_000000_create_a_table.php', $this->createTableMigration('a', $this->simpleBody())),
            $this->writeFixture('2024_01_01_000000_create_b_table.php', $this->createTableMigration('b', $this->simpleBody())),
            $this->writeFixture('2024_01_01_000003_add_foreign_keys_to_a_table.php', $this->foreignKeysMigration('a', $this->foreignKeyLine('b_id', 'b'), $this->dropForeignLine('a_b_id_foreign'))),
            $this->writeFixture('2024_01_01_000003_add_foreign_keys_to_b_table.php', $this->foreignKeysMigration('b', $this->foreignKeyLine('a_id', 'a'), $this->dropForeignLine('b_a_id_foreign'))),
        ];

        $this->invokeMerge($files);

        $names = $this->remainingFilenames();
        $this->assertCount(3, $names);

        // Both tables get created, one foreign-keys migration is left over for the side that couldn't be
        // inlined without a forward reference, running only after both tables exist.
        $aCreate = $this->timestampOf('create_a_table', $names);
        $bCreate = $this->timestampOf('create_b_table', $names);
        $leftoverFile = $this->fileMatching($names, 'add_foreign_keys_to_a_table');

        $this->assertLessThan($bCreate, $aCreate);
        $this->assertGreaterThan($bCreate, $this->timestampOf('add_foreign_keys_to_a_table', $names));

        $bContent = $this->filesystem->get($this->dir . '/' . $this->fileMatching($names, 'create_b_table'));
        $this->assertStringContainsString("->on('a')", $bContent);

        $aContent = $this->filesystem->get($this->dir . '/' . $this->fileMatching($names, 'create_a_table'));
        $this->assertStringNotContainsString('->foreign(', $aContent);

        $leftoverContent = $this->filesystem->get($this->dir . '/' . $leftoverFile);
        $this->assertStringContainsString("->on('b')", $leftoverContent);
    }

    /** @test */
    public function a_reference_to_a_table_outside_this_batch_is_never_a_blocker()
    {
        $files = [
            $this->writeFixture('2024_01_01_000000_create_child_table.php', $this->createTableMigration('child', $this->childBody())),
            $this->writeFixture('2024_01_01_000003_add_foreign_keys_to_child_table.php', $this->foreignKeysMigration('child', $this->childForeignKeyLine('users'), $this->childDropForeignLine())),
        ];

        $this->invokeMerge($files);

        $names = $this->remainingFilenames();
        $this->assertCount(1, $names);

        $content = $this->filesystem->get($this->dir . '/' . $this->fileMatching($names, 'create_child_table'));
        $this->assertStringContainsString("->on('users')", $content);
    }

    /** @test */
    public function it_leaves_files_already_in_the_directory_untouched()
    {
        $decoyName = '2020_01_01_000000_create_users_table.php';
        $decoyContent = $this->createTableMigration('users', $this->simpleBody());
        $this->writeFixture($decoyName, $decoyContent);

        $newFiles = [
            $this->writeFixture('2024_01_01_000000_create_parent_table.php', $this->createTableMigration('parent', $this->parentBody())),
            $this->writeFixture('2024_01_01_000000_create_child_table.php', $this->createTableMigration('child', $this->childBody())),
            $this->writeFixture('2024_01_01_000003_add_foreign_keys_to_child_table.php', $this->foreignKeysMigration('child', $this->childForeignKeyLine('parent'), $this->childDropForeignLine())),
        ];

        // The decoy is deliberately left out of $newFiles, simulating a file that already existed in the
        // destination directory (e.g. Laravel's own default migrations) before this run.
        $this->invokeMerge($newFiles);

        $this->assertTrue($this->filesystem->exists($this->dir . '/' . $decoyName));
        $this->assertSame($decoyContent, $this->filesystem->get($this->dir . '/' . $decoyName));
        $this->assertCount(3, $this->remainingFilenames());
    }

    private function invokeMerge(array $files): void
    {
        $command = new MigrationsGenerate();
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput()));

        $method = new ReflectionMethod(MigrationsGenerate::class, 'mergeForeignKeysIntoTableMigrations');
        $method->setAccessible(true);
        $method->invoke($command, $this->filesystem, $files);
    }

    private function writeFixture(string $filename, string $content): string
    {
        $path = $this->dir . '/' . $filename;
        $this->filesystem->put($path, $content);

        return $path;
    }

    /**
     * @return string[]
     */
    private function remainingFilenames(): array
    {
        return array_map(fn ($file) => $file->getFilename(), $this->filesystem->files($this->dir));
    }

    /**
     * @param string[] $names
     */
    private function fileMatching(array $names, string $needle): string
    {
        foreach ($names as $name) {
            if (str_contains($name, $needle)) {
                return $name;
            }
        }

        $this->fail('No file matching "' . $needle . '" found among: ' . implode(', ', $names));
    }

    /**
     * @param string[] $names
     */
    private function timestampOf(string $needle, array $names): string
    {
        $file = $this->fileMatching($names, $needle);

        preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_/', $file, $matches);

        return $matches[1];
    }

    /**
     * @param string[] $names
     * @param string[] $needles
     * @return string[] $needles, sorted by each match's timestamp.
     */
    private function tableSuffixes(array $names, array $needles): array
    {
        usort($needles, fn ($a, $b) => $this->timestampOf($a, $names) <=> $this->timestampOf($b, $names));

        return $needles;
    }

    private function createTableMigration(string $tableName, string $body): string
    {
        $template = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('__TABLE__', function (Blueprint $table) {
            __BODY__
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('__TABLE__');
    }
};

PHP;

        return str_replace(['__TABLE__', '__BODY__'], [$tableName, $body], $template);
    }

    private function foreignKeysMigration(string $tableName, string $upLine, string $downLine): string
    {
        $template = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('__TABLE__', function (Blueprint $table) {
            __UP__
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('__TABLE__', function (Blueprint $table) {
            __DOWN__
        });
    }
};

PHP;

        return str_replace(['__TABLE__', '__UP__', '__DOWN__'], [$tableName, $upLine, $downLine], $template);
    }

    private function parentBody(): string
    {
        return <<<'PHP'
$table->bigIncrements('id');
            $table->string('name');
PHP;
    }

    private function simpleBody(): string
    {
        return <<<'PHP'
$table->bigIncrements('id');
            $table->string('label');
PHP;
    }

    private function childBody(): string
    {
        return <<<'PHP'
$table->bigIncrements('id');
            $table->unsignedBigInteger('parent_id')->index('child_parent_id_foreign');
            $table->string('title');
PHP;
    }

    private function childForeignKeyLine(string $target): string
    {
        return $this->foreignKeyLine('parent_id', $target);
    }

    private function childDropForeignLine(): string
    {
        return $this->dropForeignLine('child_parent_id_foreign');
    }

    private function foreignKeyLine(string $column, string $target): string
    {
        return "\$table->foreign(['{$column}'])->references(['id'])->on('{$target}')->onUpdate('restrict')->onDelete('cascade');";
    }

    private function dropForeignLine(string $constraint): string
    {
        return "\$table->dropForeign('{$constraint}');";
    }
}
