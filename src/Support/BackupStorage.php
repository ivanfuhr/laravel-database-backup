<?php

declare(strict_types=1);

namespace IvanFuhr\LaravelDatabaseBackup\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

final readonly class BackupStorage
{
    public function __construct(
        private FilesystemAdapter $disk,
        private string $directory,
    ) {}

    public static function forConfiguredDisk(): self
    {
        $diskName = self::configString('database-backup.disk');

        if ($diskName === '') {
            $diskName = self::configString('filesystems.default', 'local');
        }

        $directory = mb_trim(self::configString('database-backup.directory', 'backups'), '/');

        $disk = Storage::disk($diskName);

        if (! $disk instanceof FilesystemAdapter) {
            throw new InvalidArgumentException(sprintf('Disk "%s" is not a filesystem adapter.', $diskName));
        }

        if ($directory !== '' && ! $disk->exists($directory)) {
            $disk->makeDirectory($directory);
        }

        return new self($disk, $directory);
    }

    public function path(string $filename): string
    {
        $cleaned = mb_ltrim($filename, '/\\');

        return $this->disk->path($this->directory !== '' ? $this->directory.'/'.$cleaned : $cleaned);
    }

    public function remotePath(string $filename): string
    {
        $cleaned = mb_ltrim($filename, '/\\');

        return $this->directory !== '' ? $this->directory.'/'.$cleaned : $cleaned;
    }

    /**
     * @return list<string>
     */
    public function files(): array
    {
        return array_values($this->disk->allFiles($this->directory));
    }

    public function disk(): FilesystemAdapter
    {
        return $this->disk;
    }

    public function filesystem(): Filesystem
    {
        return $this->disk;
    }

    public function directory(): string
    {
        return $this->directory;
    }

    private static function configString(string $key, string $default = ''): string
    {
        $value = config($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }
}
