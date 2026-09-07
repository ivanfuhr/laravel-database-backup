<?php

declare(strict_types=1);

use IvanFuhr\LaravelDatabaseBackup\BackupManager;
use IvanFuhr\LaravelDatabaseBackup\Contracts\DatabaseBackupStrategy;
use IvanFuhr\LaravelDatabaseBackup\Exceptions\UnsupportedStrategyException;
use IvanFuhr\LaravelDatabaseBackup\Strategies\PostgreSqlBackupStrategy;
use IvanFuhr\LaravelDatabaseBackup\Support\BackupStorage;
use IvanFuhr\LaravelDatabaseBackup\Support\DatabaseConnection;

it('resolves the default pgsql strategy', function (): void {
    configurePgsqlBackup();

    $strategy = app(BackupManager::class)->strategy();

    expect($strategy)->toBeInstanceOf(PostgreSqlBackupStrategy::class)
        ->and($strategy->name())->toBe('pgsql')
        ->and($strategy->supports('pgsql'))->toBeTrue()
        ->and($strategy->supports('mysql'))->toBeFalse();
});

it('allows extending the manager with a custom strategy', function (): void {
    configurePgsqlBackup();

    $custom = new class implements DatabaseBackupStrategy
    {
        public function name(): string
        {
            return 'custom';
        }

        public function supports(string $driver): bool
        {
            return $driver === 'pgsql';
        }

        public function backup(DatabaseConnection $connection, BackupStorage $storage, ?string $filename = null): string
        {
            return 'custom.dump';
        }

        public function restore(DatabaseConnection $connection, string $localBackupPath): void {}
    };

    $manager = app(BackupManager::class);
    $manager->extend('custom', fn (): DatabaseBackupStrategy => $custom);

    expect($manager->strategy('custom'))->toBe($custom);
});

it('rejects unknown strategy drivers', function (): void {
    configurePgsqlBackup();

    config([
        'database-backup.strategies.sqlite' => [
            'driver' => 'sqlite',
        ],
    ]);

    app(BackupManager::class)->strategy('sqlite');
})->throws(UnsupportedStrategyException::class);

it('builds a database connection value object', function (): void {
    $connection = DatabaseConnection::fromConfig('pgsql', [
        'driver' => 'pgsql',
        'host' => '127.0.0.1',
        'port' => 5432,
        'database' => 'app',
        'username' => 'user',
        'password' => 'secret',
    ]);

    expect($connection->name)->toBe('pgsql')
        ->and($connection->driver)->toBe('pgsql')
        ->and($connection->host())->toBe('127.0.0.1')
        ->and($connection->port())->toBe('5432')
        ->and($connection->database())->toBe('app')
        ->and($connection->username())->toBe('user')
        ->and($connection->password())->toBe('secret');
});
