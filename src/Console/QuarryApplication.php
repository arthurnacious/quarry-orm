<?php

namespace Quarry\Console;

use Quarry\Console\Commands\MigrateCommand;
use Quarry\Console\Commands\SeedCommand;
use Quarry\Console\Commands\InitCommand;
use Symfony\Component\Console\Application;

class QuarryApplication extends Application
{
    public function __construct()
    {
        parent::__construct('Quarry ORM', '0.2.0');

        $this->addCommands([
            new MigrateCommand(),
            new SeedCommand(),
            new InitCommand(),
        ]);
    }
}
