<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use IvanFuhr\LaravelDatabaseBackup\Strategies\PostgreSqlBackupStrategy;
use IvanFuhr\LaravelDatabaseBackup\Support\BackupStorage;
use IvanFuhr\LaravelDatabaseBackup\Support\ConnectionResolver;

it('creates backup storage for the configured disk', function (): void {
    configurePgsqlBackup();

    $storage = BackupStorage::forConfiguredDisk();

    expect($storage->directory())->toBe('backups')
        ->and($storage->remotePath('demo.dump'))->toBe('backups/demo.dump')
        ->and($storage->path('demo.dump'))->toEndWith('backups/demo.dump')
        ->and($storage->filesystem())->toBeInstanceOf(Filesystem::class)
        ->and($storage->disk())->toBe($storage->filesystem())
        ->and($storage->files())->toBe([]);
});

it('falls back to the default filesystem disk when backup disk is empty', function (): void {
    configurePgsqlBackup();

    $diskName = (string) config('filesystems.default');
    config(['database-backup.disk' => '']);

    $storage = BackupStorage::forConfiguredDisk();

    expect($storage->disk())->toBe(Storage::disk($diskName));
});

it('supports an empty backup directory', function (): void {
    configurePgsqlBackup();

    config(['database-backup.directory' => '']);

    $storage = BackupStorage::forConfiguredDisk();

    expect($storage->directory())->toBe('')
        ->and($storage->remotePath('/demo.dump'))->toBe('demo.dump')
        ->and($storage->path('\\demo.dump'))->toEndWith('demo.dump');
});

it('falls back when backup storage config values are not scalar', function (): void {
    configurePgsqlBackup();

    $diskName = (string) config('filesystems.default');

    config([
        'database-backup.disk' => ['not-a-string'],
        'database-backup.directory' => ['not-a-string'],
    ]);

    $storage = BackupStorage::forConfiguredDisk();

    expect($storage->disk())->toBe(Storage::disk($diskName))
        ->and($storage->directory())->toBe('backups');
});

it('rejects disks that are not filesystem adapters', function (): void {
    configurePgsqlBackup();

    $diskName = 'custom-non-adapter-'.getmypid();

    Storage::shouldReceive('disk')
        ->once()
        ->with($diskName)
        ->andReturn(Mockery::mock(Filesystem::class));

    config(['database-backup.disk' => $diskName]);

    BackupStorage::forConfiguredDisk();
})->throws(InvalidArgumentException::class);

it('resolves a pgsql connection for the strategy', function (): void {
    configurePgsqlBackup();

    $connection = app(ConnectionResolver::class)->resolve('pgsql');

    expect($connection->driver)->toBe('pgsql')
        ->and($connection->database())->toBe('testing');
});

it('fails when the connection does not exist', function (): void {
    configurePgsqlBackup();

    app(ConnectionResolver::class)->resolve('missing');
})->throws(InvalidArgumentException::class);

it('fails when the strategy does not support the connection driver', function (): void {
    configurePgsqlBackup();

    config([
        'database.connections.mysql' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'database' => 'testing',
            'username' => 'root',
            'password' => '',
        ],
    ]);

    app(ConnectionResolver::class)->resolveForStrategy(new PostgreSqlBackupStrategy, 'mysql');
})->throws(InvalidArgumentException::class);

it('falls back when the default connection name is not scalar', function (): void {
    configurePgsqlBackup();

    config([
        'database.default' => ['pgsql'],
        'database.connections.' => [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => '5432',
            'database' => 'testing',
            'username' => 'postgres',
            'password' => 'secret',
        ],
    ]);

    try {
        $connection = app(ConnectionResolver::class)->resolve();

        expect($connection->name)->toBe('')
            ->and($connection->driver)->toBe('pgsql');
    } finally {
        config(['database.default' => 'pgsql']);
    }
});
