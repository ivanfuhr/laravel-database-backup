<?php

declare(strict_types=1);

namespace IvanFuhr\LaravelDatabaseBackup\Support;

use InvalidArgumentException;

final readonly class DatabaseConnection
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public string $name,
        public string $driver,
        public array $config,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $name, array $config): self
    {
        $driver = $config['driver'] ?? null;

        if (! is_string($driver) || $driver === '') {
            throw new InvalidArgumentException(sprintf('Connection "%s" is missing a driver.', $name));
        }

        return new self($name, $driver, $config);
    }

    public function host(): string
    {
        return $this->stringValue('host');
    }

    public function port(): string
    {
        return $this->stringValue('port');
    }

    public function database(): string
    {
        return $this->stringValue('database');
    }

    public function username(): string
    {
        return $this->stringValue('username');
    }

    public function password(): string
    {
        return $this->stringValue('password');
    }

    private function stringValue(string $key): string
    {
        $value = $this->config[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
