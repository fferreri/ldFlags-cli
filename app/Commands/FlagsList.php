<?php

namespace App\Commands;

use App\Services\LaunchDarklyService;
use GuzzleHttp\Exception\GuzzleException;

class FlagsList extends BaseCommand
{
    /**
     * The signature of the command.
     */
    protected $signature = 'flags:list
                            {--project= : LaunchDarkly project ID}
                            {--environment= : Specific environment to filter by}
                            {--tag= : Filter flags by tag}
                            {--json : Output in JSON format}
                            {--debug : Show detailed debug information}';

    /**
     * The description of the command.
     */
    protected $description = 'List all feature flags from LaunchDarkly API workspace';

    /**
     * LaunchDarkly service instance.
     */
    protected LaunchDarklyService $ldService;

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(LaunchDarklyService $ldService): int
    {
        $this->ldService = $ldService;
        try {
            $this->initializeOptions();

            if (empty($this->projectKey)) {
                $this->error('No project specified and no default project configured.');
                return 1;
            }

            $this->info("Getting LaunchDarkly flags for project: {$this->projectKey}");

            $flags = $this->ldService->getFlags($this->projectKey);
            if (empty($flags)) {
                $this->warn("No flags found in project: {$this->projectKey}");
                return 0;
            }

            $flags = $this->filterFlagsByTag($flags);
            if ($flags === null) {
                return 0;
            }

            if ($this->option('json')) {
                $this->outputJson($flags);
                return 0;
            }

            $tableData = $this->environmentKey
                ? $this->prepareEnvironmentFilteredTable($flags, $this->projectKey, $this->environmentKey, $this->option('debug'))
                : $this->prepareBasicTable($flags);

            $this->displayTable($tableData);

            return 0;
        } catch (GuzzleException $e) {
            return $this->handleException($e);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    protected function initializeOptions(): void
    {
        $this->projectKey = $this->option('project') ?: config('launchdarkly.default_project');
        $this->environmentKey = $this->option('environment') ?: config('launchdarkly.default_environment');
        $this->tag = $this->option('tag');
    }

    protected function filterFlagsByTag(array $flags): ?array
    {
        if ($this->tag) {
            $flags = array_filter($flags, fn($flag) => in_array($this->tag, $flag['tags'] ?? []));
            if (empty($flags)) {
                $this->warn("No flags found with tag: {$this->tag}");
                return null;
            }
        }
        return $flags;
    }

    protected function outputJson(array $flags): void
    {
        $this->line(json_encode($flags, JSON_PRETTY_PRINT));
    }

    protected function prepareBasicTable(array $flags): array
    {
        return array_map(fn($flag) => [
            'key' => $flag['key'],
            'name' => $flag['name'] ?? $flag['key'],
            'kind' => $flag['kind'] ?? 'N/A',
            'tags' => implode(', ', $flag['tags'] ?? []),
            'temporary' => ($flag['temporary'] ?? false) ? 'Yes' : 'No',
            'variations' => count($flag['variations'] ?? []),
            'description' => $flag['description'] ?? ''
        ], $flags);
    }

    protected function prepareEnvironmentFilteredTable(array $flags, string $projectKey, string $environmentKey, bool $debug): array
    {
        $tableData = [];
        $count = 0;
        $total = count($flags);

        foreach ($flags as $flag) {
            $count++;
            $flagKey = $flag['key'];

            if ($debug) {
                $this->line("\rProcessing flag {$count}/{$total}: {$flagKey}", false);
            }

            try {
                // Get detailed flag information to access environment data
                $flagDetails = $this->ldService->getFlagDetails($projectKey, $flagKey);

                if (!$flagDetails) {
                    if ($debug) {
                        $this->warn("\nCould not retrieve details for flag: {$flagKey}");
                    }
                    continue;
                }

                // Skip if the environment doesn't exist for this flag
                if (!isset($flagDetails['environments'][$environmentKey])) {
                    if ($debug) {
                        $this->warn("\nEnvironment '{$environmentKey}' not found for flag: {$flagKey}");
                    }
                    continue;
                }

                $envData = $flagDetails['environments'][$environmentKey];

                // Get the status of the flag in this environment
                $status = $envData['on'] ? 'On' : 'Off';

                // Count the number of rules
                $ruleCount = count($envData['rules'] ?? []);

                // Get variation info
                $variations = $flagDetails['variations'] ?? [];
                $variationCount = count($variations);

                // Get the fallthrough (default) variation
                $fallthroughVariation = $envData['fallthrough']['variation'] ?? 'N/A';
                $fallthrough = is_numeric($fallthroughVariation) && isset($variations[$fallthroughVariation])
                    ? ($variations[$fallthroughVariation]['name'] ?? '') . " ({$fallthroughVariation})"
                    : "Variation {$fallthroughVariation}";

                $tableData[] = [
                    'key' => $flagKey,
                    'name' => $flagDetails['name'] ?? $flagKey,
                    'status' => $status,
                    'rules' => $ruleCount,
                    'fallthrough' => $fallthrough,
                    'tags' => implode(', ', $flagDetails['tags'] ?? []),
                    'description' => $flagDetails['description'] ?? ''
                ];
            } catch (\Exception $e) {
                if ($debug) {
                    $this->warn("\nError processing flag {$flagKey}: " . $e->getMessage());
                }
            }
        }

        if ($debug) {
            $this->line("\n"); // End the progress line
        }

        return $tableData;
    }

    protected function displayTable(array $tableData): void
    {
        usort($tableData, fn($a, $b) => strcmp($a['key'], $b['key']));
        $headers = array_keys($tableData[0]);
        $this->table($headers, $tableData);
        $this->info("Total flags: " . count($tableData));
    }

    protected function handleException(\Exception $e): int
    {
        $this->error('Error: ' . $e->getMessage());
        return 1;
    }
}