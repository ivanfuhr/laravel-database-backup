<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use IvanFuhr\LaravelDatabaseBackup\BackupManager;
use Testcontainers\Modules\PostgresContainer;

uses()->group('integration');

beforeEach(function (): void {
    if (! class_exists(PostgresContainer::class)) {
        $this->markTestSkipped('testcontainers/testcontainers is not installed.');
    }

    static $container = null;
    static $wrappers = null;

    if ($container === null) {
        $container = (new PostgresContainer('17-alpine', 'postgres', 'secret', 'backup_test'))->start();
        $wrappers = createDockerPostgresClientWrappers($container->getId());
    }

    $host = $container->getHost();
    $port = (string) $container->getFirstMappedPort();

    $diskName = 'integration-'.getmypid();
    $diskRoot = storage_path('framework/testing/disks/'.$diskName);
    ensureTestingDirectoryExists($diskRoot);

    config([
        'database.default' => 'pgsql',
        'database.connections.pgsql' => [
            'driver' => 'pgsql',
            'host' => $host,
            'port' => $port,
            'database' => 'backup_test',
            'username' => 'postgres',
            'password' => 'secret',
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
            'sslmode' => 'prefer',
        ],
        'database-backup.default' => 'pgsql',
        'database-backup.disk' => $diskName,
        'database-backup.directory' => 'backups',
        'database-backup.strategies.pgsql.pg_dump_binary' => $wrappers['pg_dump'],
        'database-backup.strategies.pgsql.pg_restore_binary' => $wrappers['pg_restore'],
        'filesystems.default' => $diskName,
        'filesystems.disks.'.$diskName => [
            'driver' => 'local',
            'root' => $diskRoot,
        ],
    ]);

    app()->forgetInstance(BackupManager::class);

    DB::purge('pgsql');
    DB::reconnect('pgsql');

    DB::statement('DROP TABLE IF EXISTS backup_items');
    DB::statement('CREATE TABLE backup_items (id SERIAL PRIMARY KEY, name TEXT NOT NULL)');
    DB::table('backup_items')->insert(['name' => 'alpha']);
    DB::table('backup_items')->insert(['name' => 'beta']);
});

it('backs up and restores a real PostgreSQL database', function (): void {
    $exitCode = Artisan::call('db:backup');
    expect($exitCode)->toBe(0);

    $files = backupDisk()->allFiles('backups');
    expect($files)->not->toBeEmpty();

    $backupName = basename($files[0]);

    DB::table('backup_items')->delete();
    expect(DB::table('backup_items')->count())->toBe(0);

    $exitCode = Artisan::call('db:restore', [
        'backup' => $backupName,
        '--force' => true,
    ]);

    expect($exitCode)->toBe(0)
        ->and(DB::table('backup_items')->orderBy('id')->pluck('name')->all())
        ->toBe(['alpha', 'beta']);
});

it('backs up and restores through the strategy API', function (): void {
    $manager = app(BackupManager::class);
    $strategy = $manager->strategy();
    $connection = $manager->connectionFor();
    $storage = IvanFuhr\LaravelDatabaseBackup\Support\BackupStorage::forConfiguredDisk();

    $remotePath = $strategy->backup($connection, $storage, 'manual.dump');
    expect($remotePath)->toBe('backups/manual.dump')
        ->and(backupDisk()->exists($remotePath))->toBeTrue();

    DB::table('backup_items')->where('name', 'beta')->delete();
    expect(DB::table('backup_items')->count())->toBe(1);

    $local = storage_path('framework/testing/manual-restore-'.getmypid().'.dump');
    file_put_contents($local, backupDisk()->get($remotePath));

    $strategy->restore($connection, $local);
    @unlink($local);

    expect(DB::table('backup_items')->orderBy('id')->pluck('name')->all())
        ->toBe(['alpha', 'beta']);
});
