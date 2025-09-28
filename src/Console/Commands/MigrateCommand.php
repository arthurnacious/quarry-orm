<?php

namespace Quarry\Console\Commands;

use Quarry\Quarry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

class MigrateCommand extends Command
{
    protected static $defaultName = 'migrate';

    protected function configure(): void
    {
        $this
            ->setDescription('Run database migrations')
            ->addOption('fresh', null, InputOption::VALUE_NONE, 'Drop all tables and re-run migrations')
            ->addOption('seed', null, InputOption::VALUE_NONE, 'Seed the database after migration');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Check if Quarry is initialized
        if (!Quarry::isInitialized()) {
            $output->writeln('<error>Quarry not initialized. Run "vendor/bin/quarry init" first.</error>');
            $output->writeln('<comment>Make sure you call Quarry::init() in your bootstrap file.</comment>');
            return Command::FAILURE;
        }

        try {
            if ($input->getOption('fresh')) {
                return $this->migrateFresh($output, $input->getOption('seed'));
            }

            $output->writeln('<info>Running migrations...</info>');
            Quarry::createTables();
            $output->writeln('<info>✓ Migrations completed successfully</info>');

            if ($input->getOption('seed')) {
                $this->runSeed($output);
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln("<error>Migration failed: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }

    private function migrateFresh(OutputInterface $output, bool $seed = false): int
    {
        $output->writeln('<comment>Dropping all tables...</comment>');
        Quarry::dropTables();

        $output->writeln('<info>Running fresh migrations...</info>');
        Quarry::createTables();
        $output->writeln('<info>✓ Fresh migrations completed</info>');

        if ($seed) {
            $this->runSeed($output);
        }

        return Command::SUCCESS;
    }

    private function runSeed(OutputInterface $output): void
    {
        $output->writeln('<info>Seeding database...</info>');

        if (file_exists('database/seeders/DatabaseSeeder.php')) {
            // Instead of trying to run it directly, suggest the command
            $output->writeln('<comment>Run the seeder with: composer run seed</comment>');
            $output->writeln('<comment>Or: vendor/bin/quarry seed</comment>');
        } else {
            $output->writeln('<comment>No seeder found. Create database/seeders/DatabaseSeeder.php</comment>');
        }
    }
}
