<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

function ensureTestingDirectoryExists(string $path): void
{
    if (is_dir($path)) {
        return;
    }

    @mkdir($path, 0755, true);

    if (! is_dir($path)) {
        throw new RuntimeException(sprintf('Failed to create testing directory: %s', $path));
    }
}

function createFakePgDumpScript(): string
{
    $path = storage_path('framework/testing/bin-'.getmypid().'/fake-pg-dump.sh');

    ensureTestingDirectoryExists(dirname($path));

    File::put($path, <<<'BASH'
        #!/usr/bin/env bash
        for arg in "$@"; do
          case "$arg" in
            --file=*) echo "backup" > "${arg#--file=}" ;;
          esac
        done
        exit 0
        BASH);

    chmod($path, 0755);

    return $path;
}

function createFakePgRestoreScript(): string
{
    $path = storage_path('framework/testing/bin-'.getmypid().'/fake-pg-restore.sh');

    ensureTestingDirectoryExists(dirname($path));

    File::put($path, <<<'BASH'
        #!/usr/bin/env bash
        exit 0
        BASH);

    chmod($path, 0755);

    return $path;
}

function createFailingPgDumpScript(): string
{
    $path = storage_path('framework/testing/bin-'.getmypid().'/failing-pg-dump.sh');

    ensureTestingDirectoryExists(dirname($path));

    File::put($path, <<<'BASH'
        #!/usr/bin/env bash
        echo "pg_dump failed" >&2
        exit 1
        BASH);

    chmod($path, 0755);

    return $path;
}

function createUnreadablePgDumpScript(): string
{
    $path = storage_path('framework/testing/bin-'.getmypid().'/unreadable-pg-dump.sh');

    ensureTestingDirectoryExists(dirname($path));

    File::put($path, <<<'BASH'
        #!/usr/bin/env bash
        for arg in "$@"; do
          case "$arg" in
            --file=*) echo "backup" > "${arg#--file=}" && chmod 000 "${arg#--file=}" ;;
          esac
        done
        exit 0
        BASH);

    chmod($path, 0755);

    return $path;
}

function createUnsupportedVersionPgRestoreScript(): string
{
    $path = storage_path('framework/testing/bin-'.getmypid().'/unsupported-pg-restore.sh');

    ensureTestingDirectoryExists(dirname($path));

    File::put($path, <<<'BASH'
        #!/usr/bin/env bash
        echo "pg_restore: error: unsupported version" >&2
        exit 1
        BASH);

    chmod($path, 0755);

    return $path;
}

function createFailingPgRestoreScript(): string
{
    $path = storage_path('framework/testing/bin-'.getmypid().'/failing-pg-restore.sh');

    ensureTestingDirectoryExists(dirname($path));

    File::put($path, <<<'BASH'
        #!/usr/bin/env bash
        echo "pg_restore failed" >&2
        exit 1
        BASH);

    chmod($path, 0755);

    return $path;
}

function backupDisk(): Illuminate\Contracts\Filesystem\Filesystem
{
    $diskName = config('database-backup.disk');

    if (! is_string($diskName) || $diskName === '') {
        $diskName = config('filesystems.default', 'local');
    }

    return Storage::disk(is_string($diskName) ? $diskName : 'local');
}

function configurePgsqlBackup(?string $pgDump = null, ?string $pgRestore = null): void
{
    $diskName = 'local-'.getmypid();
    $diskRoot = storage_path('framework/testing/disks/'.$diskName);

    config([
        'database.default' => 'pgsql',
        'database.connections.pgsql' => [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => '5432',
            'database' => 'testing',
            'username' => 'postgres',
            'password' => 'secret',
        ],
        'database-backup.default' => 'pgsql',
        'database-backup.disk' => $diskName,
        'database-backup.directory' => 'backups',
        'database-backup.strategies.pgsql.pg_dump_binary' => $pgDump ?? createFakePgDumpScript(),
        'database-backup.strategies.pgsql.pg_restore_binary' => $pgRestore ?? createFakePgRestoreScript(),
        'filesystems.default' => $diskName,
        'filesystems.disks.'.$diskName => [
            'driver' => 'local',
            'root' => $diskRoot,
        ],
    ]);

    Storage::fake($diskName, ['root' => $diskRoot]);
}

/**
 * Create host wrappers that invoke pg_dump/pg_restore inside a Docker network
 * shared with the Testcontainers Postgres instance (no local client required).
 *
 * @return array{pg_dump: string, pg_restore: string}
 */
function createDockerPostgresClientWrappers(string $containerId): array
{
    $dir = storage_path('framework/testing/bin-'.getmypid().'/docker-pg');
    ensureTestingDirectoryExists($dir);

    $pgDump = $dir.'/pg_dump';
    $pgRestore = $dir.'/pg_restore';

    File::put($pgDump, <<<BASH
        #!/usr/bin/env bash
        set -euo pipefail
        CONTAINER_ID={$containerId}
        ARGS=()
        FILE=""
        for arg in "\$@"; do
          case "\$arg" in
            --host=*) ARGS+=("--host=127.0.0.1") ;;
            --port=*) ARGS+=("--port=5432") ;;
            --file=*) FILE="\${arg#--file=}" ;;
            *) ARGS+=("\$arg") ;;
          esac
        done
        if [[ -n "\$FILE" ]]; then
          docker exec -e PGPASSWORD="\${PGPASSWORD:-}" "\$CONTAINER_ID" pg_dump "\${ARGS[@]}" --file=/tmp/laravel-db-backup.dump
          docker cp "\$CONTAINER_ID:/tmp/laravel-db-backup.dump" "\$FILE"
        else
          docker exec -e PGPASSWORD="\${PGPASSWORD:-}" "\$CONTAINER_ID" pg_dump "\${ARGS[@]}"
        fi
        BASH);

    File::put($pgRestore, <<<BASH
        #!/usr/bin/env bash
        set -euo pipefail
        CONTAINER_ID={$containerId}
        ARGS=()
        FILE=""
        for arg in "\$@"; do
          case "\$arg" in
            --host=*) ARGS+=("--host=127.0.0.1") ;;
            --port=*) ARGS+=("--port=5432") ;;
            *)
              if [[ "\$arg" == -* ]]; then
                ARGS+=("\$arg")
              else
                FILE="\$arg"
              fi
              ;;
          esac
        done
        if [[ -z "\$FILE" ]]; then
          echo "pg_restore wrapper: missing backup file" >&2
          exit 1
        fi
        docker cp "\$FILE" "\$CONTAINER_ID:/tmp/laravel-db-restore.dump"
        docker exec -e PGPASSWORD="\${PGPASSWORD:-}" "\$CONTAINER_ID" pg_restore "\${ARGS[@]}" /tmp/laravel-db-restore.dump
        BASH);

    chmod($pgDump, 0755);
    chmod($pgRestore, 0755);

    return [
        'pg_dump' => $pgDump,
        'pg_restore' => $pgRestore,
    ];
}
