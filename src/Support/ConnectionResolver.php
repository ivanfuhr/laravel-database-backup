<?php

declare(strict_types=1);

namespace IvanFuhr\LaravelDatabaseBackup\Support;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use InvalidArgumentException;
use IvanFuhr\LaravelDatabaseBackup\Contracts\DatabaseBackupStrategy;

final readonly class ConnectionResolver
{
    public function __construct(
        private ConfigRepository $config,
    ) {}

    public function resolve(?string $connectionName = null): DatabaseConnection
    {
        $name = $connectionName ?? $this->configString('database.default');
        $connection = $this->config->get(sprintf('database.connections.%s', $name));

        if (! is_array($connection)) {
            throw new InvalidArgumentException(sprintf('Connection "%s" not found.', $name));
        }

        /** @var array<string, mixed> $connection */
        return DatabaseConnection::fromConfig($name, $connection);
    }

    public function resolveForStrategy(DatabaseBackupStrategy $strategy, ?string $connectionName = null): DatabaseConnection
    {
        $connection = $this->resolve($connectionName);

        if (! $strategy->supports($connection->driver)) {
            throw new InvalidArgumentException(sprintf(
                'Strategy "%s" does not support the "%s" database driver.',
                $strategy->name(),
                $connection->driver,
            ));
        }

        return $connection;
    }

    private function configString(string $key, string $default = ''): string
    {
        $value = $this->config->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }
}
