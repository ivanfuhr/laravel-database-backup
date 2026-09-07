<?php

declare(strict_types=1);

namespace IvanFuhr\LaravelDatabaseBackup;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use IvanFuhr\LaravelDatabaseBackup\Commands\DatabaseBackupCommand;
use IvanFuhr\LaravelDatabaseBackup\Commands\DatabaseListBackupsCommand;
use IvanFuhr\LaravelDatabaseBackup\Commands\DatabasePruneBackupsCommand;
use IvanFuhr\LaravelDatabaseBackup\Commands\DatabaseRestoreCommand;
use IvanFuhr\LaravelDatabaseBackup\Support\ConnectionResolver;

final class DatabaseBackupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/database-backup.php', 'database-backup');

        $this->app->singleton(ConnectionResolver::class);

        $this->app->singleton(BackupManager::class, fn (Application $app): BackupManager => new BackupManager($app, $app->make(ConnectionResolver::class)));

        $this->app->alias(BackupManager::class, 'db.backup');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/database-backup.php' => config_path('database-backup.php'),
            ], 'database-backup-config');

            $this->commands([
                DatabaseBackupCommand::class,
                DatabaseRestoreCommand::class,
                DatabaseListBackupsCommand::class,
                DatabasePruneBackupsCommand::class,
            ]);
        }
    }
}
