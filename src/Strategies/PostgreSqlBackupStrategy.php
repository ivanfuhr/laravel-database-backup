<?php

declare(strict_types=1);

namespace IvanFuhr\LaravelDatabaseBackup\Strategies;

use IvanFuhr\LaravelDatabaseBackup\Contracts\DatabaseBackupStrategy;
use IvanFuhr\LaravelDatabaseBackup\Exceptions\BackupFailedException;
use IvanFuhr\LaravelDatabaseBackup\Exceptions\RestoreFailedException;
use IvanFuhr\LaravelDatabaseBackup\Support\BackupStorage;
use IvanFuhr\LaravelDatabaseBackup\Support\DatabaseConnection;
use Symfony\Component\Process\Process;

final readonly class PostgreSqlBackupStrategy implements DatabaseBackupStrategy
{
    public function __construct(
        private string $pgDumpBinary = 'pg_dump',
        private string $pgRestoreBinary = 'pg_restore',
    ) {}

    public function name(): string
    {
        return 'pgsql';
    }

    public function supports(string $driver): bool
    {
        return $driver === 'pgsql';
    }

    public function backup(DatabaseConnection $connection, BackupStorage $storage, ?string $filename = null): string
    {
        $filename ??= $this->buildFilename($connection->database());
        $remotePath = $storage->remotePath($filename);
        $tempPath = $this->temporaryPath($filename);

        $command = [
            $this->pgDumpBinary,
            '--file='.$tempPath,
            '--format=custom',
            '--host='.$connection->host(),
            '--port='.$connection->port(),
            '--username='.$connection->username(),
            '--no-owner',
            '--no-privileges',
            $connection->database(),
        ];

        $process = new Process($command, null, $this->pgEnv($connection));
        $process->setTimeout(null);
        $process->run();

        if (! $process->isSuccessful()) {
            @unlink($tempPath);

            throw new BackupFailedException($process->getErrorOutput() ?: $process->getOutput() ?: 'pg_dump failed.');
        }

        $handle = @fopen($tempPath, 'r');

        if ($handle === false) {
            @unlink($tempPath);

            throw new BackupFailedException('Failed to open temporary file for upload.');
        }

        try {
            $storage->disk()->put($remotePath, $handle);
        } finally {
            fclose($handle);
            @unlink($tempPath);
        }

        return $remotePath;
    }

    public function restore(DatabaseConnection $connection, string $localBackupPath): void
    {
        if (! is_file($localBackupPath)) {
            throw new RestoreFailedException(sprintf('Backup file not found: %s', $localBackupPath));
        }

        $command = [
            $this->pgRestoreBinary,
            '--clean',
            '--if-exists',
            '--no-owner',
            '--no-privileges',
            '--host='.$connection->host(),
            '--port='.$connection->port(),
            '--username='.$connection->username(),
            '--dbname='.$connection->database(),
            $localBackupPath,
        ];

        $process = new Process($command, null, $this->pgEnv($connection));
        $process->setTimeout(null);
        $process->run();

        if (! $process->isSuccessful()) {
            $errorOutput = $process->getErrorOutput() ?: $process->getOutput();

            if (str_contains($errorOutput, 'unsupported version')) {
                throw new RestoreFailedException(
                    'The installed pg_restore is older than the backup file format. '.
                    'Upgrade PostgreSQL client tools to match the database server version.'."\n".$errorOutput
                );
            }

            throw new RestoreFailedException($errorOutput !== '' ? $errorOutput : 'pg_restore failed.');
        }
    }

    /**
     * @return array<string, string>
     */
    private function pgEnv(DatabaseConnection $connection): array
    {
        return [
            'PGPASSWORD' => $connection->password(),
        ];
    }

    private function buildFilename(string $database): string
    {
        return sprintf('%s-%s.dump', $database, now()->format('Ymd_His'));
    }

    private function temporaryPath(string $filename): string
    {
        $tempDir = storage_path('app/tmp');

        if (! is_dir($tempDir)) {
            @mkdir($tempDir, 0755, true);
        }

        return $tempDir.DIRECTORY_SEPARATOR.getmypid().'-'.uniqid('', true).'-'.$filename;
    }
}
