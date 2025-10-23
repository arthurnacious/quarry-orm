<?php

namespace Quarry\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Quarry\Quarry;
use Quarry\Database\SyncPool;
use Quarry\Database\ConnectionScope;
use RuntimeException;

class MigrateCommand extends Command
{
    protected static $defaultName = 'migrate';
    protected static $defaultDescription = 'Run database migrations';

    protected function configure(): void
    {
        $this
            ->addOption(
                'path',
                'p',
                InputOption::VALUE_REQUIRED,
                'Path to migrations directory',
                'database/migrations'
            )
            ->addOption(
                'connection',
                'c',
                InputOption::VALUE_OPTIONAL,
                'Database connection to use',
                'primary'
            )
            ->addOption(
                'database-url',
                'd',
                InputOption::VALUE_OPTIONAL,
                'Database URL (overrides config)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('🚀 Quarry Database Migrations');

        $migrationsPath = $input->getOption('path');
        $connectionName = $input->getOption('connection');
        $databaseUrl = $input->getOption('database-url');

        try {
            // Ensure the requested connection exists
            $this->ensureConnectionExists($connectionName, $databaseUrl, $io);

            $this->ensureMigrationsTable($connectionName, $io);

            $pendingMigrations = $this->getPendingMigrations($migrationsPath, $connectionName, $io);

            if (empty($pendingMigrations)) {
                $io->success('✅ No pending migrations - database is up to date!');
                return Command::SUCCESS;
            }

            $io->section('📦 Pending Migrations');
            $io->listing(array_keys($pendingMigrations));

            if (!$io->confirm('Run these migrations?', true)) {
                $io->warning('Migration cancelled');
                return Command::SUCCESS;
            }

            $results = $this->runMigrations($pendingMigrations, $connectionName, $io);

            $io->newLine();

            if ($results['failed'] > 0) {
                $io->error(sprintf('Completed with %d error(s)', $results['failed']));
                return Command::FAILURE;
            }

            $io->success(sprintf(
                '✅ Successfully ran %d migration(s) on connection "%s"',
                $results['success'],
                $connectionName
            ));

            return Command::SUCCESS;
        } catch (RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }

    private function ensureConnectionExists(string $connectionName, ?string $databaseUrl, SymfonyStyle $io): void
    {
        if (Quarry::hasConnection($connectionName)) {
            return;
        }

        $io->text("<fg=yellow>⚠️</> Connection '{$connectionName}' not found in configuration");

        // Create a default connection
        if ($databaseUrl) {
            $io->text("Using provided database URL: {$databaseUrl}");
        } else {
            // Default to SQLite in current directory
            $databaseUrl = 'sqlite:///' . getcwd() . '/database.sqlite';
            $io->text("Using default SQLite database: {$databaseUrl}");
        }

        $pool = new SyncPool([
            'max_pool_size' => 5,
            'max_idle' => 3,
            'idle_timeout' => 30,
            'connection_config' => [
                'database_url' => $databaseUrl
            ]
        ]);

        Quarry::registerConnection($connectionName, $pool);
        Quarry::setDefaultConnection($connectionName);

        $io->text("<fg=green>✅</> Created connection '{$connectionName}'");
    }

    private function ensureMigrationsTable(string $connectionName, SymfonyStyle $io): void
    {
        $pool = Quarry::getConnectionPool($connectionName);
        $scope = new ConnectionScope($pool);

        try {
            $connection = $scope->getConnection();
            $migrationsTable = $this->getMigrationsTableName($connectionName);

            $connection->exec("
                CREATE TABLE IF NOT EXISTS {$migrationsTable} (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    migration VARCHAR(255) NOT NULL UNIQUE,
                    batch INTEGER NOT NULL,
                    executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $io->text("<fg=green>✅</> Migrations table '{$migrationsTable}' ready");
        } catch (\PDOException $e) {
            throw new RuntimeException('Failed to create migrations table: ' . $e->getMessage());
        }
    }

    private function getMigrationsTableName(string $connectionName): string
    {
        return "quarry_migrations_{$connectionName}";
    }

    private function getPendingMigrations(string $migrationsPath, string $connectionName, SymfonyStyle $io): array
    {
        if (!is_dir($migrationsPath)) {
            throw new RuntimeException("Migrations directory not found: {$migrationsPath}");
        }

        $migrationFiles = glob($migrationsPath . '/*.sql');
        sort($migrationFiles);

        if (empty($migrationFiles)) {
            $io->warning("No migration files found in: {$migrationsPath}");
            return [];
        }

        $pool = Quarry::getConnectionPool($connectionName);
        $scope = new ConnectionScope($pool);

        $executedMigrations = [];
        try {
            $connection = $scope->getConnection();
            $migrationsTable = $this->getMigrationsTableName($connectionName);

            $stmt = $connection->query("SELECT migration FROM {$migrationsTable} ORDER BY id");
            $executedMigrations = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\PDOException $e) {
            // Table might not exist yet - that's ok
        } finally {
            $scope->release();
        }

        $pending = [];
        foreach ($migrationFiles as $file) {
            $filename = basename($file);
            if (!in_array($filename, $executedMigrations)) {
                $pending[$filename] = $file;
            }
        }

        return $pending;
    }

    private function runMigrations(array $migrations, string $connectionName, SymfonyStyle $io): array
    {
        $pool = Quarry::getConnectionPool($connectionName);
        $results = ['success' => 0, 'failed' => 0];
        $batch = $this->getNextBatchNumber($connectionName);
        $migrationsTable = $this->getMigrationsTableName($connectionName);

        foreach ($migrations as $filename => $filepath) {
            $io->text("<fg=blue>🚀 Migrating:</> {$filename} on connection '{$connectionName}'");

            $scope = new ConnectionScope($pool);
            try {
                $connection = $scope->getConnection();
                $sql = file_get_contents($filepath);

                // Execute migration
                $connection->exec($sql);

                // Record migration in connection-specific table
                $stmt = $connection->prepare("
                    INSERT INTO {$migrationsTable} (migration, batch) 
                    VALUES (?, ?)
                ");
                $stmt->execute([$filename, $batch]);

                $results['success']++;
                $io->text("<fg=green>✅ Success:</> {$filename}");
            } catch (\PDOException $e) {
                $results['failed']++;
                $io->text("<fg=red>❌ Failed:</> {$filename}");
                $io->text("    Error: {$e->getMessage()}");

                if (!$io->confirm('Continue with remaining migrations?', false)) {
                    break;
                }
            } finally {
                $scope->release();
            }
        }

        return $results;
    }

    private function getNextBatchNumber(string $connectionName): int
    {
        $pool = Quarry::getConnectionPool($connectionName);
        $scope = new ConnectionScope($pool);

        try {
            $connection = $scope->getConnection();
            $migrationsTable = $this->getMigrationsTableName($connectionName);

            $stmt = $connection->query("SELECT MAX(batch) FROM {$migrationsTable}");
            $batch = $stmt->fetch(\PDO::FETCH_COLUMN);
            return (int) $batch + 1;
        } catch (\PDOException $e) {
            return 1;
        } finally {
            $scope->release();
        }
    }
}
