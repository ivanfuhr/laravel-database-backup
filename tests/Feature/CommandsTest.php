<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use IvanFuhr\LaravelDatabaseBackup\DatabaseBackupServiceProvider;

beforeEach(function (): void {
    configurePgsqlBackup();
});

it('registers package artisan commands', function (): void {
    $commands = app()->make(Illuminate\Contracts\Console\Kernel::class)->all();

    expect($commands)->toHaveKeys([
        'db:backup',
        'db:restore',
        'db:backups:list',
        'db:backups:prune',
    ]);
});

it('registers the service provider', function (): void {
    expect(app()->providerIsLoaded(DatabaseBackupServiceProvider::class))->toBeTrue();
});

it('creates a backup with the fake pg_dump binary', function (): void {
    $exitCode = Artisan::call('db:backup');

    expect($exitCode)->toBe(0)
        ->and(backupDisk()->allFiles('backups'))->not->toBeEmpty();
});

it('fails when the connection is missing', function (): void {
    $exitCode = Artisan::call('db:backup', ['--connection' => 'missing']);

    expect($exitCode)->toBe(1);
});

it('fails when pg_dump exits with an error', function (): void {
    config(['database-backup.strategies.pgsql.pg_dump_binary' => createFailingPgDumpScript()]);

    // Reset manager cached drivers
    app()->forgetInstance(IvanFuhr\LaravelDatabaseBackup\BackupManager::class);

    $exitCode = Artisan::call('db:backup');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('pg_dump failed');
});

it('lists backups on the configured disk', function (): void {
    Artisan::call('db:backup');

    $exitCode = Artisan::call('db:backups:list');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('.dump');
});

it('prunes old backups based on retention', function (): void {
    Artisan::call('db:backup');
    $files = backupDisk()->allFiles('backups');
    expect($files)->not->toBeEmpty();

    $exitCode = Artisan::call('db:backups:prune', ['--days' => 0]);

    expect($exitCode)->toBe(0);

    // retention 0 means nothing deleted by command design
    $exitCode = Artisan::call('db:backups:prune', ['--days' => -1]);
    expect($exitCode)->toBe(0);
});

it('restores a backup with fake binaries', function (): void {
    Artisan::call('db:backup');
    $file = basename(backupDisk()->allFiles('backups')[0]);

    withPromptFallbacks(
        confirm: fn (): bool => false,
    );

    $exitCode = Artisan::call('db:restore', [
        'backup' => $file,
        '--force' => true,
    ]);

    resetPromptFallbacks();

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Restore completed successfully');
});
