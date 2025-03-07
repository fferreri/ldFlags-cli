<?php

namespace App\Commands;

use App\Services\LaunchDarklyService;
use GuzzleHttp\Exception\GuzzleException;

class FlagsAddRule extends BaseCommand
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
            $this->initializeOptions();

            if (!$this->validateInputs()) {
                return 1;
            }

            $this->displayRuleDetails();

            if (!$this->confirmOperation()) {
                return 0;
            }

            $flagData = $this->ldService->getFlag($this->projectKey, $this->flagKey);

            if (!$flagData) {
                $this->error("Flag '{$this->flagKey}' not found in project '{$this->projectKey}'.");
                return 1;
            }

            if (!$this->validateEnvironment($flagData)) {
                return 1;
            }

            if (!$this->validateVariations($flagData)) {
                return 1;
            }

            $result = $this->useJsonPatch ? $this->addRuleWithJsonPatch() : $this->addRuleWithSemanticPatch($flagData);

            return $result ? $this->successMessage() : $this->failureMessage();
        } catch (GuzzleException $e) {
            return $this->handleException($e);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    protected function initializeOptions(): void
    {
        $this->ruleName = $this->argument('name');
        $this->endpointPattern = $this->argument('pattern');
        $this->projectKey = $this->option('project') ?: config('launchdarkly.default_project');
        $this->environmentKey = $this->option('environment') ?: config('launchdarkly.default_environment');
        $this->force = $this->option('force') ?: false;
        $this->v5Percentage = (int) $this->option('v5-percentage');
        $this->v6Percentage = (int) $this->option('v6-percentage');
        $this->contextKind = $this->option('context-kind');
        $this->bucketBy = $this->option('bucket-by');
        $this->position = (int) $this->option('position');
        $this->comment = $this->option('comment');
        $this->trackEvents = !$this->option('no-track');
        $this->useJsonPatch = $this->option('json-patch');
        $this->flagKey = $this->option('flag');
    }

    protected function validateInputs(): bool
    {
        if (empty($this->projectKey)) {
            $this->error('No project specified and no default project configured.');
            return false;
        }

        if (empty($this->environmentKey)) {
            $this->error('No environment specified and no default environment configured.');
            return false;
        }

        if ($this->v5Percentage + $this->v6Percentage != 100) {
            $this->error('Percentages must sum to 100%. Current sum: ' . ($this->v5Percentage + $this->v6Percentage) . '%');
            return false;
        }

        if (!preg_match('/^(GET|POST|PUT|DELETE|PATCH|OPTIONS|HEAD)\s+\/.*$/', $this->endpointPattern)) {
            $this->error('Pattern must be in the format "HTTP_METHOD /path" (e.g. "GET /api/users")');
            return false;
        }

        return true;
    }

    protected function displayRuleDetails(): void
    {
        $this->info("Adding targeting rule to flag '{$this->flagKey}':");
        $this->line("  • Project: {$this->projectKey}");
        $this->line("  • Environment: {$this->environmentKey}");
        $this->line("  • Rule name: {$this->ruleName}");
        $this->line("  • Endpoint pattern: {$this->endpointPattern}");
        $this->line("  • Rollout: {$this->v5Percentage}% to v5, {$this->v6Percentage}% to v6");
        $this->line("  • Context kind: {$this->contextKind}");
        $this->line("  • Bucket by: {$this->bucketBy}");
        $this->line("  • Position: {$this->position}");
        $this->line("  • Track events: " . ($this->trackEvents ? 'Yes' : 'No'));
        $this->line("  • Using " . ($this->useJsonPatch ? "JSON Patch" : "Semantic Patch"));

        if ($this->comment) {
            $this->line("  • Comment: {$this->comment}");
        }
    }

    protected function confirmOperation(): bool
    {
        if (!$this->force) {
            return $this->confirm('Do you want to continue?', true);
        }
        return true;
    }

    protected function validateEnvironment(array $flagData): bool
    {
        if (!isset($flagData['environments'][$this->environmentKey])) {
            $this->error("Environment '{$this->environmentKey}' not found for flag '{$this->flagKey}'.");
            $this->line("\nAvailable environments for this flag:");
            if (isset($flagData['environments']) && is_array($flagData['environments'])) {
                foreach (array_keys($flagData['environments']) as $env) {
                    $this->line("  • {$env}");
                }
            } else {
                $this->line("  No environments found in flag data.");
            }
            return false;
        }
        return true;
    }

    protected function validateVariations(array $flagData): bool
    {
        $variations = $flagData['variations'] ?? [];
        if (count($variations) < 2) {
            $this->error("Flag '{$this->flagKey}' does not have enough variations.");
            return false;
        }

        $this->info("\nVariations detected:");
        foreach ($variations as $index => $variation) {
            $value = json_encode($variation['value']);
            $name = $variation['name'] ?? "Variation {$index}";
            $this->line("  • Variation {$index}: {$name} ({$value})");
        }

        $existingRules = $flagData['environments'][$this->environmentKey]['rules'] ?? [];
        $this->info("\nCurrent rules count: " . count($existingRules));
        if ($this->position > count($existingRules)) {
            $this->warn("Position {$this->position} is greater than the number of existing rules. The rule will be added at the end.");
        }

        $this->variationIds = [];
        foreach ($variations as $variation) {
            if (isset($variation['_id'])) {
                $this->variationIds[] = $variation['_id'];
            }
        }

        if (count($this->variationIds) < 2) {
            $this->variationIds = [0, 1];
        }

        return true;
    }

    protected function addRuleWithSemanticPatch(array $flagData): bool
    {
        $rule = $this->ldService->createEndpointMatchingRule(
            $this->ruleName,
            $this->endpointPattern,
            [$this->v5Percentage, $this->v6Percentage],
            $this->bucketBy,
            $this->contextKind,
            $this->trackEvents,
            $this->variationIds
        );

        return $this->ldService->addTargetingRule(
            $this->projectKey,
            $this->flagKey,
            $this->environmentKey,
            $rule,
            $this->comment,
            $this->position
        );
    }

    protected function addRuleWithJsonPatch(): bool
    {
        $flagData = $this->ldService->getFlag($this->projectKey, $this->flagKey);
        $rules = $flagData['environments'][$this->environmentKey]['rules'] ?? [];

        $newRule = [
            'description' => $this->ruleName,
            'clauses' => [
                [
                    'attribute' => 'endpoint_pattern',
                    'op' => 'matches',
                    'values' => [$this->endpointPattern],
                    'contextKind' => $this->contextKind,
                    'negate' => false
                ]
            ],
            'trackEvents' => $this->trackEvents,
            'rollout' => [
                'variations' => [
                    [
                        'variation' => 0,
                        'weight' => $this->v5Percentage * 1000
                    ],
                    [
                        'variation' => 1,
                        'weight' => $this->v6Percentage * 1000
                    ]
                ],
                'bucketBy' => $this->bucketBy,
                'contextKind' => $this->contextKind
            ]
        ];

        array_splice($rules, $this->position, 0, [$newRule]);

        $patch = [
            [
                'op' => 'replace',
                'path' => "/environments/{$this->environmentKey}/rules",
                'value' => $rules
            ]
        ];

        return $this->ldService->updateFlagWithJsonPatch($this->projectKey, $this->flagKey, $patch, $this->comment);
    }

    protected function successMessage(): int
    {
        $this->info("Successfully added targeting rule '{$this->ruleName}' to flag '{$this->flagKey}'.");
        $this->line("The rule will match: <fg=yellow>{$this->endpointPattern}</>");
        $this->line("Traffic split: <fg=green>{$this->v5Percentage}%</> to v5, <fg=green>{$this->v6Percentage}%</> to v6");
        return 0;
    }

    protected function failureMessage(): int
    {
        $this->error("Failed to add targeting rule.");
        return 1;
    }

    protected function handleException(\Exception $e): int
    {
        $this->error('Error: ' . $e->getMessage());
        return 1;
    }
}