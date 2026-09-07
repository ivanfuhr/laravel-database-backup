<?php

declare(strict_types=1);

namespace IvanFuhr\LaravelDatabaseBackup\Commands\Concerns;

trait InteractsWithCommandInput
{
    protected function optionalStringOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    protected function optionalStringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
