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
        if (DB::getDriverName() !== 'mysql')
            return;

        (new PDO(
            'mysql:host=' . env('DB_HOST'),
            env('DB_USERNAME'),
            env('DB_PASSWORD')
        ))->exec('CREATE DATABASE IF NOT EXISTS ' . env('DB_DATABASE'));

        return 0;
    }
}
