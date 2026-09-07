<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use IvanFuhr\LaravelDatabaseBackup\BackupManager;
use IvanFuhr\LaravelDatabaseBackup\DatabaseBackupServiceProvider;
use IvanFuhr\LaravelDatabaseBackup\Support\BackupStorage;

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

    app()->forgetInstance(BackupManager::class);

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

it('reports when no backups are available to list', function (): void {
    $exitCode = Artisan::call('db:backups:list');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('No backups found on the configured disk.');
});

it('prunes old backups based on retention', function (): void {
    Artisan::call('db:backup');
    $files = backupDisk()->allFiles('backups');
    expect($files)->not->toBeEmpty();

    $exitCode = Artisan::call('db:backups:prune', ['--days' => 0]);

    expect($exitCode)->toBe(0);

    $exitCode = Artisan::call('db:backups:prune', ['--days' => -1]);
    expect($exitCode)->toBe(0);
});

it('reports when there are no backups to prune', function (): void {
    $exitCode = Artisan::call('db:backups:prune', ['--days' => 7]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('No backups found for removal.');
});

it('deletes backups older than the retention window', function (): void {
    Artisan::call('db:backup');
    $file = backupDisk()->allFiles('backups')[0];

    // Force the stored file timestamp into the past for prune cutoff checks.
    $absolute = BackupStorage::forConfiguredDisk()->path(basename($file));
    touch($absolute, now()->subDays(10)->getTimestamp());

    $exitCode = Artisan::call('db:backups:prune', ['--days' => 7]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Files removed: 1')
        ->and(backupDisk()->allFiles('backups'))->toBeEmpty();
});

it('uses configured retention days when the option is omitted', function (): void {
    Artisan::call('db:backup');
    $file = backupDisk()->allFiles('backups')[0];
    $absolute = BackupStorage::forConfiguredDisk()->path(basename($file));
    touch($absolute, now()->subDays(40)->getTimestamp());

    config(['database-backup.retention_days' => 30]);

    $exitCode = Artisan::call('db:backups:prune');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Files removed: 1');
});

it('falls back to thirty days when retention config is not numeric', function (): void {
    Artisan::call('db:backup');
    $file = backupDisk()->allFiles('backups')[0];
    $absolute = BackupStorage::forConfiguredDisk()->path(basename($file));
    touch($absolute, now()->subDays(40)->getTimestamp());

    config(['database-backup.retention_days' => 'not-numeric']);

    $exitCode = Artisan::call('db:backups:prune');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Files removed: 1');
});

it('restores a backup with fake binaries', function (): void {
    Artisan::call('db:backup');
    $file = basename(backupDisk()->allFiles('backups')[0]);

    $exitCode = Artisan::call('db:restore', [
        'backup' => $file,
        '--force' => true,
    ]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Restore completed successfully');
});

it('restores from an absolute backup path', function (): void {
    Artisan::call('db:backup');
    $remote = backupDisk()->allFiles('backups')[0];
    $absolute = storage_path('framework/testing/absolute-'.getmypid().'.dump');
    file_put_contents($absolute, backupDisk()->get($remote));

    $exitCode = Artisan::call('db:restore', [
        'backup' => $absolute,
        '--force' => true,
    ]);

    @unlink($absolute);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Restore completed successfully');
});

it('fails when an absolute backup path is missing', function (): void {
    $exitCode = Artisan::call('db:restore', [
        'backup' => storage_path('framework/testing/missing-absolute-'.getmypid().'.dump'),
        '--force' => true,
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Backup file not found:');
});

it('fails when a relative backup path is missing and lists alternatives', function (): void {
    Artisan::call('db:backup');
    expect(backupDisk()->allFiles('backups'))->not->toBeEmpty();

    $exitCode = Artisan::call('db:restore', [
        'backup' => 'does-not-exist.dump',
        '--force' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Backup file not found on disk:')
        ->and($output)->toContain('Available backups:');
});

it('warns when a relative backup is missing and no backups exist', function (): void {
    $exitCode = Artisan::call('db:restore', [
        'backup' => 'does-not-exist.dump',
        '--force' => true,
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('No backups found on the configured disk.');
});

it('cancels restore when confirmation is declined', function (): void {
    Artisan::call('db:backup');
    $file = basename(backupDisk()->allFiles('backups')[0]);

    $this->artisan('db:restore', ['backup' => $file])
        ->expectsConfirmation('Do you want to create a safety backup before restoring?', 'no')
        ->expectsConfirmation('This operation will replace the current database. Continue?', 'no')
        ->expectsOutputToContain('Restore cancelled.')
        ->assertExitCode(0);
});

it('creates a safety backup before restoring when confirmed', function (): void {
    $now = now();
    Illuminate\Support\Carbon::setTestNow($now);

    Artisan::call('db:backup');
    $file = basename(backupDisk()->allFiles('backups')[0]);
    $before = count(backupDisk()->allFiles('backups'));

    Illuminate\Support\Carbon::setTestNow($now->copy()->addSecond());

    $this->artisan('db:restore', ['backup' => $file])
        ->expectsConfirmation('Do you want to create a safety backup before restoring?', 'yes')
        ->expectsConfirmation('This operation will replace the current database. Continue?', 'yes')
        ->expectsOutputToContain('Restore completed successfully')
        ->assertExitCode(0);

    Illuminate\Support\Carbon::setTestNow();

    expect(count(backupDisk()->allFiles('backups')))->toBeGreaterThan($before);
});

it('aborts restore when the safety backup fails', function (): void {
    Artisan::call('db:backup');
    $file = basename(backupDisk()->allFiles('backups')[0]);

    config(['database-backup.strategies.pgsql.pg_dump_binary' => createFailingPgDumpScript()]);
    app()->forgetInstance(BackupManager::class);

    $this->artisan('db:restore', ['backup' => $file])
        ->expectsConfirmation('Do you want to create a safety backup before restoring?', 'yes')
        ->expectsOutputToContain('Safety backup failed; restore aborted.')
        ->assertExitCode(1);
});

it('reports restore failures from the strategy', function (): void {
    Artisan::call('db:backup');
    $file = basename(backupDisk()->allFiles('backups')[0]);

    config(['database-backup.strategies.pgsql.pg_restore_binary' => createFailingPgRestoreScript()]);
    app()->forgetInstance(BackupManager::class);

    $exitCode = Artisan::call('db:restore', [
        'backup' => $file,
        '--force' => true,
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('pg_restore failed');
});

it('selects a backup interactively when none is provided', function (): void {
    Artisan::call('db:backup');
    $remotePath = backupDisk()->allFiles('backups')[0];
    $label = basename($remotePath).' ('.now()->createFromTimestamp(backupDisk()->lastModified($remotePath))->toDateTimeString().')';

    $this->artisan('db:restore', ['--force' => true])
        ->expectsChoice('Select a backup to restore', $remotePath, [
            $remotePath => $label,
        ])
        ->expectsOutputToContain('Restore completed successfully')
        ->assertExitCode(0);
});

it('fails interactive restore when no backups exist', function (): void {
    $exitCode = Artisan::call('db:restore', [
        '--force' => true,
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('No backups found on the configured disk.');
});

it('treats windows-style paths as absolute restore paths', function (): void {
    $exitCode = Artisan::call('db:restore', [
        'backup' => 'C:\\missing\\backup.dump',
        '--force' => true,
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Backup file not found:');
});

it('creates the temporary directory when downloading a remote backup', function (): void {
    Artisan::call('db:backup');
    $file = basename(backupDisk()->allFiles('backups')[0]);

    $tempDir = storage_path('app/tmp');

    if (is_dir($tempDir)) {
        foreach (glob($tempDir.'/*') ?: [] as $tempFile) {
            @unlink($tempFile);
        }

        @rmdir($tempDir);
    }

    $exitCode = Artisan::call('db:restore', [
        'backup' => $file,
        '--force' => true,
    ]);

    expect($exitCode)->toBe(0)
        ->and(is_dir($tempDir))->toBeTrue();
});

it('fails when the backup stream cannot be read from disk', function (): void {
    configurePgsqlBackup();

    $diskName = (string) config('database-backup.disk');
    $mock = Mockery::mock(Illuminate\Filesystem\FilesystemAdapter::class);
    $mock->shouldReceive('exists')->andReturn(true);
    $mock->shouldReceive('readStream')->once()->andReturn(false);

    Illuminate\Support\Facades\Storage::shouldReceive('disk')
        ->with($diskName)
        ->andReturn($mock);

    $exitCode = Artisan::call('db:restore', [
        'backup' => 'missing-stream.dump',
        '--force' => true,
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Failed to read backup file from disk.');
});

it('fails when a temporary restore file cannot be created', function (): void {
    Artisan::call('db:backup');
    $file = basename(backupDisk()->allFiles('backups')[0]);

    $tempDir = storage_path('app/tmp');

    if (is_dir($tempDir)) {
        foreach (glob($tempDir.'/*') ?: [] as $tempFile) {
            @unlink($tempFile);
        }

        @rmdir($tempDir);
    } elseif (is_file($tempDir)) {
        @unlink($tempDir);
    }

    // Force temporaryPath() to build under a non-directory path so fopen fails.
    file_put_contents($tempDir, 'not-a-directory');

    $exitCode = Artisan::call('db:restore', [
        'backup' => $file,
        '--force' => true,
    ]);
    $output = Artisan::output();

    @unlink($tempDir);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Failed to create temporary file for restore.');
});
