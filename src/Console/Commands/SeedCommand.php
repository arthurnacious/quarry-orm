<?php

namespace Quarry\Console\Commands;

use Quarry\Quarry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

class SeedCommand extends Command
{
    protected static $defaultName = 'seed';

    protected function configure(): void
    {
        $this
            ->setDescription('Seed the database with sample data')
            ->addArgument('seeder', InputArgument::OPTIONAL, 'The specific seeder class to run', 'DatabaseSeeder');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!Quarry::isInitialized()) {
            $output->writeln('<error>Quarry not initialized. Run "vendor/bin/quarry init" first.</error>');
            return Command::FAILURE;
        }

        try {
            $seederClass = $input->getArgument('seeder');
            $seederFile = "database/seeders/{$seederClass}.php";

            if (!file_exists($seederFile)) {
                $output->writeln("<error>Seeder file not found: {$seederFile}</error>");
                $output->writeln("<comment>Create a seeder file in database/seeders/</comment>");
                return Command::FAILURE;
            }

            // Include the seeder file
            require_once $seederFile;

            // Build possible class names
            $possibleClasses = [
                "Database\\Seeders\\{$seederClass}",
                $seederClass
            ];

            $seederInstance = null;
            foreach ($possibleClasses as $className) {
                if (class_exists($className)) {
                    $seederInstance = new $className();
                    break;
                }
            }

            if (!$seederInstance) {
                $output->writeln("<error>Seeder class not found. Tried:</error>");
                foreach ($possibleClasses as $className) {
                    $output->writeln("  - {$className}");
                }
                $output->writeln("<comment>Make sure your seeder class is properly defined.</comment>");
                return Command::FAILURE;
            }

            $output->writeln("<info>Running seeder: {$seederClass}</info>");

            $seederInstance->run();

            $output->writeln('<info>✓ Database seeded successfully</info>');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln("<error>Seeding failed: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }
}
