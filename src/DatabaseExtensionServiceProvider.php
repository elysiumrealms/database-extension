<?php

namespace Elysiumrealms\DatabaseExtension;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Schema\Blueprint;

class DatabaseExtensionServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->registerCommands();

        $this->registerBlueprintMacro();

        $this->registerServiceProvider();
    }

    /**
     * Register the SQLInterceptor Artisan commands.
     *
     * @return void
     */
    protected function registerCommands()
    {
        if (!$this->app->runningInConsole())
            return;

        $this->commands([
            Console\dbinit::class,
        ]);
    }

    /**
     * Register the Service provider in the application configuration file.
     *
     * @return void
     */
    protected function registerServiceProvider()
    {
        if (!$this->app->runningInConsole())
            return;

        $appConfig = file_get_contents(config_path('app.php'));

        $class = static::class . '::class';

        if (Str::contains($appConfig, $class))
            return;

        file_put_contents(config_path('app.php'), str_replace(
            "        /*" . PHP_EOL .
                "         * Package Service Providers..." . PHP_EOL .
                "         */",
            "        /*" . PHP_EOL .
                "         * Package Service Providers..." . PHP_EOL .
                "         */" . PHP_EOL .
                "        " . $class . ",",
            $appConfig
        ));
    }

    /**
     * Register the Blueprint macro.
     *
     * @return void
     */
    protected function registerBlueprintMacro()
    {
        if (!$this->app->runningInConsole())
            return;

        Blueprint::macro('dropForeignKeys', function () {
            /** @var Illuminate\Database\Schema\Blueprint */
            $table = $this;
            // Get all foreign keys of the table
            $foreignKeys = DB::select(<<<SQL
                SELECT CONSTRAINT_NAME
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = ?
                AND TABLE_NAME = ?
                AND REFERENCED_TABLE_NAME IS NOT NULL
            SQL, [DB::getDatabaseName(), $table->getTable()]);
            collect($foreignKeys)
                ->each(function ($foreignKey) use ($table) {
                    $table->dropForeign($foreignKey->CONSTRAINT_NAME);
                });
        });

        Blueprint::macro('archiveMonths', function ($months = 1) {
            /** @var Illuminate\Database\Schema\Blueprint */
            $blueprint = $this;

            $table = $blueprint->getTable();
            $snake = Str::snake($table);

            $event = "archive_months_{$snake}";

            DB::unprepared(<<<SQL
                DROP EVENT IF EXISTS {$event};
                CREATE EVENT {$event}
                ON SCHEDULE EVERY 1 DAY
                STARTS CURDATE() + INTERVAL 1 DAY
                DO
                CALL archive_months('{$table}', {$months});
            SQL);

            DB::unprepared(<<<SQL
                DROP PROCEDURE IF EXISTS `archive_months`;
                CREATE PROCEDURE `archive_months`(
                    IN `table_name` VARCHAR(255),
                    IN `months` INT
                )
                BEGIN
                    -- 設置目標表名稱
                    SET
                    @target_table_name = CONCAT(
                        table_name,
                        '_',
                        DATE_FORMAT(
                        DATE_SUB(
                            CURDATE(),
                            INTERVAL months MONTH
                        ),
                        '%Y%m'
                        )
                    );

                    -- 如果表不存在，創建它
                    SET @create_table_sql = CONCAT(
                        'CREATE TABLE IF NOT EXISTS ',
                        @target_table_name,
                        ' LIKE ', table_name, ';'
                    );
                    PREPARE create_stmt FROM @create_table_sql;
                    EXECUTE create_stmt;
                    DEALLOCATE PREPARE create_stmt;

                    -- 將超過指定月份的數據移轉到目標表
                    SET @ship_data_sql = CONCAT(
                        'INSERT INTO ', @target_table_name,
                        ' SELECT * FROM ', table_name,
                        ' WHERE DATE(created_at)',
                        ' < DATE_SUB(CURDATE(), INTERVAL ',
                        months, ' MONTH);'
                    );
                    PREPARE ship_stmt FROM @ship_data_sql;
                    EXECUTE ship_stmt;
                    DEALLOCATE PREPARE ship_stmt;

                    -- 從原始表中刪除超過指定月份的數據
                    SET @delete_data_sql = CONCAT(
                        'DELETE FROM ', table_name,
                        ' WHERE DATE(created_at)',
                        ' < DATE_SUB(CURDATE(), INTERVAL ',
                        months, ' MONTH);'
                    );
                    PREPARE delete_stmt FROM @delete_data_sql;
                    EXECUTE delete_stmt;
                    DEALLOCATE PREPARE delete_stmt;
                END;
            SQL);
        });
    }
}
