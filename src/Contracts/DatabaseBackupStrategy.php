<?php

declare(strict_types=1);

namespace IvanFuhr\LaravelDatabaseBackup\Contracts;

use IvanFuhr\LaravelDatabaseBackup\Support\BackupStorage;
use IvanFuhr\LaravelDatabaseBackup\Support\DatabaseConnection;

interface DatabaseBackupStrategy
{
    /**
     * Unique strategy name used for manager resolution.
     */
    public function name(): string;

    /**
     * Whether this strategy can handle the given Laravel database driver.
     */
    public function supports(string $driver): bool;

    /**
     * Create a backup and store it on the configured disk.
     *
     * @return string Relative path of the backup on the configured disk
     */
    public function backup(DatabaseConnection $connection, BackupStorage $storage, ?string $filename = null): string;

    /**
     * Restore a local backup file into the given database connection.
     */
    public function restore(DatabaseConnection $connection, string $localBackupPath): void;
}
