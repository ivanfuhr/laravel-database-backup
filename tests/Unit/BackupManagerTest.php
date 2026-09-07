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

it('rejects custom creators that do not return a strategy', function (): void {
    configurePgsqlBackup();

    $manager = app(BackupManager::class);
    $manager->extend('broken', fn (): object => new stdClass);

    $manager->strategy('broken');
})->throws(UnsupportedStrategyException::class);

it('rejects cached drivers that are not strategies', function (): void {
    configurePgsqlBackup();

    $manager = app(BackupManager::class);
    $drivers = (new ReflectionClass($manager))->getProperty('drivers');
    $drivers->setValue($manager, ['pgsql' => new stdClass]);

    $manager->strategy('pgsql');
})->throws(UnsupportedStrategyException::class);

it('rejects strategies that are not configured as arrays', function (): void {
    configurePgsqlBackup();

    config(['database-backup.strategies.missing' => 'not-an-array']);

    app(BackupManager::class)->strategy('missing');
})->throws(InvalidArgumentException::class);

it('falls back when the default strategy config is not scalar', function (): void {
    configurePgsqlBackup();

    config(['database-backup.default' => ['pgsql']]);

    expect(app(BackupManager::class)->getDefaultDriver())->toBe('pgsql');
});

it('falls back when strategy config values are not scalar', function (): void {
    configurePgsqlBackup();

    config([
        'database-backup.strategies.pgsql.driver' => ['pgsql'],
        'database-backup.strategies.pgsql.pg_dump_binary' => ['pg_dump'],
        'database-backup.strategies.pgsql.pg_restore_binary' => ['pg_restore'],
    ]);

    app()->forgetInstance(BackupManager::class);

    expect(app(BackupManager::class)->strategy())->toBeInstanceOf(PostgreSqlBackupStrategy::class);
});

it('resolves a connection for a strategy', function (): void {
    configurePgsqlBackup();

    $connection = app(BackupManager::class)->connectionFor('pgsql', 'pgsql');

    expect($connection->driver)->toBe('pgsql')
        ->and($connection->database())->toBe('testing');
});

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

it('rejects connections without a driver', function (): void {
    DatabaseConnection::fromConfig('pgsql', [
        'host' => '127.0.0.1',
    ]);
})->throws(InvalidArgumentException::class);

it('rejects connections with an empty driver', function (): void {
    DatabaseConnection::fromConfig('pgsql', [
        'driver' => '',
    ]);
})->throws(InvalidArgumentException::class);

it('returns empty strings for non-scalar connection values', function (): void {
    $connection = DatabaseConnection::fromConfig('pgsql', [
        'driver' => 'pgsql',
        'host' => ['127.0.0.1'],
        'port' => null,
    ]);

    expect($connection->host())->toBe('')
        ->and($connection->port())->toBe('');
});
