<?php

namespace Elysiumrealms\DatabaseExtension;

use DateTime;
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

        Blueprint::macro('coldStorage', function ($interval = '90 days') {
            /** @var Illuminate\Database\Schema\Blueprint */
            $blueprint = $this;

            $table = $blueprint->getTable();
            $snake = Str::snake($table);

            $event = "dehydrate_{$snake}";

            $days = (int)(new DateTime())
                ->diff(new DateTime($interval))
                ->format('%a');

            DB::unprepared(<<<SQL
                DROP EVENT IF EXISTS {$event};
                CREATE EVENT {$event}
                ON SCHEDULE EVERY 1 DAY
                STARTS CURDATE() + INTERVAL 1 DAY
                DO
                CALL dehydrate('{$table}', {$days}, TRUE);
            SQL);

            DB::unprepared(<<<SQL
                DROP PROCEDURE IF EXISTS `dehydrate`;
                CREATE PROCEDURE `dehydrate`(
                    IN `table_name` VARCHAR(255),
                    IN `days` INT,
                    IN `daily` BOOLEAN
                )
                BEGIN
                    -- 設置目標表名稱
                    SET @target_table_name = CONCAT(
                        table_name,
                        '_',
                        DATE_FORMAT(
                            DATE_SUB(
                                CURDATE(),
                                INTERVAL days DAY
                            ),
                            '%Y%m'
                        )
                    );

                    SET @range_begin = DATE_FORMAT(
                        DATE_SUB(
                            CURDATE(),
                            INTERVAL days DAY
                        ),
                        '%Y-%m-01'
                    );

                    SET @range_until = DATE_SUB(
                        CURDATE(),
                        INTERVAL days DAY
                    );

                    IF daily = FALSE THEN
                        SET @range_until = LAST_DAY(
                            @range_until
                        );
                    END IF;

                    SET @range_until = DATE_FORMAT(
                        @range_until,
                        '%Y-%m-%d'
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
                        ' BETWEEN ',
                        QUOTE(@range_begin),
                        ' AND ',
                        QUOTE(@range_until),
                        ';'
                    );
                    PREPARE ship_stmt FROM @ship_data_sql;
                    EXECUTE ship_stmt;
                    DEALLOCATE PREPARE ship_stmt;

                    -- 從原始表中刪除超過指定月份的數據
                    SET @delete_data_sql = CONCAT(
                        'DELETE FROM ', table_name,
                        ' WHERE DATE(created_at)',
                        ' BETWEEN ',
                        QUOTE(@range_begin),
                        ' AND ',
                        QUOTE(@range_until),
                        ';'
                    );
                    PREPARE delete_stmt FROM @delete_data_sql;
                    EXECUTE delete_stmt;
                    DEALLOCATE PREPARE delete_stmt;
                END;
            SQL);
        });
    }
}
