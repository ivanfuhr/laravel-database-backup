<?php

declare(strict_types=1);

namespace IvanFuhr\LaravelDatabaseBackup\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use IvanFuhr\LaravelDatabaseBackup\BackupManager;
use IvanFuhr\LaravelDatabaseBackup\Commands\Concerns\InteractsWithCommandInput;
use IvanFuhr\LaravelDatabaseBackup\Exceptions\BackupFailedException;
use IvanFuhr\LaravelDatabaseBackup\Exceptions\RestoreFailedException;
use IvanFuhr\LaravelDatabaseBackup\Support\BackupStorage;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

final class DatabaseRestoreCommand extends Command
{
    use InteractsWithCommandInput;

    protected $signature = 'db:restore
        {backup? : Absolute path or filename inside the backups folder}
        {--connection= : Database connection name}
        {--strategy= : Backup strategy name}
        {--force : Skip confirmation}';

    protected $description = 'Restore a database backup using the configured strategy.';

    public function handle(BackupManager $manager): int
    {
        $input = $this->optionalStringArgument('backup');

        try {
            $strategy = $manager->strategy($this->optionalStringOption('strategy'));
            $connection = $manager->connectionFor(
                $this->optionalStringOption('connection'),
                $strategy->name(),
            );

            $input ??= $this->selectBackup(BackupStorage::forConfiguredDisk());

            $backupFile = $this->resolveLocalBackupPath($input);
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->error($invalidArgumentException->getMessage());

            return self::FAILURE;
        }

        if (! $this->runSafetyBackup()) {
            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('This operation will replace the current database. Continue?')) {
            $this->info('Restore cancelled.');

            return self::SUCCESS;
        }

        try {
            $strategy->restore($connection, $backupFile);
        } catch (RestoreFailedException $restoreFailedException) {
            $this->error($restoreFailedException->getMessage());
            $this->cleanupTemp($backupFile, $input);

            return self::FAILURE;
        }

        $this->cleanupTemp($backupFile, $input);
        $this->info('Restore completed successfully.');

        return self::SUCCESS;
    }

    private function resolveLocalBackupPath(string $path): string
    {
        if ($this->isAbsolutePath($path)) {
            if (! is_file($path)) {
                throw new InvalidArgumentException(sprintf('Backup file not found: %s', $path));
            }

            return $path;
        }

        $storage = BackupStorage::forConfiguredDisk();
        $disk = $storage->disk();
        $remotePath = $storage->remotePath($path);

        if (! $disk->exists($remotePath)) {
            $this->listAvailableBackups($storage, $path);

            throw new InvalidArgumentException(sprintf('Backup file not found on disk: %s', $remotePath));
        }

        $stream = $disk->readStream($remotePath);

        if (! is_resource($stream)) {
            throw new InvalidArgumentException('Failed to read backup file from disk.');
        }

        $tempPath = $this->temporaryPath(basename($remotePath));
        $target = @fopen($tempPath, 'w');

        if ($target === false) {
            fclose($stream);

            throw new InvalidArgumentException('Failed to create temporary file for restore.');
        }

        stream_copy_to_stream($stream, $target);
        fclose($stream);
        fclose($target);

        return $tempPath;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:\\\\/', $path) === 1;
    }

    private function temporaryPath(string $filename): string
    {
        $tempDir = storage_path('app/tmp');

        if (! is_dir($tempDir)) {
            @mkdir($tempDir, 0755, true);
        }

        return $tempDir.DIRECTORY_SEPARATOR.getmypid().'-'.$filename;
    }

    private function cleanupTemp(string $backupFile, string $input): void
    {
        if ($this->isAbsolutePath($input)) {
            return;
        }

        if (is_file($backupFile)) {
            @unlink($backupFile);
        }
    }

    private function listAvailableBackups(BackupStorage $storage, string $query): void
    {
        $files = $storage->files();

        if ($files === []) {
            $this->warn('No backups found on the configured disk.');

            return;
        }

        $matches = array_values(array_filter(
            $files,
            static fn (string $file): bool => str_contains(basename($file), $query)
        ));

        $list = $matches !== [] ? $matches : $files;
        $list = array_slice($list, 0, 15);

        $this->warn('Backup file not found. Available backups:');

        foreach ($list as $file) {
            $this->line('- '.$file);
        }
    }

    private function selectBackup(BackupStorage $storage): string
    {
        $disk = $storage->disk();
        $files = $storage->files();

        if ($files === []) {
            throw new InvalidArgumentException('No backups found on the configured disk.');
        }

        $ordered = collect($files)
            ->map(fn (string $file): array => [
                'file' => $file,
                'timestamp' => $disk->lastModified($file),
            ])
            ->sortByDesc('timestamp')
            ->values()
            ->all();

        $options = [];

        foreach ($ordered as $entry) {
            $file = $entry['file'];
            $time = $entry['timestamp'];

            $label = basename($file).' ('.now()->createFromTimestamp($time)->toDateTimeString().')';
            $options[$file] = $label;
        }

        $selected = select(
            label: 'Select a backup to restore',
            options: $options,
        );

        return basename(is_string($selected) ? $selected : (string) $selected);
    }

    private function runSafetyBackup(): bool
    {
        if ($this->input !== null && $this->option('force')) {
            return true;
        }

        if (! confirm('Do you want to create a safety backup before restoring?', default: true)) {
            return true;
        }

        try {
            $result = $this->call('db:backup', [
                '--connection' => $this->optionalStringOption('connection'),
                '--strategy' => $this->optionalStringOption('strategy'),
            ]);
        } catch (BackupFailedException) {
            $this->error('Safety backup failed; restore aborted.');

            return false;
        }

        if ($result !== self::SUCCESS) {
            $this->error('Safety backup failed; restore aborted.');

            return false;
        }

        return true;
    }
}
