<?php

declare(strict_types=1);

namespace IvanFuhr\LaravelDatabaseBackup\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use IvanFuhr\LaravelDatabaseBackup\BackupManager;
use IvanFuhr\LaravelDatabaseBackup\Commands\Concerns\InteractsWithCommandInput;
use IvanFuhr\LaravelDatabaseBackup\Exceptions\BackupFailedException;
use IvanFuhr\LaravelDatabaseBackup\Support\BackupStorage;

final class DatabaseBackupCommand extends Command
{
    use InteractsWithCommandInput;

    protected $signature = 'db:backup
        {--connection= : Database connection name}
        {--strategy= : Backup strategy name}';

    protected $description = 'Create a database backup using the configured strategy.';

    public function handle(BackupManager $manager): int
    {
        try {
            $strategy = $manager->strategy($this->optionalStringOption('strategy'));
            $connection = $manager->connectionFor(
                $this->optionalStringOption('connection'),
                $strategy->name(),
            );
            $storage = BackupStorage::forConfiguredDisk();
            $remotePath = $strategy->backup($connection, $storage);
        } catch (InvalidArgumentException|BackupFailedException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Backup created successfully at: %s', $remotePath));

        return self::SUCCESS;
    }
}
