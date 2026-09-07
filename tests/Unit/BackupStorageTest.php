<?php

declare(strict_types=1);

use IvanFuhr\LaravelDatabaseBackup\Support\BackupStorage;
use IvanFuhr\LaravelDatabaseBackup\Support\ConnectionResolver;

it('creates backup storage for the configured disk', function (): void {
    configurePgsqlBackup();

    $storage = BackupStorage::forConfiguredDisk();

    expect($storage->directory())->toBe('backups')
        ->and($storage->remotePath('demo.dump'))->toBe('backups/demo.dump')
        ->and($storage->files())->toBe([]);
});

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
