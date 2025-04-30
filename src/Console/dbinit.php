<?php

namespace Elysiumrealms\DatabaseExtension\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

class dbinit extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:init';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create database if not exists';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        collect(config('database.connections'))
            ->filter(fn($connection) => $connection['driver'] === 'mysql')
            ->each(function ($connection) {
                (new PDO(
                    'mysql:host=' . $connection['host'],
                    $connection['username'],
                    $connection['password']
                ))->exec('CREATE DATABASE IF NOT EXISTS ' . $connection['database']);
            });

        return 0;
    }
}
