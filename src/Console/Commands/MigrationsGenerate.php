<?php

namespace Itemvirtual\LaravelDoctrine\Console\Commands;

use DateTime;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;


class MigrationsGenerate extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'doctrine:migrations-generate
                            {path=tests/database/migrations : Path where migrations will be stored}
                            {--R|remove : Remove previous generated migration files}
                            {--O|output : View migrations package console output}
                            {--S|single-file=false : Generate all migrations in a single file}
                            {--M|merge-foreign-keys : Merge each table\'s foreign keys migration into its own create-table migration file, instead of a separate "add_foreign_keys_to_..." file. Tables are reordered so referenced tables are always created first; a genuine circular dependency between tables is left in a separate migration. Has no effect together with --single-file}
                            {--date= : Migrations will be created with the given date/time, in a format supported by Carbon::parse. Defaults to today at midnight, so migration filenames stay clean and easy to order}
                            {--T|tables= : A list of Tables or Views you wish to Generate Migrations separated by comma: users,products,labels}
                            {--I|ignore= : A list of Tables or Views you wish to ignore, separated by comma: users,products,labels}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate laravel migration files from database';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $path = trim($this->argument('path'));
        $output = $this->option('output');
        $remove = $this->option('remove');
        $tables = $this->option('tables');
        $ignore = $this->option('ignore');
        $singleFile = $this->option('single-file');
        $singleFile = filter_var($singleFile, FILTER_VALIDATE_BOOLEAN);
        $mergeForeignKeys = $this->option('merge-foreign-keys');

        if ($tables) {
            $params['--tables'] = $tables;
        }

        if ($ignore) {
            $params['--ignore'] = $ignore;
        }

        if ($singleFile) {
            $params['--squash'] = $singleFile;
        }

        $params['--path'] = $path;
        $params['--date'] = $this->option('date') ?: now()->startOfDay()->format('Y-m-d H:i:s');
        $params['--no-interaction'] = true;

        [$registerMigrations, $truncateMigrationsTable] = $this->askMigrationsTableQuestions();

        $filesystem = new Filesystem();

        // Check that path is writable
        $filesystem->ensureDirectoryExists(base_path($path));

        if (!$filesystem->isWritable(base_path($path))) {
            $this->error('The destination path <comment>' . $path . '</comment> is not writable');
            return 0;
        }

        // Remove previous files
        if ($remove) {
            $filesystem->deleteDirectory(base_path($path), true);
        }

        $filesBefore = $filesystem->files(base_path($path));

        // Run the package command with custom params
        if ($output) {
            $this->call('migrate:generate', $params);
        } else {
            $this->callSilent('migrate:generate', $params);
        }

        if ($mergeForeignKeys) {
            if ($singleFile) {
                $this->warn('The --merge-foreign-keys option has no effect together with --single-file, everything is already in one file.');
            } else {
                $this->mergeForeignKeysIntoTableMigrations($filesystem, $this->newFiles($filesystem, $path, $filesBefore));
            }
        }

        $generatedFiles = $this->newFiles($filesystem, $path, $filesBefore);

        if ($registerMigrations && !empty($generatedFiles)) {
            $this->registerGeneratedMigrations($generatedFiles, $truncateMigrationsTable);
        }

        $this->info('Migrations files generated in <comment>' . $path . '</comment>');

        return 0;
    }

    /**
     * Asks, up front, whether the generated migrations should be registered in the migrations table, and
     * whether that table should be truncated first if it already has entries — so the whole generation and
     * merge step afterward runs uninterrupted, instead of stopping mid-output to ask.
     *
     * @return array{0: bool, 1: bool} Whether to register, and whether to truncate first.
     */
    private function askMigrationsTableQuestions(): array
    {
        if (!$this->confirm('Register these migrations in the migrations table, without running them?')) {
            return [false, false];
        }

        /** @var \Illuminate\Database\Migrations\DatabaseMigrationRepository $repository */
        $repository = $this->laravel['migration.repository'];

        $truncate = $repository->repositoryExists()
            && !empty($repository->getRan())
            && $this->confirm('The migrations table already has entries. Truncate it first?');

        return [true, $truncate];
    }

    /**
     * The migration files generated by this run (and only those: anything already in the destination
     * directory before this run, such as a previous batch or Laravel's own default migrations, is excluded).
     *
     * @param string[] $filesBefore
     * @return string[] Absolute paths.
     */
    private function newFiles(Filesystem $filesystem, string $path, array $filesBefore): array
    {
        return array_values(array_diff(
            array_map(fn($file) => $file->getPathname(), $filesystem->files(base_path($path))),
            array_map(fn($file) => $file->getPathname(), $filesBefore),
        ));
    }

    /**
     * Registers each generated migration in Laravel's migrations table without actually running it: the
     * database already has this exact schema, these files only describe it for tooling like
     * migrate:status/migrate:rollback. Each file gets its own, incremental batch number, in filename order, so
     * they can be rolled back one at a time instead of all at once.
     *
     * @param string[] $files Absolute paths.
     */
    private function registerGeneratedMigrations(array $files, bool $truncate)
    {
        /** @var \Illuminate\Database\Migrations\DatabaseMigrationRepository $repository */
        $repository = $this->laravel['migration.repository'];

        if (!$repository->repositoryExists()) {
            $repository->createRepository();
        } elseif ($truncate) {
            $repository->getConnection()->table($this->migrationsTableName())->truncate();
        }

        $names = array_map(fn($file) => basename($file, '.php'), $files);
        sort($names);

        $batch = $repository->getNextBatchNumber();

        foreach ($names as $name) {
            $repository->log($name, $batch);
            $batch++;
        }

        $this->info(count($names) . ' migration(s) registered in the migrations table, one batch each.');
    }

    /**
     * The same table name Laravel's own MigrationServiceProvider resolves for the migration repository.
     */
    private function migrationsTableName(): string
    {
        $migrations = $this->laravel['config']['database.migrations'];

        return is_array($migrations) ? ($migrations['table'] ?? 'migrations') : $migrations;
    }

    /**
     * Kitloong's generator writes each table's foreign keys into a separate
     * "..._add_foreign_keys_to_{table}_table.php" migration, run only after every table has been created (so
     * referenced tables always exist by then). That ordering is exactly why it's split in two: every table
     * migration shares the same timestamp, so if a foreign key were inlined into Schema::create() as-is,
     * Laravel would run the migrations in plain filename order, which doesn't respect which table has to exist
     * first for the foreign key to be valid.
     *
     * This folds each foreign-keys migration back into its own table's create-migration, but first works out a
     * safe creation order between the tables involved (referenced table before the table that references it) and
     * re-timestamps the table migrations to match it. A foreign key whose target can't be scheduled first because
     * of a circular dependency between tables is left behind in a (trimmed) foreign-keys migration, timestamped
     * to run after every table in this batch.
     *
     * Every table migration in this batch gets a slot in that order and a sequential timestamp, Laravel style,
     * even the ones with no foreign keys of their own and nothing referencing them.
     *
     * @param string[] $newFiles Absolute paths of the migration files generated by this run (and only those:
     *                           anything already in the destination directory before this run, such as a
     *                           previous batch or Laravel's own default migrations, is left untouched).
     */
    private function mergeForeignKeysIntoTableMigrations(Filesystem $filesystem, array $newFiles)
    {
        $foreignKeyFiles = $this->matching($newFiles, '/_add_foreign_keys_to_.+_table\.php$/');
        $tables = $this->collectForeignKeyTables($filesystem, $newFiles, $foreignKeyFiles);

        // Every other table migration in this batch also needs a slot in the creation order and a sequential
        // timestamp, even with no foreign keys of its own and nothing in this batch referencing it.
        $this->addAllTables($newFiles, $tables);

        if (empty($tables)) {
            return;
        }

        $baseDate = $this->migrationDate(reset($tables)['tableFile']);
        [$order, $deferred] = $this->resolveMergeOrder($tables);

        foreach ($order as $position => $table) {
            if ($tables[$table]['foreignKeyFile'] !== null) {
                $this->mergeTable($filesystem, $tables[$table], $table, $deferred[$table] ?? []);
            }

            $tableFile = $this->renameWithOffset($filesystem, $tables[$table]['tableFile'], $baseDate, $position);
            $tables[$table]['tableFile'] = $tableFile;
        }

        // Anything left in a foreign-keys migration (fully or partially deferred, or one we couldn't parse)
        // must run after every table in this batch has been created.
        foreach ($tables as $data) {
            if ($data['foreignKeyFile'] !== null && $filesystem->exists($data['foreignKeyFile'])) {
                $this->renameWithOffset($filesystem, $data['foreignKeyFile'], $baseDate, count($order));
            }
        }
    }

    /**
     * The files (out of $files) whose basename matches $pattern.
     *
     * @param string[] $files
     * @return string[]
     */
    private function matching(array $files, string $pattern): array
    {
        return array_values(array_filter($files, fn(string $file) => preg_match($pattern, basename($file)) === 1));
    }

    /**
     * Reads every "..._add_foreign_keys_to_{table}_table.php" file (from this batch) and pairs it with its
     * table's create-migration and the list of individual foreign key statements it contains.
     *
     * @param string[] $newFiles
     * @param string[] $foreignKeyFiles
     * @return array<string, array{tableFile: string, foreignKeyFile: string|null, statements: array<int, array{line: string, target: string|null}>}>
     */
    private function collectForeignKeyTables(Filesystem $filesystem, array $newFiles, array $foreignKeyFiles)
    {
        $tables = [];

        foreach ($foreignKeyFiles as $foreignKeyFile) {
            if (!preg_match('/_add_foreign_keys_to_(.+)_table\.php$/', basename($foreignKeyFile), $matches)) {
                continue;
            }

            $table = $matches[1];
            $tableFiles = $this->matching($newFiles, '/_create_' . preg_quote($table, '/') . '_table\.php$/');

            if (count($tableFiles) !== 1) {
                $this->warn('Could not merge foreign keys for <comment>' . $table . '</comment>: expected exactly one create-table migration, found ' . count($tableFiles) . '. Left as a separate file.');
                continue;
            }

            $statements = $this->extractForeignKeyStatements($filesystem->get($foreignKeyFile));

            if ($statements === null) {
                $this->warn('Could not read the foreign keys in <comment>' . basename($foreignKeyFile) . '</comment>, left as a separate file.');
                continue;
            }

            $tables[$table] = [
                'tableFile' => $tableFiles[0],
                'foreignKeyFile' => $foreignKeyFile,
                'statements' => $statements,
            ];
        }

        return $tables;
    }

    /**
     * Adds an entry (with no foreign keys of its own) for every "..._create_{table}_table.php" migration in
     * this batch that isn't in $tables yet, so it gets a slot in the creation order too.
     *
     * @param string[] $newFiles
     * @param array<string, array{tableFile: string, foreignKeyFile: string|null, statements: array<int, array{line: string, target: string|null}>}> $tables
     */
    private function addAllTables(array $newFiles, array &$tables)
    {
        foreach ($this->matching($newFiles, '/_create_.+_table\.php$/') as $tableFile) {
            if (!preg_match('/_create_(.+)_table\.php$/', basename($tableFile), $matches)) {
                continue;
            }

            $table = $matches[1];

            if (isset($tables[$table])) {
                continue;
            }

            $tables[$table] = [
                'tableFile' => $tableFile,
                'foreignKeyFile' => null,
                'statements' => [],
            ];
        }
    }

    /**
     * Works out a table creation order where every table is scheduled after the tables its foreign keys point
     * to (Kahn's algorithm). When a circular dependency leaves every remaining table with at least one
     * unresolved reference, the table with the fewest of them is forced through: the statements pointing at
     * not-yet-scheduled tables are marked as deferred, breaking the cycle.
     *
     * @param array<string, array{statements: array<int, array{line: string, target: string|null}>}> $tables
     * @return array{0: string[], 1: array<string, string[]>} The creation order, and per-table deferred
     *                                                         statement lines.
     */
    private function resolveMergeOrder(array $tables)
    {
        $remaining = array_keys($tables);
        sort($remaining);

        $order = [];
        $deferred = [];

        while (!empty($remaining)) {
            $next = null;

            foreach ($remaining as $table) {
                if (empty($this->unresolvedTargets($tables[$table]['statements'], $table, $remaining))) {
                    $next = $table;
                    break;
                }
            }

            if ($next === null) {
                $fewestBlockers = null;

                foreach ($remaining as $table) {
                    $blockers = $this->unresolvedTargets($tables[$table]['statements'], $table, $remaining);

                    if ($fewestBlockers === null || count($blockers) < count($fewestBlockers)) {
                        $next = $table;
                        $fewestBlockers = $blockers;
                    }
                }

                foreach ($tables[$next]['statements'] as $statement) {
                    if ($statement['target'] !== null && in_array($statement['target'], $fewestBlockers, true)) {
                        $deferred[$next][] = $statement['line'];
                    }
                }
            }

            $order[] = $next;
            $remaining = array_values(array_diff($remaining, [$next]));
        }

        return [$order, $deferred];
    }

    /**
     * The referenced tables of $table's foreign keys that are still waiting to be scheduled. A self-reference,
     * or a reference to a table outside this batch (assumed to already exist), is never a blocker.
     *
     * @param array<int, array{line: string, target: string|null}> $statements
     * @param string[] $remaining
     * @return string[]
     */
    private function unresolvedTargets(array $statements, string $table, array $remaining)
    {
        $targets = [];

        foreach ($statements as $statement) {
            $target = $statement['target'];

            if ($target === null || $target === $table) {
                continue;
            }

            if (in_array($target, $remaining, true)) {
                $targets[] = $target;
            }
        }

        return array_values(array_unique($targets));
    }

    /**
     * Merges every non-deferred foreign key statement of $table into its create-table migration, and rewrites
     * (or deletes) the foreign-keys migration to hold only what's left.
     *
     * @param array{tableFile: string, foreignKeyFile: string, statements: array<int, array{line: string, target: string|null}>} $data
     * @param string[] $deferredLines
     */
    private function mergeTable(Filesystem $filesystem, array $data, string $table, array $deferredLines)
    {
        $toInline = array_values(array_filter(
            $data['statements'],
            fn(array $statement) => !in_array($statement['line'], $deferredLines, true),
        ));

        $merged = empty($toInline) ? null : $this->insertForeignKeyStatements(
            $filesystem->get($data['tableFile']),
            implode("\n", array_column($toInline, 'line')),
        );

        if (!empty($toInline) && $merged === null) {
            $this->warn('Could not merge foreign keys into <comment>' . basename($data['tableFile']) . '</comment>, left as a separate file.');
            $toInline = [];
        }

        if ($merged !== null) {
            $filesystem->put($data['tableFile'], $merged);
        }

        $remaining = empty($toInline) ? $data['statements'] : array_values(array_filter(
            $data['statements'],
            fn(array $statement) => in_array($statement['line'], $deferredLines, true),
        ));

        if (empty($remaining)) {
            $filesystem->delete($data['foreignKeyFile']);
            $this->line('Merged foreign keys of <comment>' . $table . '</comment> into <comment>' . basename($data['tableFile']) . '</comment>');
            return;
        }

        $rewritten = $this->replaceForeignKeyStatements(
            $filesystem->get($data['foreignKeyFile']),
            implode("\n", array_column($remaining, 'line')),
        );

        if ($rewritten !== null) {
            $filesystem->put($data['foreignKeyFile'], $rewritten);
        }

        $mergedCount = count($data['statements']) - count($remaining);

        if ($mergedCount > 0) {
            $this->line('Merged ' . $mergedCount . ' of ' . count($data['statements']) . ' foreign key(s) of <comment>' . $table . '</comment> into its table migration; the rest reference tables created later (circular dependency) and stay in a separate migration.');
        } else {
            $this->line('Left foreign keys of <comment>' . $table . '</comment> in a separate migration, its referenced table(s) are created later.');
        }
    }

    /**
     * Pulls the individual $table->foreign(...) statements out of a "..._add_foreign_keys_to_{table}_table.php"
     * migration's up() method, e.g.:
     *   Schema::table('posts', function (Blueprint $table) {
     *       $table->foreign(['category_id'], '...')->references(['id'])->on('categories')->onDelete('cascade');
     *   });
     *
     * @return array<int, array{line: string, target: string|null}>|null The statements in order (with the
     *         table each one references, if it could be determined), or null if the expected
     *         Schema::table(...) block was not found.
     */
    private function extractForeignKeyStatements(string $foreignKeyMigration)
    {
        $pattern = '/Schema::table\([\'"][^\'"]+[\'"],\s*function\s*\(Blueprint\s+\$table\)\s*\{\n(.*?)\n\s*\}\);/s';

        if (!preg_match($pattern, $foreignKeyMigration, $matches)) {
            return null;
        }

        $statements = [];

        foreach (explode("\n", rtrim($matches[1])) as $line) {
            if (trim($line) === '') {
                continue;
            }

            preg_match('/->on\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $line, $targetMatch);

            $statements[] = [
                'line' => $line,
                'target' => $targetMatch[1] ?? null,
            ];
        }

        return $statements;
    }

    /**
     * Inserts the given $table->foreign(...) statements right before the closing "});" of the table migration's
     * Schema::create(...) block, so the table ends up created with its foreign keys in a single migration.
     *
     * @return string|null The merged migration content, or null if the expected Schema::create(...) block was
     *                      not found.
     */
    private function insertForeignKeyStatements(string $tableMigration, string $foreignKeyStatements)
    {
        $pattern = '/(Schema::create\([\'"][^\'"]+[\'"],\s*function\s*\(Blueprint\s+\$table\)\s*\{\n.*?\n)(\s*)(\}\);)/s';

        if (!preg_match($pattern, $tableMigration)) {
            return null;
        }

        return preg_replace($pattern, '$1' . addcslashes($foreignKeyStatements, '\\$') . "\n" . '$2$3', $tableMigration, 1);
    }

    /**
     * Replaces the $table->foreign(...) statements of a "..._add_foreign_keys_to_{table}_table.php" migration
     * with the given (reduced) set, keeping everything else (namespaces, down(), ...) untouched.
     *
     * @return string|null The rewritten migration content, or null if the expected Schema::table(...) block
     *                      was not found.
     */
    private function replaceForeignKeyStatements(string $foreignKeyMigration, string $foreignKeyStatements)
    {
        $pattern = '/(Schema::table\([\'"][^\'"]+[\'"],\s*function\s*\(Blueprint\s+\$table\)\s*\{\n).*?(\n\s*)(\}\);)/s';

        if (!preg_match($pattern, $foreignKeyMigration)) {
            return null;
        }

        return preg_replace($pattern, '$1' . addcslashes($foreignKeyStatements, '\\$') . '$2$3', $foreignKeyMigration, 1);
    }

    /**
     * The DateTime encoded in a migration's "YYYY_MM_DD_HHMMSS_..." filename prefix.
     */
    private function migrationDate(string $file)
    {
        if (preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_/', basename($file), $matches)) {
            $date = DateTime::createFromFormat('Y_m_d_His', $matches[1]);

            if ($date !== false) {
                return $date;
            }
        }

        return new DateTime();
    }

    /**
     * Renames a migration file to $baseDate + $offsetSeconds, keeping its "..._{rest of the name}" suffix, so
     * that Laravel's filename-based migration ordering reflects the given offset.
     */
    private function renameWithOffset(Filesystem $filesystem, string $file, DateTime $baseDate, int $offsetSeconds)
    {
        if (!preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_(.+)$/', basename($file), $matches)) {
            return $file;
        }

        $newDate = (clone $baseDate)->modify('+' . $offsetSeconds . ' seconds');
        $newFile = dirname($file) . '/' . $newDate->format('Y_m_d_His') . '_' . $matches[1];

        if ($newFile !== $file) {
            $filesystem->move($file, $newFile);
        }

        return $newFile;
    }

}
