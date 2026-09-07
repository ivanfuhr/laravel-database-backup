<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Backup Strategy
    |--------------------------------------------------------------------------
    |
    | This option controls the default backup strategy that will be used by the
    | package. Strategies are resolved like Laravel filesystem disks.
    |
    */

    'default' => env('DB_BACKUP_STRATEGY', 'pgsql'),

    /*
    |--------------------------------------------------------------------------
    | Backup Storage
    |--------------------------------------------------------------------------
    |
    | Configure where backup files are stored. When disk is null/empty, the
    | application's default filesystem disk is used.
    |
    */

    'disk' => env('DB_BACKUP_DISK'),

    'directory' => env('DB_BACKUP_DIRECTORY', 'backups'),

    'retention_days' => (int) env('DB_BACKUP_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Backup Strategies
    |--------------------------------------------------------------------------
    |
    | Each strategy maps a name to a driver implementation. Built-in drivers
    | currently include "pgsql". Register custom strategies via
    | BackupManager::extend() or by binding a class that implements
    | DatabaseBackupStrategy.
    |
    */

    'strategies' => [

        'pgsql' => [
            'driver' => 'pgsql',
            'pg_dump_binary' => env('PG_DUMP_PATH', 'pg_dump'),
            'pg_restore_binary' => env('PG_RESTORE_PATH', 'pg_restore'),
        ],

    ],

];
