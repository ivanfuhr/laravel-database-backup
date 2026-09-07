<?php

declare(strict_types=1);

namespace IvanFuhr\LaravelDatabaseBackup\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use IvanFuhr\LaravelDatabaseBackup\Commands\Concerns\InteractsWithCommandInput;
use IvanFuhr\LaravelDatabaseBackup\Support\BackupStorage;

final class DatabasePruneBackupsCommand extends Command
{
    use InteractsWithCommandInput;

    protected $signature = 'db:backups:prune {--days= : Override retention (in days)}';

    protected $description = 'Remove backups older than the configured retention.';

    public function handle(): int
    {
        $retentionDays = $this->resolveRetentionDays();

        if ($retentionDays <= 0) {
            $this->info('No backups will be removed because retention is zero or negative.');

            return self::SUCCESS;
        }

        $cutoff = Carbon::now()->subDays($retentionDays);
        $storage = BackupStorage::forConfiguredDisk();
        $disk = $storage->disk();
        $files = $storage->files();

        if ($files === []) {
            $this->info('No backups found for removal.');

            return self::SUCCESS;
        }

        $deleted = 0;

        foreach ($files as $file) {
            if ($disk->lastModified($file) < $cutoff->getTimestamp()) {
                $disk->delete($file);
                $deleted++;
            }
        }

        $this->info(sprintf('Prune completed. Files removed: %d', $deleted));

        return self::SUCCESS;
    }

    private function resolveRetentionDays(): int
    {
        $option = $this->option('days');

        if (is_numeric($option)) {
            return (int) $option;
        }

        $retention = config('database-backup.retention_days', 30);

        return is_numeric($retention) ? (int) $retention : 30;
    }
}
