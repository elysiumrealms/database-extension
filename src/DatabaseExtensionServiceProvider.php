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

        $this->registerBuilderMacro();
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

            $days = (int)(new DateTime())
                ->diff(new DateTime($interval))
                ->format('%a');

            DB::unprepared(<<<SQL
                DROP EVENT IF EXISTS dehydrate_{$table};
                CREATE EVENT dehydrate_{$table}
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

        Blueprint::macro('dropColdStorage', function() {
            /** @var Illuminate\Database\Schema\Blueprint */
            $blueprint = $this;

            $table = $blueprint->getTable();

            DB::unprepared(<<<SQL
                DROP EVENT IF EXISTS dehydrate_{$table};
            SQL);
        });

        Blueprint::macro('usePartition', function($interval = '90 days') {
            /** @var Illuminate\Database\Schema\Blueprint */
            $blueprint = $this;

            $table = $blueprint->getTable();

            DB::unprepared(<<<SQL
                DROP EVENT IF EXISTS create_partition_{$table};
                CREATE EVENT create_partition_{$table}
                ON SCHEDULE EVERY 1 DAY
                STARTS TIMESTAMP(CURDATE(), '23:55:00')
                DO
                CALL create_partition('{$table}');
            SQL);

            $today = now()->format('Ymd');
            $tomorrow = now()->addDay()->format('Y-m-d');
            DB::unprepared(<<<SQL
                ALTER TABLE {$table}
                ADD COLUMN `date` DATE
                    NOT NULL
                    DEFAULT (CURDATE())
                    AFTER `id`;
                ALTER TABLE {$table}
                    DROP PRIMARY KEY,
                    ADD PRIMARY KEY(id, date);
                ALTER TABLE {$table}
                PARTITION BY RANGE(TO_DAYS(date)) (
                    PARTITION p{$today}
                        VALUES LESS THAN (TO_DAYS('{$tomorrow}'))
                );
                DROP TRIGGER IF EXISTS set_partition_column_{$table};
                CREATE TRIGGER set_partition_column_{$table}
                BEFORE INSERT ON {$table}
                FOR EACH ROW
                BEGIN
                    SET NEW.date = DATE(NEW.created_at);
                END;
            SQL);

            DB::unprepared(<<<SQL
                DROP PROCEDURE IF EXISTS `create_partition`;
                CREATE PROCEDURE `create_partition`(
                    IN `table_name` VARCHAR(255)
                )
                BEGIN
                    DECLARE i INT DEFAULT 1;

                    -- Create partitions for the next 3 days
                    WHILE i <= 3 DO
                        SET @partition_date = CURDATE() + INTERVAL i DAY;

                        SET @next_partition_name = CONCAT(
                            'p',
                            DATE_FORMAT(
                                @partition_date, '%Y%m%d'
                            )
                        );

                        IF NOT EXISTS (
                            SELECT 1
                            FROM INFORMATION_SCHEMA.PARTITIONS
                            WHERE TABLE_NAME=table_name
                            AND PARTITION_NAME = @next_partition_name) THEN

                            SET @next_partition_value = TO_DAYS(
                                DATE_FORMAT(
                                    @partition_date + INTERVAL 1 DAY,
                                    '%Y-%m-%d'
                                )
                            );

                            SET @add_partition_sql = CONCAT(
                                'ALTER TABLE ', table_name,
                                ' ADD PARTITION (PARTITION ',
                                @next_partition_name,
                                ' VALUES LESS THAN (',
                                @next_partition_value,
                                ' ));'
                            );
                            PREPARE add_partition_stmt FROM @add_partition_sql;
                            EXECUTE add_partition_stmt;
                            DEALLOCATE PREPARE add_partition_stmt;
                        END IF;

                        SET i = i + 1;
                    END WHILE;
                END;
            SQL);

            $days = (int)(new DateTime())
                ->diff(new DateTime($interval))
                ->format('%a');

            DB::unprepared(<<<SQL
                DROP EVENT IF EXISTS dehydrate_partition_{$table};
                CREATE EVENT dehydrate_partition_{$table}
                ON SCHEDULE EVERY 1 DAY
                STARTS CURDATE() + INTERVAL 1 DAY
                DO
                CALL dehydrate_partition('{$table}', {$days});
            SQL);

            DB::unprepared(<<<SQL
                DROP PROCEDURE IF EXISTS `dehydrate_partition`;
                CREATE PROCEDURE `dehydrate_partition`(
                    IN `table_name` VARCHAR(255),
                    IN `days` INT
                )
                BEGIN
                    SET @old_partition_name = CONCAT(
                        'p',
                        DATE_FORMAT(
                            DATE_SUB(
                                CURDATE(),
                                INTERVAL 1 MONTH
                            ),
                            '%Y%m%d'
                        )
                    );

                    IF EXISTS (
                        SELECT 1
                        FROM INFORMATION_SCHEMA.PARTITIONS
                        WHERE TABLE_NAME=table_name
                        AND PARTITION_NAME = @old_partition_name) THEN

                        SET @target_table_name =
                            CONCAT(table_name, '_',
                                DATE_FORMAT(
                                    DATE_SUB(
                                        CURDATE(),
                                        INTERVAL days DAY
                                    ),
                                    '%Y%m'
                                )
                            );

                        SET @create_table_sql = CONCAT(
                            'CREATE TABLE IF NOT EXISTS ',
                            target_table_name, ' LIKE ',
                            table_name, ';'
                        );
                        PREPARE create_stmt FROM @create_table_sql;
                        EXECUTE create_stmt;
                        DEALLOCATE PREPARE create_stmt;

                        SET @ship_data_sql = CONCAT(
                            'INSERT INTO ', @target_table_name,
                            ' SELECT * FROM ', table_name,
                            ' PARTITION(',
                            @old_partition_name,
                            ' );'
                        );
                        PREPARE ship_stmt FROM @ship_data_sql;
                        EXECUTE ship_stmt;
                        DEALLOCATE PREPARE ship_stmt;

                        SET @drop_partition_sql = CONCAT(
                            'ALTER TABLE ', table_name,
                            ' DROP PARTITION ',
                            old_partition_name, ';'
                        );
                        PREPARE drop_stmt FROM @drop_partition_sql;
                        EXECUTE drop_stmt;
                        DEALLOCATE PREPARE drop_stmt;
                    END IF;
                END;
            SQL);
        });

        Blueprint::macro('dropPartition', function() {
            /** @var Illuminate\Database\Schema\Blueprint */
            $blueprint = $this;

            $table = $blueprint->getTable();

            DB::unprepared(<<<SQL
                CREATE TABLE {$table}_new AS
                    SELECT * FROM {$table};
                DROP TABLE {$table};
                RENAME TABLE {$table}_new TO {$table};
                ALTER TABLE {$table} ADD PRIMARY KEY(id);
                DROP EVENT IF EXISTS create_partition_{$table};
                DROP EVENT IF EXISTS dehydrate_partition_{$table};
                DROP TRIGGER IF EXISTS set_partition_column_{$table};
            SQL);
        });
    }

    /**
     * Register the Builder macro.
     *
     * @return void
     */
    protected function registerBuilderMacro()
    {
        \Illuminate\Database\Eloquent\Builder
            ::macro('toRawSql', function () {
                /** @var \Illuminate\Database\Eloquent\Builder $this */
                $raw = $this->toSql();
                collect($this->getBindings())
                    ->each(function ($binding) use (&$raw) {
                        $raw = preg_replace(
                            '/\?/',
                            is_string($binding)
                                ? "'$binding'" : $binding,
                            $raw,
                            1
                        );
                    });
                return addcslashes($raw, '\\');
            });

        \Illuminate\Database\Query\Builder
            ::macro('toRawSql', function () {
                /** @var \Illuminate\Database\Query\Builder $this */
                $raw = $this->toSql();
                collect($this->getBindings())
                    ->each(function ($binding) use (&$raw) {
                        $raw = preg_replace(
                            '/\?/',
                            is_string($binding)
                                ? "'$binding'" : $binding,
                            $raw,
                            1
                        );
                    });
                return addcslashes($raw, '\\');
            });
    }
}
