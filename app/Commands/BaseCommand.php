<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

abstract class BaseCommand extends Command
{
    protected string $projectKey;
    protected string $environmentKey;
    protected string $flagKey;

    protected function initializeOptions(): void
    {
        $this->flagKey = $this->argument('flag-key');
        $this->projectKey = $this->option('project') ?: config('launchdarkly.default_project');
        $this->environmentKey = $this->option('environment') ?: config('launchdarkly.default_environment');
    }

    protected function outputJson(array $data): void
    {
        $this->line(json_encode($data, JSON_PRETTY_PRINT));
    }

    protected function handleException(\Exception $e): int
    {
        $this->error('Error: ' . $e->getMessage());
        return 1;
    }
}
