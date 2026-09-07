<?php

declare(strict_types=1);

namespace IvanFuhr\LaravelDatabaseBackup;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Manager;
use InvalidArgumentException;
use IvanFuhr\LaravelDatabaseBackup\Contracts\DatabaseBackupStrategy;
use IvanFuhr\LaravelDatabaseBackup\Exceptions\UnsupportedStrategyException;
use IvanFuhr\LaravelDatabaseBackup\Strategies\PostgreSqlBackupStrategy;
use IvanFuhr\LaravelDatabaseBackup\Support\ConnectionResolver;
use IvanFuhr\LaravelDatabaseBackup\Support\DatabaseConnection;

final class BackupManager extends Manager
{
    public function __construct(
        Container $container,
        private readonly ConnectionResolver $connectionResolver,
    ) {
        parent::__construct($container);
    }

    public function strategy(?string $name = null): DatabaseBackupStrategy
    {
        $strategy = $this->driver($name);

        if (! $strategy instanceof DatabaseBackupStrategy) {
            throw new UnsupportedStrategyException(sprintf(
                'Resolved backup strategy [%s] is invalid.',
                $name ?? $this->getDefaultDriver(),
            ));
        }

        return $strategy;
    }

    public function getDefaultDriver(): string
    {
        $default = $this->config->get('database-backup.default', 'pgsql');

        return is_scalar($default) ? (string) $default : 'pgsql';
    }

    public function connectionFor(?string $connectionName = null, ?string $strategyName = null): DatabaseConnection
    {
        return $this->connectionResolver->resolveForStrategy(
            $this->strategy($strategyName),
            $connectionName,
        );
    }

    /**
     * @param  Closure(Container): DatabaseBackupStrategy  $callback
     */
    public function extend($driver, Closure $callback): static
    {
        parent::extend($driver, $callback);

        return $this;
    }

    protected function createDriver($driver): DatabaseBackupStrategy
    {
        if (isset($this->customCreators[$driver])) {
            $strategy = $this->callCustomCreator($driver);

            if (! $strategy instanceof DatabaseBackupStrategy) {
                throw new UnsupportedStrategyException(sprintf(
                    'Custom backup strategy [%s] must implement DatabaseBackupStrategy.',
                    $driver,
                ));
            }

            return $strategy;
        }

        $config = $this->strategyConfig($driver);
        $driverName = $this->stringFromConfig($config, 'driver', $driver);

        return match ($driverName) {
            'pgsql' => new PostgreSqlBackupStrategy(
                pgDumpBinary: $this->stringFromConfig($config, 'pg_dump_binary', 'pg_dump'),
                pgRestoreBinary: $this->stringFromConfig($config, 'pg_restore_binary', 'pg_restore'),
            ),
            default => throw new UnsupportedStrategyException(sprintf(
                'Backup strategy driver [%s] is not supported.',
                $driverName,
            )),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function strategyConfig(string $name): array
    {
        $config = $this->config->get(sprintf('database-backup.strategies.%s', $name));

        if (! is_array($config)) {
            throw new InvalidArgumentException(sprintf('Backup strategy [%s] is not configured.', $name));
        }

        /** @var array<string, mixed> $config */
        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function stringFromConfig(array $config, string $key, string $default): string
    {
        $value = $config[$key] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }
}
