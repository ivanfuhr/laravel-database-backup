<?php

declare(strict_types=1);

namespace IvanFuhr\LaravelDatabaseBackup\Commands;

use Illuminate\Console\Command;
use IvanFuhr\LaravelDatabaseBackup\Support\BackupStorage;

final class DatabaseListBackupsCommand extends Command
{
    protected $signature = 'db:backups:list';

    protected $description = 'List available database backups on the configured disk.';

    public function handle(): int
    {
        $storage = BackupStorage::forConfiguredDisk();
        $disk = $storage->disk();
        $files = $storage->files();

        if ($files === []) {
            $this->info('No backups found on the configured disk.');

            return self::SUCCESS;
        }

        $rows = collect($files)
            ->map(fn (string $file): array => [
                'file' => $file,
                'timestamp' => $disk->lastModified($file),
            ])
            ->sortByDesc('timestamp')
            ->map(fn (array $entry): array => [
                basename($entry['file']),
                $entry['file'],
                now()->createFromTimestamp($entry['timestamp'])->toDateTimeString(),
            ])
            ->values()
            ->all();

        $this->table(['Name', 'Path', 'Modified'], $rows);

        return self::SUCCESS;
    }
}
