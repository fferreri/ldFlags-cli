<?php

namespace App\Commands;

use App\Services\LaunchDarklyService;
use LaravelZero\Framework\Commands\Command;
use GuzzleHttp\Exception\GuzzleException;

class FlagsAddRule extends Command
{
    /**
     * The signature of the command.
     */
    protected $signature = 'flags:add-rule
                            {name : Name of the targeting rule}
                            {pattern : Endpoint pattern in the format "METHOD /path" (e.g. "GET /api/users")}
                            {--project= : LaunchDarkly project ID}
                            {--environment= : Environment to add the rule to}
                            {--force : Do not ask for permission to continue}
                            {--v5-percentage=100 : Percentage for v5 variation (0-100)}
                            {--v6-percentage=0 : Percentage for v6 variation (0-100)}
                            {--context-kind=request : Context kind for the rule}
                            {--bucket-by=key : Attribute to bucket by for percentage rollout}
                            {--position=0 : Position to insert the rule (0 is first)}
                            {--debug : Show detailed debug information}
                            {--comment= : Comment to include with the change}
                            {--flag=api-v6-rollout-endpoints : The feature flag key to add rules to}
                            {--no-track : Disable event tracking for this rule}
                            {--json-patch : Use JSON Patch format instead of semantic patch}';

    /**
     * The description of the command.
     */
    protected $description = 'Add a targeting rule to a feature flag for specific endpoint patterns';

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
            $ruleName = $this->argument('name');
            $endpointPattern = $this->argument('pattern');
            $projectKey = $this->option('project') ?: config('launchdarkly.default_project');
            $environmentKey = $this->option('environment') ?: config('launchdarkly.default_environment');
            $force = $this->option('force') ?: false;
            $v5Percentage = (int) $this->option('v5-percentage');
            $v6Percentage = (int) $this->option('v6-percentage');
            $contextKind = $this->option('context-kind');
            $bucketBy = $this->option('bucket-by');
            $position = (int) $this->option('position');
            $debug = $this->option('debug');
            $comment = $this->option('comment');
            $trackEvents = !$this->option('no-track');
            $useJsonPatch = $this->option('json-patch');

            // Flag key can now be specified via option
            $flagKey = $this->option('flag');

            if (empty($projectKey)) {
                $this->error('No project specified and no default project configured.');
                return 1;
            }

            if (empty($environmentKey)) {
                $this->error('No environment specified and no default environment configured.');
                return 1;
            }

            // Validate percentages
            if ($v5Percentage + $v6Percentage != 100) {
                $this->error('Percentages must sum to 100%. Current sum: ' . ($v5Percentage + $v6Percentage) . '%');
                return 1;
            }

            // Validate pattern format
            if (!preg_match('/^(GET|POST|PUT|DELETE|PATCH|OPTIONS|HEAD)\s+\/.*$/', $endpointPattern)) {
                $this->error('Pattern must be in the format "HTTP_METHOD /path" (e.g. "GET /api/users")');
                return 1;
            }

            // Show what we're about to do
            $this->info("Adding targeting rule to flag '{$flagKey}':");
            $this->line("  • Project: {$projectKey}");
            $this->line("  • Environment: {$environmentKey}");
            $this->line("  • Rule name: {$ruleName}");
            $this->line("  • Endpoint pattern: {$endpointPattern}");
            $this->line("  • Rollout: {$v5Percentage}% to v5, {$v6Percentage}% to v6");
            $this->line("  • Context kind: {$contextKind}");
            $this->line("  • Bucket by: {$bucketBy}");
            $this->line("  • Position: {$position}");
            $this->line("  • Track events: " . ($trackEvents ? 'Yes' : 'No'));
            $this->line("  • Using " . ($useJsonPatch ? "JSON Patch" : "Semantic Patch"));

            if ($comment) {
                $this->line("  • Comment: {$comment}");
            }

            if (!$force) {
                if (!$this->confirm('Do you want to continue?', true)) {
                    $this->line('Operation cancelled.');
                    return 0;
                }
            }

            // Get full flag data to ensure we have all variations and current rules
            $flagData = $this->ldService->getFlag($projectKey, $flagKey);

            if (!$flagData) {
                $this->error("Flag '{$flagKey}' not found in project '{$projectKey}'.");
                return 1;
            }

            // Display available environments when in debug mode
            if ($debug) {
                $this->info("\nDEBUG: Available environments in flag data:");
                if (isset($flagData['environments']) && is_array($flagData['environments'])) {
                    $availableEnvs = array_keys($flagData['environments']);
                    $this->line("  Found " . count($availableEnvs) . " environments: " . implode(', ', $availableEnvs));
                } else {
                    $this->warn("  No environments data found in flag response!");
                    $this->line("  Full flag data structure: " . json_encode(array_keys($flagData)));
                }

                // List all project environments
                $this->info("\nDEBUG: Available environments in project:");
                try {
                    $projectEnvs = $this->ldService->getProjectEnvironments($projectKey);
                    foreach ($projectEnvs as $env) {
                        $this->line("  • {$env['key']} ({$env['name']})");
                    }
                } catch (\Exception $e) {
                    $this->warn("  Error retrieving project environments: " . $e->getMessage());
                }
            }

            // Check if environment exists
            if (!isset($flagData['environments'][$environmentKey])) {
                $this->error("Environment '{$environmentKey}' not found for flag '{$flagKey}'.");
                $this->line("\nAvailable environments for this flag:");
                if (isset($flagData['environments']) && is_array($flagData['environments'])) {
                    foreach (array_keys($flagData['environments']) as $env) {
                        $this->line("  • {$env}");
                    }
                } else {
                    $this->line("  No environments found in flag data.");
                }
                return 1;
            }

