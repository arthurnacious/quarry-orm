<?php

namespace Quarry\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Quarry\Quarry;
use Quarry\Database\SyncPool;
use PDO;
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
                'Database connection pool to use',
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
        $connectionPool = $input->getOption('connection');
        $databaseUrl = $input->getOption('database-url');

        try {
            // Ensure the requested pool exists
            $this->ensurePoolExists($connectionPool, $databaseUrl, $io);

            // Rest of the method remains the same...
            $this->ensureMigrationsTable($connectionPool, $io);

            $pendingMigrations = $this->getPendingMigrations($migrationsPath, $connectionPool, $io);

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

            $results = $this->runMigrations($pendingMigrations, $connectionPool, $io);

            $io->newLine();

            if ($results['failed'] > 0) {
                $io->error(sprintf('Completed with %d error(s)', $results['failed']));
                return Command::FAILURE;
            }

            $io->success(sprintf(
                '✅ Successfully ran %d migration(s) on connection "%s"',
                $results['success'],
                $connectionPool
            ));

            return Command::SUCCESS;

        } catch (RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }

    private function ensurePoolExists(string $poolName, ?string $databaseUrl, SymfonyStyle $io): void
    {
        if (Quarry::hasPool($poolName)) {
            return;
        }

        $io->text("<fg=yellow>⚠️</> Pool '{$poolName}' not found in configuration");

        // Create a default pool
        if ($databaseUrl) {
            $io->text("Using provided database URL: {$databaseUrl}");
        } else {
            // Default to SQLite in current directory
            $databaseUrl = 'sqlite:///' . getcwd() . '/database.sqlite';
            $io->text("Using default SQLite database: {$databaseUrl}");
        }

        $pool = new SyncPool([
            'max_connections' => 5,
            'max_idle_connections' => 3,
            'idle_timeout' => 30,
            'connection_config' => [
                'database_url' => $databaseUrl
            ]
        ]);

        Quarry::registerPool($poolName, $pool);
        Quarry::setDefaultPool($poolName);
        
        $io->text("<fg=green>✅</> Created pool '{$poolName}'");
    }

    // ... rest of the methods remain the same
    private function ensureMigrationsTable(string $connectionPool, SymfonyStyle $io): void
    {
        $pool = Quarry::getPool($connectionPool);
        $connection = $pool->getConnection();

        try {
            $connection->exec('
                CREATE TABLE IF NOT EXISTS quarry_migrations (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    migration VARCHAR(255) NOT NULL UNIQUE,
                    batch INTEGER NOT NULL,
                    executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ');
            $io->text('<fg=green>✅</> Migrations table ready');
        } catch (\PDOException $e) {
            throw new RuntimeException('Failed to create migrations table: ' . $e->getMessage());
        } finally {
            $pool->releaseConnection($connection);
        }
    }

    private function getPendingMigrations(string $migrationsPath, string $connectionPool, SymfonyStyle $io): array
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

        $pool = Quarry::getPool($connectionPool);
        $connection = $pool->getConnection();
        
        $executedMigrations = [];
        try {
            $stmt = $connection->query('SELECT migration FROM quarry_migrations ORDER BY id');
            $executedMigrations = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (\PDOException $e) {
            // Table might not exist yet - that's ok
        } finally {
            $pool->releaseConnection($connection);
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

    private function runMigrations(array $migrations, string $connectionPool, SymfonyStyle $io): array
    {
        $pool = Quarry::getPool($connectionPool);
        $results = ['success' => 0, 'failed' => 0];
        $batch = $this->getNextBatchNumber($connectionPool);

        foreach ($migrations as $filename => $filepath) {
            $io->text("<fg=blue>🚀 Migrating:</> {$filename}");

            try {
                $connection = $pool->getConnection();
                $sql = file_get_contents($filepath);
                
                // Execute migration
                $connection->exec($sql);
                
                // Record migration
                $stmt = $connection->prepare('
                    INSERT INTO quarry_migrations (migration, batch) 
                    VALUES (?, ?)
                ');
                $stmt->execute([$filename, $batch]);
                
                $pool->releaseConnection($connection);
                
                $results['success']++;
                $io->text("<fg=green>✅ Success:</> {$filename}");

            } catch (\PDOException $e) {
                $results['failed']++;
                $io->text("<fg=red>❌ Failed:</> {$filename}");
                $io->text("    Error: {$e->getMessage()}");
                
                if (!$io->confirm('Continue with remaining migrations?', false)) {
                    break;
                }
            }
        }

        return $results;
    }

    private function getNextBatchNumber(string $connectionPool): int
    {
        $pool = Quarry::getPool($connectionPool);
        $connection = $pool->getConnection();
        
        try {
            $stmt = $connection->query('SELECT MAX(batch) FROM quarry_migrations');
            $batch = $stmt->fetch(PDO::FETCH_COLUMN);
            return (int) $batch + 1;
        } catch (\PDOException $e) {
            return 1;
        } finally {
            $pool->releaseConnection($connection);
        }
    }
}