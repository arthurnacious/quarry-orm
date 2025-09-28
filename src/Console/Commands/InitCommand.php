<?php

namespace Quarry\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class InitCommand extends Command
{
    protected static $defaultName = 'init';

    protected function configure(): void
    {
        $this->setDescription('Initialize Quarry in your project');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $output->writeln('<info>Setting up Quarry...</info>');

            $this->createDirectories($output);
            $this->createFiles($output);

            $output->writeln('<info>✓ Quarry initialized successfully!</info>');
            $output->writeln('');
            $output->writeln('<comment>Next steps:</comment>');
            $output->writeln('1. Configure your database in <info>database/config.php</info>');
            $output->writeln('2. Define your schema in <info>database/schema.php</info>');
            $output->writeln('3. Run: <info>composer run migrate</info>');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln("<error>Init failed: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }

    private function createDirectories(OutputInterface $output): void
    {
        $dirs = ['database', 'database/seeders', 'app/Models'];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
                $output->writeln("✓ Created {$dir}/");
            }
        }
    }

    private function createFiles(OutputInterface $output): void
    {
        $files = [
            'database/config.php' => $this->getConfigTemplate(),
            'database/schema.php' => $this->getSchemaTemplate(),
            'database/seeders/DatabaseSeeder.php' => $this->getSeederTemplate(),
            '.env.example' => $this->getEnvTemplate(),
        ];

        foreach ($files as $file => $content) {
            if (!file_exists($file)) {
                file_put_contents($file, $content);
                $output->writeln("✓ Created {$file}");
            }
        }
    }

    private function getSeederTemplate(): string
    {
        return <<<'PHP'
            <?php
            
            class DatabaseSeeder
            {
                public function run(): void
                {
                    // Add your seed data here
                    // Example:
                    // \App\Models\User::create([
                    //     'name' => 'Admin',
                    //     'email' => 'admin@example.com',
                    //     'password' => 'secret',
                    // ]);
                    
                    echo "Database seeded! Add your seed data above.\n";
                }
            }
        PHP;
    }

    private function getConfigTemplate(): string
    {
        return <<<'PHP'
            <?php

            return [
                'default' => 'mysql_primary',
                
                'connections' => [
                    'mysql_primary' => [
                        'driver' => 'mysql',
                        'host' => env('DB_HOST', 'localhost'),
                        'database' => env('DB_NAME', 'myapp'),
                        'username' => env('DB_USER', 'root'),
                        'password' => env('DB_PASS', ''),
                        'charset' => 'utf8mb4',
                    ],
                ],
                
                'schema_path' => __DIR__ . '/schema.php',
                'auto_create_tables' => env('APP_ENV') === 'local',
            ];
        PHP;
    }

    private function getSchemaTemplate(): string
    {
        return <<<'PHP'
            <?php

            return [
                'users' => [
                    'id' => 'id|primary|autoincrement',
                    'name' => 'string|max:255',
                    'email' => 'string|unique',
                    'password' => 'string|max:255',
                    'created_at' => 'datetime',
                ],
            ];
        PHP;
    }

    private function getEnvTemplate(): string
    {
        return <<<'ENV'
            APP_ENV=local

            DB_HOST=localhost
            DB_NAME=myapp
            DB_USER=root
            DB_PASS=
        ENV;
    }
}