            // Verify variation indices - we assume v5 is 0 and v6 is 1, but let's check
            $variations = $flagData['variations'] ?? [];
            if (count($variations) < 2) {
                $this->error("Flag '{$flagKey}' does not have enough variations.");
                return 1;
            }

            // Display variation information for confirmation
            $this->info("\nVariations detected:");
            foreach ($variations as $index => $variation) {
                $value = json_encode($variation['value']);
                $name = $variation['name'] ?? "Variation {$index}";
                $this->line("  • Variation {$index}: {$name} ({$value})");
            }

            // Display existing rules count
            $existingRules = $flagData['environments'][$environmentKey]['rules'] ?? [];
            $this->info("\nCurrent rules count: " . count($existingRules));
            if ($position > count($existingRules)) {
                $this->warn("Position {$position} is greater than the number of existing rules. The rule will be added at the end.");
            }

            // Get variation IDs from flag data
            $variationIds = [];
            foreach ($variations as $variation) {
                if (isset($variation['_id'])) {
                    $variationIds[] = $variation['_id'];
                }
            }

            if (count($variationIds) < 2 && $debug) {
                $this->warn("Could not find variation IDs in flag data. Using indices instead.");
                // If we can't get variation IDs, fall back to indices
                $variationIds = [0, 1];
            }

            // Create the rule
            if ($useJsonPatch) {
                // Use JSON Patch approach
                $result = $this->addRuleWithJsonPatch(
                    $projectKey,
                    $flagKey,
                    $environmentKey,
                    $ruleName,
                    $endpointPattern,
                    $v5Percentage,
                    $v6Percentage,
                    $contextKind,
                    $bucketBy,
                    $trackEvents,
                    $position,
                    $comment
                );
            } else {
                // Create the rule using semantic patch
                $rule = $this->ldService->createEndpointMatchingRule(
                    $ruleName,
                    $endpointPattern,
                    [$v5Percentage, $v6Percentage],
                    $bucketBy,
                    $contextKind,
                    $trackEvents,
                    $variationIds
                );

                if ($debug) {
                    $this->info("\nDEBUG: Rule payload:");
                    $this->line(json_encode($rule, JSON_PRETTY_PRINT));
                }

                // Add the rule to the flag using semantic patch
                $result = $this->ldService->addTargetingRule(
                    $projectKey,
                    $flagKey,
                    $environmentKey,
                    $rule,
                    $comment,
                    $position
                );
            }

            if ($result) {
                $this->info("Successfully added targeting rule '{$ruleName}' to flag '{$flagKey}'.");
                $this->line("The rule will match: <fg=yellow>{$endpointPattern}</>");
                $this->line("Traffic split: <fg=green>{$v5Percentage}%</> to v5, <fg=green>{$v6Percentage}%</> to v6");
                return 0;
            } else {
                $this->error("Failed to add targeting rule.");
                return 1;
            }
        } catch (GuzzleException $e) {
            $this->error('Error connecting to LaunchDarkly: ' . $e->getMessage());
            if ($this->option('debug')) {
                $this->line("\nDEBUG: Exception details:");
                $this->line("  Class: " . get_class($e));
                $this->line("  Code: " . $e->getCode());
                $this->line("  Full message: " . $e->getMessage());
            }
            return 1;
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            if ($this->option('debug')) {
                $this->line("\nDEBUG: Exception details:");
                $this->line("  Class: " . get_class($e));
                $this->line("  Code: " . $e->getCode());
                $this->line("  Full message: " . $e->getMessage());
                $this->line("  Trace: " . $e->getTraceAsString());
            }
            return 1;
        }
    }

    /**
     * Add a rule using the JSON Patch approach instead of semantic patch.
     */
    protected function addRuleWithJsonPatch(
        string $projectKey,
        string $flagKey,
        string $environmentKey,
        string $ruleName,
        string $endpointPattern,
        int $v5Percentage,
        int $v6Percentage,
        string $contextKind,
        string $bucketBy,
        bool $trackEvents,
        int $position,
        ?string $comment
    ): bool {
        // Get current rules
        $flagData = $this->ldService->getFlag($projectKey, $flagKey);
        $rules = $flagData['environments'][$environmentKey]['rules'] ?? [];

        // Create a new rule
        $newRule = [
            'description' => $ruleName,
            'clauses' => [
                [
                    'attribute' => 'endpoint_pattern',
                    'op' => 'matches',
                    'values' => [$endpointPattern],
                    'contextKind' => $contextKind,
                    'negate' => false
                ]
            ],
            'trackEvents' => $trackEvents,
            'rollout' => [
                'variations' => [
                    [
                        'variation' => 0,
                        'weight' => $v5Percentage * 1000
                    ],
                    [
                        'variation' => 1,
                        'weight' => $v6Percentage * 1000
                    ]
                ],
                'bucketBy' => $bucketBy,
                'contextKind' => $contextKind
            ]
        ];

        // Insert the new rule at the specified position
        array_splice($rules, $position, 0, [$newRule]);

        // Create JSON patch
        $patch = [
            [
                'op' => 'replace',
                'path' => "/environments/{$environmentKey}/rules",
                'value' => $rules
            ]
        ];

        if ($this->option('debug')) {
            $this->info("\nDEBUG: JSON Patch payload:");
            $this->line(json_encode($patch, JSON_PRETTY_PRINT));
        }

        // Apply the patch
        return $this->ldService->updateFlagWithJsonPatch($projectKey, $flagKey, $patch, $comment);
    }
}