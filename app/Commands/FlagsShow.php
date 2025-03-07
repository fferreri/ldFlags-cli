<?php

namespace App\Commands;

use App\Services\LaunchDarklyService;
use GuzzleHttp\Exception\GuzzleException;

class FlagsShow extends BaseCommand
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'flags:show
                            {flag-key : The key of the feature flag to show}
                            {--project= : LaunchDarkly project ID}
                            {--environment= : Specific environment in LaunchDarkly}
                            {--json : Output in JSON format}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Show detailed information about a specific feature flag';

    /**
     * LaunchDarkly service instance.
     *
     * @var \App\Services\LaunchDarklyService
     */
    protected $ldService;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
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

            $flagDetails = $this->ldService->getFlagDetails($this->projectKey, $this->flagKey);

            if (!$flagDetails) {
                $this->error("Flag '{$this->flagKey}' not found in project '{$this->projectKey}'.");
                return 1;
            }

            if ($this->option('json')) {
                $this->outputJson($flagDetails);
                return 0;
            }

            $this->displayFlagDetails($flagDetails);

            return 0;
        } catch (GuzzleException $e) {
            return $this->handleException($e);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    protected function initializeOptions(): void
    {
        $this->flagKey = $this->argument('flag-key');
        $this->projectKey = $this->option('project') ?: config('launchdarkly.default_project');
        $this->environmentKey = $this->option('environment') ?: config('launchdarkly.default_environment');
    }

    protected function outputJson(array $flagDetails): void
    {
        $this->line(json_encode($flagDetails, JSON_PRETTY_PRINT));
    }

    protected function displayFlagDetails(array $flagDetails): void
    {
        $this->info('==== Flag Details ====');
        $this->line("<fg=green>Key:</> {$flagDetails['key']}");
        $this->line("<fg=green>Name:</> {$flagDetails['name']}");
        $this->line("<fg=green>Description:</> " . ($flagDetails['description'] ?? 'N/A'));
        $this->line("<fg=green>Kind:</> " . ($flagDetails['kind'] ?? 'N/A'));
        $this->line("<fg=green>Created:</> " . ($flagDetails['creationDate'] ?? 'Unknown'));

        if (!empty($flagDetails['tags'])) {
            $this->line("<fg=green>Tags:</> " . implode(', ', $flagDetails['tags']));
        }

        $this->line("<fg=green>Temporary:</> " . ($flagDetails['temporary'] ? 'Yes' : 'No'));
        $this->line("<fg=green>Archived:</> " . ($flagDetails['archived'] ? 'Yes' : 'No'));
        $this->line("<fg=green>Deprecated:</> " . ($flagDetails['deprecated'] ? 'Yes' : 'No'));

        $this->displayClientSideAvailability($flagDetails);
        $this->displayDefaults($flagDetails);
        $this->displayMaintainer($flagDetails);
        $this->displayVariations($flagDetails);
        $this->displayCustomProperties($flagDetails);

        if ($this->environmentKey) {
            $this->displayEnvironmentDetails($flagDetails);
        }
    }

    protected function displayClientSideAvailability(array $flagDetails): void
    {
        if (isset($flagDetails['clientSideAvailability'])) {
            $this->newLine();
            $this->info('==== Client-side Availability ====');
            $this->line("<fg=green>Using Mobile Key:</> " . ($flagDetails['clientSideAvailability']['usingMobileKey'] ? 'Yes' : 'No'));
            $this->line("<fg=green>Using Environment ID:</> " . ($flagDetails['clientSideAvailability']['usingEnvironmentId'] ? 'Yes' : 'No'));
        }
    }

    protected function displayDefaults(array $flagDetails): void
    {
        if (isset($flagDetails['defaults'])) {
            $this->newLine();
            $this->info('==== Default Settings ====');
            $onVariation = $flagDetails['defaults']['onVariation'] ?? 'N/A';
            $offVariation = $flagDetails['defaults']['offVariation'] ?? 'N/A';
            $this->line("<fg=green>On Variation:</> {$onVariation}");
            $this->line("<fg=green>Off Variation:</> {$offVariation}");
        }
    }

    protected function displayMaintainer(array $flagDetails): void
    {
        if (isset($flagDetails['maintainer'])) {
            $this->newLine();
            $this->info('==== Maintainer ====');
            $maintainer = $flagDetails['maintainer'];
            $this->line("<fg=green>Name:</> {$maintainer['firstName']} {$maintainer['lastName']}");
            $this->line("<fg=green>Email:</> {$maintainer['email']}");
            $this->line("<fg=green>Role:</> {$maintainer['role']}");
        }
    }

    protected function displayVariations(array $flagDetails): void
    {
        if (!empty($flagDetails['variations'])) {
            $this->newLine();
            $this->info('==== Variations ====');

            foreach ($flagDetails['variations'] as $index => $variation) {
                $this->line("<fg=yellow>Variation {$index}:</>");
                $this->line("  Value: " . json_encode($variation['value']));
                $this->line("  Name: " . ($variation['name'] ?? 'N/A'));
                $this->line("  Description: " . ($variation['description'] ?? 'N/A'));
                if (isset($variation['_id'])) {
                    $this->line("  ID: " . $variation['_id']);
                }
                $this->newLine();
            }
        }
    }

    protected function displayCustomProperties(array $flagDetails): void
    {
        if (!empty($flagDetails['customProperties'])) {
            $this->newLine();
            $this->info('==== Custom Properties ====');
            foreach ($flagDetails['customProperties'] as $key => $property) {
                $propName = $property['name'] ?? $key;
                $propValue = is_array($property['value'])
                    ? implode(', ', $property['value'])
                    : $property['value'];
                $this->line("<fg=green>{$propName}:</> {$propValue}");
            }
        }
    }

    protected function displayEnvironmentDetails(array $flagDetails): void
    {
        if (isset($flagDetails['environments'][$this->environmentKey])) {
            $flagEnv = $flagDetails['environments'][$this->environmentKey];

            $this->newLine();
            $this->info("==== Environment: {$this->environmentKey} ====");
            $this->line("<fg=green>Status:</> " . ($flagEnv['on'] ? 'ON' : 'OFF'));

            if (isset($flagEnv['lastModified'])) {
                $this->line("<fg=green>Last Modified:</> " . $flagEnv['lastModified']);
            }

            if (isset($flagEnv['version'])) {
                $this->line("<fg=green>Version:</> " . $flagEnv['version']);
            }

            if (isset($flagEnv['fallthrough'])) {
                $this->line("<fg=green>Default rule:</> " . $this->describeVariation($flagEnv['fallthrough'], $flagDetails['variations']));
            }

            $this->displayTargetingRules($flagEnv, $flagDetails);
            $this->displayUserTargets($flagEnv, $flagDetails);
            $this->displayContextTargets($flagEnv, $flagDetails);
            $this->displayPrerequisites($flagEnv);
            $this->displayEnvironmentSummary($flagEnv, $flagDetails);
        } else {
            $this->warn("Environment '{$this->environmentKey}' not found or not accessible for this flag.");
        }
    }

    protected function displayTargetingRules(array $flagEnv, array $flagDetails): void
    {
        if (!empty($flagEnv['rules'])) {
            $this->newLine();
            $this->info('==== Targeting Rules ====');

            foreach ($flagEnv['rules'] as $index => $rule) {
                $ruleName = !empty($rule['description']) ? " ({$rule['description']})" : "";
                $this->line("<fg=yellow>Rule " . ($index + 1) . $ruleName . ":</>");

                if (!empty($rule['clauses'])) {
                    $this->line("  Conditions:");
                    foreach ($rule['clauses'] as $clause) {
                        $this->line("    " . $this->describeClause($clause));
                    }
                }

                $returnValue = $this->getRuleReturnValue($rule, $flagDetails);
                $this->line("  Returns: " . $returnValue);

                if (isset($rule['trackEvents'])) {
                    $this->line("  Track Events: " . ($rule['trackEvents'] ? 'Yes' : 'No'));
                }

                $this->newLine();
            }
        }
    }

    protected function getRuleReturnValue(array $rule, array $flagDetails): string
    {
        if (isset($rule['variation'])) {
            return $this->describeVariation(['variation' => $rule['variation']], $flagDetails['variations']);
        } elseif (isset($rule['rollout'])) {
            return $this->describeVariation(['rollout' => $rule['rollout']], $flagDetails['variations']);
        } else {
            return 'Unknown';
        }
    }

    protected function displayUserTargets(array $flagEnv, array $flagDetails): void
    {
        if (!empty($flagEnv['targets'])) {
            $this->info('==== User Targets ====');

            foreach ($flagEnv['targets'] as $target) {
                if (!empty($target['values'])) {
                    $variationDesc = isset($flagDetails['variations'][$target['variation']])
                        ? ($flagDetails['variations'][$target['variation']]['name'] ?? "Variation {$target['variation']}")
                        : "Variation {$target['variation']}";

                    $this->line("<fg=yellow>{$variationDesc}:</> " . implode(', ', $target['values']));
                }
            }
        }
    }

    protected function displayContextTargets(array $flagEnv, array $flagDetails): void
    {
        if (!empty($flagEnv['contextTargets'])) {
            $this->newLine();
            $this->info('==== Context Targets ====');

            foreach ($flagEnv['contextTargets'] as $target) {
                if (!empty($target['values'])) {
                    $variationDesc = isset($flagDetails['variations'][$target['variation']])
                        ? ($flagDetails['variations'][$target['variation']]['name'] ?? "Variation {$target['variation']}")
                        : "Variation {$target['variation']}";

                    $contextKind = isset($target['contextKind']) ? " ({$target['contextKind']})" : "";
                    $this->line("<fg=yellow>{$variationDesc}{$contextKind}:</> " . implode(', ', $target['values']));
                }
            }
        }
    }

    protected function displayPrerequisites(array $flagEnv): void
    {
        if (!empty($flagEnv['prerequisites'])) {
            $this->newLine();
            $this->info('==== Prerequisites ====');

            foreach ($flagEnv['prerequisites'] as $prereq) {
                $this->line("<fg=green>Flag {$prereq['key']}</> must be variation {$prereq['variation']}");
            }
        }
    }

    protected function displayEnvironmentSummary(array $flagEnv, array $flagDetails): void
    {
        if (isset($flagEnv['summary'])) {
            $this->newLine();
            $this->info('==== Environment Summary ====');

            if (isset($flagEnv['summary']['variations'])) {
                foreach ($flagEnv['summary']['variations'] as $varIndex => $summary) {
                    $variationName = isset($flagDetails['variations'][$varIndex]) && isset($flagDetails['variations'][$varIndex]['name'])
                        ? $flagDetails['variations'][$varIndex]['name']
                        : "Variation {$varIndex}";

                    $this->line("<fg=yellow>{$variationName}:</>");
                    $this->line("  Target users: " . ($summary['targets'] ?? 0));
                    $this->line("  Target contexts: " . ($summary['contextTargets'] ?? 0));
                    $this->line("  Rules: " . ($summary['rules'] ?? 0));

                    if (isset($summary['isFallthrough']) && $summary['isFallthrough']) {
                        $this->line("  Is fallthrough variation");
                    }

                    if (isset($summary['isOff']) && $summary['isOff']) {
                        $this->line("  Is off variation");
                    }
                }
            }
        }
    }

    /**
     * Describe a variation reference (either direct or percentage rollout).
     *
     * @param mixed $variationData
     * @param array $variations
     * @return string
     */
    protected function describeVariation($variationData, $variations)
    {
        if (is_null($variationData)) {
            return 'N/A';
        }

        // Direct variation reference
        if (isset($variationData['variation'])) {
            $index = $variationData['variation'];
            $name = isset($variations[$index]) && isset($variations[$index]['name'])
                ? $variations[$index]['name']
                : "Variation {$index}";

            return $name;
        }

        // Percentage rollout
        if (isset($variationData['rollout']) && isset($variationData['rollout']['variations'])) {
            $rolloutDesc = [];

            foreach ($variationData['rollout']['variations'] as $vr) {
                $index = $vr['variation'];
                $percentage = $vr['weight'] / 1000;

                $name = isset($variations[$index]) && isset($variations[$index]['name'])
                    ? $variations[$index]['name']
                    : "Variation {$index}";

                $rolloutDesc[] = "{$name} ({$percentage}%)";
            }

            return "Percentage rollout: " . implode(', ', $rolloutDesc);
        }

        return json_encode($variationData);
    }

    /**
     * Describe a targeting clause in human-readable format.
     *
     * @param array $clause
     * @return string
     */
    protected function describeClause($clause)
    {
        $attributeDesc = $clause['attribute'];
        $op = $this->getOperatorDescription($clause['op']);
        $valueDesc = implode(', ', $clause['values']);
        $negated = !empty($clause['negate']) ? 'not ' : '';
        $contextKind = isset($clause['contextKind']) ? " ({$clause['contextKind']})" : '';

        return "{$attributeDesc}{$contextKind} {$negated}{$op} {$valueDesc}";
    }

    /**
     * Get a human-readable description of an operator.
     *
     * @param string $op
     * @return string
     */
    protected function getOperatorDescription($op)
    {
        $operators = [
            'in' => 'is one of',
            'endsWith' => 'ends with',
            'startsWith' => 'starts with',
            'matches' => 'matches regex',
            'contains' => 'contains',
            'lessThan' => 'is less than',
            'lessThanOrEqual' => 'is less than or equal to',
            'greaterThan' => 'is greater than',
            'greaterThanOrEqual' => 'is greater than or equal to',
            'before' => 'is before',
            'after' => 'is after',
            'segmentMatch' => 'is in segment',
            'semVerEqual' => 'version equals',
            'semVerLessThan' => 'version is less than',
            'semVerGreaterThan' => 'version is greater than',
        ];

        return $operators[$op] ?? $op;
    }

    protected function handleException(\Exception $e): int
    {
        $this->error('Error: ' . $e->getMessage());
        return 1;
    }
}