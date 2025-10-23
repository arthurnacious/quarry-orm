<?php

namespace Quarry\Commands;

use Quarry\Database\ConnectionScope;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Quarry\Quarry;
use Quarry\Schema\Table;
use Quarry\Schema\SQLGenerator;
use RuntimeException;

class MakeSchemaCommand extends Command
{
    protected static $defaultName = 'make:schema';
    protected static $defaultDescription = 'Generate migration files from schema definition';

    protected function configure(): void
    {
        $this
            ->addOption(
                'schema',
                's',
                InputOption::VALUE_REQUIRED,
                'Path to schema definition file',
                'db/Schema.php'
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Output directory for migrations',
                'database/migrations'
            )
            ->addOption(
                'connection',
                'c',
                InputOption::VALUE_OPTIONAL,
                'Database connection to use for SQL generation',
                'primary'
            )
            ->addOption(
                'create-sample',
                null,
                InputOption::VALUE_NONE,
                'Create a sample schema file and exit'
            )
            ->addOption(
                'generate-enums',
                null,
                InputOption::VALUE_NONE,
                'Generate missing enum classes automatically'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('🏗 Quarry Schema Generator');

        $schemaPath = $input->getOption('schema');
        $outputPath = $input->getOption('output');
        $connectionName = $input->getOption('connection'); // CHANGED: from $connectionPool
        $createSample = $input->getOption('create-sample');
        $generateEnums = $input->getOption('generate-enums');

        try {
            // Handle --create-sample flag
            if ($createSample) {
                $this->createSampleSchema($schemaPath, $io);
                $io->success("Sample schema created at: {$schemaPath}");
                $io->text('Edit this file to define your database schema, then run:');
                $io->text("<comment>php bin/quarry make:schema --schema={$schemaPath}</comment>");
                return Command::SUCCESS;
            }

            // Generate missing enum classes if requested
            if ($generateEnums) {
                $this->generateEnumClasses($io);
            }

            // Load schema definition
            $tables = $this->loadSchemaDefinition($schemaPath, $io, $generateEnums);

            if (empty($tables)) {
                return Command::SUCCESS;
            }

            // Ensure output directory exists
            $this->ensureOutputDirectory($outputPath, $io);

            // Generate migrations using ConnectionScope for safety
            $generatedFiles = $this->generateMigrations($tables, $connectionName, $outputPath, $io);

            $io->newLine();
            $io->success(sprintf(
                '✅ Generated %d migration file(s) from schema',
                count($generatedFiles)
            ));

            $io->text([
                'Next steps:',
                'Run <comment>php bin/quarry migrate</comment> to execute the migrations',
                'Review the generated SQL files in: <comment>' . $outputPath . '</comment>'
            ]);

            return Command::SUCCESS;
        } catch (RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }

    private function loadSchemaDefinition(string $schemaPath, SymfonyStyle $io, bool $generateEnums = false): array
    {
        if (!file_exists($schemaPath)) {
            $io->error("Schema file not found: {$schemaPath}");
            $io->text([
                'Create a schema file or use the --create-sample option:',
                "<comment>php bin/quarry make:schema --create-sample --schema={$schemaPath}</comment>"
            ]);
            return [];
        }

        $io->text("<fg=blue>📖 Loading schema:</> {$schemaPath}");

        try {
            $tables = require $schemaPath;
        } catch (\Error $e) {
            // Handle missing class errors
            if (str_contains($e->getMessage(), 'not found') && $generateEnums) {
                $io->text("<fg=yellow>⚠️ Missing classes detected, generating enum classes...</>");
                $this->generateEnumClasses($io);

                // Try loading again
                $tables = require $schemaPath;
            } else {
                $io->error("Failed to load schema file: {$e->getMessage()}");
                $io->text("Try running with: <comment>--generate-enums</comment>");
                return [];
            }
        } catch (\Throwable $e) {
            $io->error("Failed to load schema file: {$e->getMessage()}");
            return [];
        }

        if (!is_array($tables)) {
            $io->error("Schema file must return an array of Table objects");
            return [];
        }

        // Filter out non-Table objects
        $validTables = array_filter($tables, fn($table) => $table instanceof Table);

        if (empty($validTables)) {
            $io->warning('No valid Table objects found in schema file');
            $io->text('Make sure your schema file returns an array of Table objects.');
            return [];
        }

        $invalidCount = count($tables) - count($validTables);
        if ($invalidCount > 0) {
            $io->text("<fg=yellow>⚠️ Skipped {$invalidCount} non-Table object(s)</>");
        }

        $io->text(sprintf("<fg=green>✅ Loaded %d table(s)</>", count($validTables)));

        return $validTables;
    }

    private function generateEnumClasses(SymfonyStyle $io): void
    {
        $enumsDir = __DIR__ . '/../Schema';
        $enumsFile = $enumsDir . '/Enums.php';

        if (!is_dir($enumsDir)) {
            mkdir($enumsDir, 0755, true);
        }

        $enumsContent = <<<'PHP'
<?php

namespace Quarry\Schema;

enum UserRole: string
{
    case Admin = 'admin';
    case User = 'user';
    case Moderator = 'moderator';
}

enum UserStatus: string
{
    case Active = 'active';
    case Pending = 'pending';
    case Blocked = 'blocked';
}

enum PostStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
PHP;

        file_put_contents($enumsFile, $enumsContent);
        $io->text("<fg=green>✅ Generated enum classes:</> {$enumsFile}");
    }

    private function createSampleSchema(string $schemaPath, SymfonyStyle $io): void
    {
        $schemaDir = dirname($schemaPath);
        if (!is_dir($schemaDir)) {
            mkdir($schemaDir, 0755, true);
            $io->text("<fg=green>✅ Created directory:</> {$schemaDir}");
        }

        // Create a simpler schema that doesn't depend on enums
        $sampleSchema = <<<'PHP'
<?php

use Quarry\Schema\Table;
use Quarry\Schema\Column;

return [
    new Table(
        name: 'users',
        columns: [
            new Column('id', 'id', primary: true, autoIncrement: true),
            new Column('username', 'varchar', size: 50, unique: true),
            new Column('email', 'varchar', size: 255, unique: true),
            new Column('password', 'char', size: 60),
            new Column('role', 'varchar', size: 20, default: 'user'),
            new Column('status', 'varchar', size: 20, default: 'active'),
            new Column('bio', 'text', nullable: true),
            new Column('created_at', 'datetime'),
            new Column('updated_at', 'datetime'),
        ],
        indexes: [
            'idx_user_email' => ['email'],
            'idx_user_status' => ['status'],
        ]
    ),

    new Table(
        name: 'posts',
        columns: [
            new Column('id', 'id', primary: true, autoIncrement: true),
            new Column('title', 'varchar', size: 255),
            new Column('slug', 'varchar', size: 100, unique: true),
            new Column('content', 'text'),
            new Column('status', 'varchar', size: 20, default: 'draft'),
            new Column('author_id', 'integer'),
            new Column('published_at', 'datetime', nullable: true),
            new Column('created_at', 'datetime'),
            new Column('updated_at', 'datetime'),
        ],
        indexes: [
            'idx_post_slug' => ['slug'],
            'idx_post_author' => ['author_id'],
            'idx_post_status' => ['status'],
        ]
    ),
];
PHP;

        file_put_contents($schemaPath, $sampleSchema);
        $io->text("<fg=green>✅ Created sample schema:</> {$schemaPath}");
    }

    private function ensureOutputDirectory(string $outputPath, SymfonyStyle $io): void
    {
        if (!is_dir($outputPath)) {
            mkdir($outputPath, 0755, true);
            $io->text("<fg=green>✅ Created output directory:</> {$outputPath}");
        }
    }

    private function generateMigrations(array $tables, string $connectionName, string $outputPath, SymfonyStyle $io): array
    {
        $timestamp = date('YmdHis');

        // Check if connection exists, fallback to SQLite if not
        if (!Quarry::hasConnection($connectionName)) {
            $io->text("<fg=yellow>⚠️ Connection '{$connectionName}' not found, using SQLite for SQL generation</>");
            $pdo = new \PDO('sqlite::memory:');
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            // Generate migrations without connection-specific features
            $sqlGenerator = new SQLGenerator($pdo);
            return $this->generateMigrationsWithConnection($tables, $sqlGenerator, $outputPath, $io, $timestamp);
        }

        // Use ConnectionScope for safe connection handling with actual connection
        $pool = Quarry::getConnectionPool($connectionName);
        $scope = new ConnectionScope($pool); // FIXED: Now uses correct namespace

        try {
            $connection = $scope->getConnection();
            $sqlGenerator = new SQLGenerator($connection);
            return $this->generateMigrationsWithConnection($tables, $sqlGenerator, $outputPath, $io, $timestamp);
        } finally {
            $scope->release();
        }
    }

    private function generateMigrationsWithConnection(array $tables, SQLGenerator $sqlGenerator, string $outputPath, SymfonyStyle $io, string $timestamp): array
    {
        $generatedFiles = [];

        foreach ($tables as $index => $table) {
            $io->text("<fg=blue>🔨 Generating:</> {$table->name}");

            try {
                $sql = $sqlGenerator->generateCreateTable($table);
                $filename = sprintf('%s_%03d_create_%s_table.sql', $timestamp, $index + 1, $table->name);
                $filepath = $outputPath . '/' . $filename;

                $migrationContent = $this->formatMigrationFile($sql, $table->name);
                file_put_contents($filepath, $migrationContent);

                $generatedFiles[] = $filename;
                $io->text("<fg=green>✅ Generated:</> {$filename}");
            } catch (\Exception $e) {
                $io->text("<fg=red>❌ Failed to generate {$table->name}:</> {$e->getMessage()}");
            }
        }

        return $generatedFiles;
    }

    private function formatMigrationFile(string $sql, string $tableName): string
    {
        return <<<SQL
-- Quarry Migration
-- Table: {$tableName}
-- Generated: {$this->getCurrentTimestamp()}
-- 
-- !!! AUTO-GENERATED FILE - DO NOT EDIT MANUALLY !!!
-- 
-- This file was automatically generated by Quarry ORM.
-- Any manual changes may be overwritten.

{$sql}
SQL;
    }

    private function getCurrentTimestamp(): string
    {
        return date('Y-m-d H:i:s');
    }
}
