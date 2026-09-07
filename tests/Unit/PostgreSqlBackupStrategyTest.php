<?php

declare(strict_types=1);

use IvanFuhr\LaravelDatabaseBackup\BackupManager;
use IvanFuhr\LaravelDatabaseBackup\Exceptions\BackupFailedException;
use IvanFuhr\LaravelDatabaseBackup\Exceptions\RestoreFailedException;
use IvanFuhr\LaravelDatabaseBackup\Strategies\PostgreSqlBackupStrategy;
use IvanFuhr\LaravelDatabaseBackup\Support\BackupStorage;
use IvanFuhr\LaravelDatabaseBackup\Support\DatabaseConnection;

beforeEach(function (): void {
    configurePgsqlBackup();
});

it('fails when the temporary dump file cannot be opened for upload', function (): void {
    config(['database-backup.strategies.pgsql.pg_dump_binary' => createUnreadablePgDumpScript()]);
    app()->forgetInstance(BackupManager::class);

    $manager = app(BackupManager::class);
    $strategy = $manager->strategy();
    $connection = $manager->connectionFor();
    $storage = BackupStorage::forConfiguredDisk();

    $strategy->backup($connection, $storage, 'unreadable.dump');
})->throws(BackupFailedException::class, 'Failed to open temporary file for upload.');

it('creates the temporary directory when missing during backup', function (): void {
    $tempDir = storage_path('app/tmp');

    if (is_dir($tempDir)) {
        foreach (glob($tempDir.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($tempDir);
    }

    expect(is_dir($tempDir))->toBeFalse();

    $manager = app(BackupManager::class);
    $strategy = $manager->strategy();
    $connection = $manager->connectionFor();
    $storage = BackupStorage::forConfiguredDisk();

    $remotePath = $strategy->backup($connection, $storage, 'mkdir.dump');

    expect(is_dir($tempDir))->toBeTrue()
        ->and(backupDisk()->exists($remotePath))->toBeTrue();
});

it('rejects restore when the backup file is missing', function (): void {
    $strategy = new PostgreSqlBackupStrategy;
    $connection = DatabaseConnection::fromConfig('pgsql', [
        'driver' => 'pgsql',
        'host' => '127.0.0.1',
        'port' => '5432',
        'database' => 'testing',
        'username' => 'postgres',
        'password' => 'secret',
    ]);

    $strategy->restore($connection, storage_path('framework/testing/missing-'.getmypid().'.dump'));
})->throws(RestoreFailedException::class);

it('rejects restore when pg_restore reports an unsupported version', function (): void {
    config(['database-backup.strategies.pgsql.pg_restore_binary' => createUnsupportedVersionPgRestoreScript()]);
    app()->forgetInstance(BackupManager::class);

    $local = storage_path('framework/testing/unsupported-'.getmypid().'.dump');
    file_put_contents($local, 'dump');

    $manager = app(BackupManager::class);

    try {
        $manager->strategy()->restore($manager->connectionFor(), $local);
    } finally {
        @unlink($local);
    }
})->throws(RestoreFailedException::class, 'Upgrade PostgreSQL client tools');

it('rejects restore when pg_restore fails', function (): void {
    config(['database-backup.strategies.pgsql.pg_restore_binary' => createFailingPgRestoreScript()]);
    app()->forgetInstance(BackupManager::class);

    $local = storage_path('framework/testing/failing-restore-'.getmypid().'.dump');
    file_put_contents($local, 'dump');

    $manager = app(BackupManager::class);

    try {
        $manager->strategy()->restore($manager->connectionFor(), $local);
    } finally {
        @unlink($local);
    }
})->throws(RestoreFailedException::class, 'pg_restore failed');

it('falls back to a generic backup message when pg_dump has empty output', function (): void {
    $path = storage_path('framework/testing/bin-'.getmypid().'/silent-failing-pg-dump.sh');
    ensureTestingDirectoryExists(dirname($path));
    file_put_contents($path, "#!/usr/bin/env bash\nexit 1\n");
    chmod($path, 0755);

    config(['database-backup.strategies.pgsql.pg_dump_binary' => $path]);
    app()->forgetInstance(BackupManager::class);

    $manager = app(BackupManager::class);

    $manager->strategy()->backup($manager->connectionFor(), BackupStorage::forConfiguredDisk(), 'silent.dump');
})->throws(BackupFailedException::class, 'pg_dump failed.');

it('falls back to a generic restore message when pg_restore has empty output', function (): void {
    $path = storage_path('framework/testing/bin-'.getmypid().'/silent-failing-pg-restore.sh');
    ensureTestingDirectoryExists(dirname($path));
    file_put_contents($path, "#!/usr/bin/env bash\nexit 1\n");
    chmod($path, 0755);

    config(['database-backup.strategies.pgsql.pg_restore_binary' => $path]);
    app()->forgetInstance(BackupManager::class);

    $local = storage_path('framework/testing/silent-restore-'.getmypid().'.dump');
    file_put_contents($local, 'dump');

    $manager = app(BackupManager::class);

    try {
        $manager->strategy()->restore($manager->connectionFor(), $local);
    } finally {
        @unlink($local);
    }
})->throws(RestoreFailedException::class, 'pg_restore failed.');
