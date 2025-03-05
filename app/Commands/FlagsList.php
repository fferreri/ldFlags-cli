<?php

namespace App\Commands;

use App\Services\LaunchDarklyService;
use LaravelZero\Framework\Commands\Command;
use GuzzleHttp\Exception\GuzzleException;

class FlagsList extends Command
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
            $projectKey = $this->option('project') ?: config('launchdarkly.default_project');
            $environmentKey = $this->option('environment') ?: config('launchdarkly.default_environment');
            $tag = $this->option('tag');
            $debug = $this->option('debug');

            if (empty($projectKey)) {
                $this->error('No project specified and no default project configured.');
                return 1;
            }

            $this->info("Getting LaunchDarkly flags for project: {$projectKey}");

            // Get default environment if none specified but environment-filtered features are requested
            if (empty($environmentKey) && $this->option('environment') !== false) {
                $environmentKey = config('launchdarkly.default_environment');
                if ($environmentKey) {
                    $this->info("Using default environment: {$environmentKey}");
                }
            }

            // Get all flags for the project
            $flags = $this->ldService->getFlags($projectKey);
            if (empty($flags)) {
                $this->warn("No flags found in project: {$projectKey}");
                return 0;
            }

            // Filter by tag if specified
            if ($tag) {
                $flags = array_filter($flags, fn($flag) => in_array($tag, $flag['tags'] ?? []));

                if (empty($flags)) {
                    $this->warn("No flags found with tag: {$tag}");
                    return 0;
                }
            }

            // If JSON output is requested
            if ($this->option('json')) {
                $this->line(json_encode($flags, JSON_PRETTY_PRINT));
                return 0;
            }

            // Get detailed flag information if we need to filter by environment
            $tableData = $environmentKey
                ? $this->prepareEnvironmentFilteredTable($flags, $projectKey, $environmentKey, $debug)
                : $this->prepareBasicTable($flags);

            // Sort flags alphabetically by key
            usort($tableData, fn($a, $b) => strcmp($a['key'], $b['key']));

            // Display the table
            $headers = array_keys($tableData[0]);
            $this->table($headers, $tableData);
            $this->info("Total flags: " . count($tableData));

            return 0;
        } catch (GuzzleException $e) {
            $this->error('Error connecting to LaunchDarkly: ' . $e->getMessage());
            if ($debug) {
                $this->line("\nDEBUG: Exception details:");
                $this->line("  Class: " . get_class($e));
                $this->line("  Code: " . $e->getCode());
                $this->line("  Full message: " . $e->getMessage());
            }
            return 1;
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            if ($debug) {
                $this->line("\nDEBUG: Exception details:");
                $this->line("  Class: " . get_class($e));
                $this->line("  Code: " . $e->getCode());
                $this->line("  Full message: " . $e->getMessage());
            }
            return 1;
        }
    }

    /**
     * Prepare a basic table with flag information.
     */
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

    /**
     * Prepare a table with environment-filtered flag information.
     */
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
}