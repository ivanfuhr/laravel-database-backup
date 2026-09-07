# Laravel Database Backup

Database backup and restore for Laravel applications, extracted as a focused package with pluggable strategies.

Composer: `ivanfuhr/laravel-database-backup`

## Features

- Artisan commands: `db:backup`, `db:restore`, `db:backups:list`, `db:backups:prune`
- Strategy pattern with a built-in **PostgreSQL** driver (`pg_dump` / `pg_restore`)
- Extensible manager (same idea as filesystem disks / cache stores)
- Publishable config for disk, directory, retention, and binary paths
- Ready for future native MySQL / SQLite strategies

## Requirements

- PHP 8.3+
- Laravel 11, 12, or 13
- PostgreSQL client tools (`pg_dump`, `pg_restore`) on the host that runs backups

## Installation

```bash
composer require ivanfuhr/laravel-database-backup
```

Publish the config (optional):

```bash
php artisan vendor:publish --tag=database-backup-config
```

## Usage

```bash
php artisan db:backup
php artisan db:backup --connection=pgsql --strategy=pgsql

php artisan db:restore
php artisan db:restore yesterday.dump --force

php artisan db:backups:list
php artisan db:backups:prune
php artisan db:backups:prune --days=7
```

## Configuration

```php
// config/database-backup.php
return [
    'default' => env('DB_BACKUP_STRATEGY', 'pgsql'),
    'disk' => env('DB_BACKUP_DISK'),
    'directory' => env('DB_BACKUP_DIRECTORY', 'backups'),
    'retention_days' => (int) env('DB_BACKUP_RETENTION_DAYS', 30),
    'strategies' => [
        'pgsql' => [
            'driver' => 'pgsql',
            'pg_dump_binary' => env('PG_DUMP_PATH', 'pg_dump'),
            'pg_restore_binary' => env('PG_RESTORE_PATH', 'pg_restore'),
        ],
    ],
];
```

## Custom strategies

Implement `IvanFuhr\LaravelDatabaseBackup\Contracts\DatabaseBackupStrategy` and register it:

```php
use IvanFuhr\LaravelDatabaseBackup\BackupManager;
use IvanFuhr\LaravelDatabaseBackup\Contracts\DatabaseBackupStrategy;

public function boot(BackupManager $backups): void
{
    $backups->extend('mysql', function (): DatabaseBackupStrategy {
        return new MySqlBackupStrategy();
    });
}
```

Then set `DB_BACKUP_STRATEGY=mysql` (and add a matching entry under `strategies` if you resolve config for it), or call:

```bash
php artisan db:backup --strategy=mysql
```

Programmatic API:

```php
use IvanFuhr\LaravelDatabaseBackup\BackupManager;
use IvanFuhr\LaravelDatabaseBackup\Support\BackupStorage;

$manager = app(BackupManager::class);
$strategy = $manager->strategy();
$connection = $manager->connectionFor();
$path = $strategy->backup($connection, BackupStorage::forConfiguredDisk());
```

## Testing

```bash
composer test          # rector dry-run + pint + phpstan + pest
composer test:unit     # unit/feature (excludes integration group)
composer test:integration
composer test:coverage # pest with PCOV/Xdebug; fails below 100%
```

CI installs [PCOV](https://github.com/krakjoe/pcov) via `shivammathur/setup-php` and enforces 100% coverage of `src/`. Locally, enable PCOV or Xdebug, then run `composer test:coverage`.

Integration tests use [Testcontainers](https://github.com/testcontainers/testcontainers-php) against a real PostgreSQL 17 container. Docker must be available. Client tools are invoked via wrappers that `docker exec` into the container when local `pg_dump` / `pg_restore` are not installed.

## Security

This repository includes `SECURITY.md`, Dependabot, SHA-pinned GitHub Actions, restricted workflow permissions, and a `moat.toml` for [Laravel Moat](https://github.com/laravel/moat) audits:

```bash
moat ivanfuhr/laravel-database-backup
```

## License

MIT
