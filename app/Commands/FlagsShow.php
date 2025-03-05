<?php

namespace App\Commands;

use App\Services\LaunchDarklyService;
use LaravelZero\Framework\Commands\Command;
use GuzzleHttp\Exception\GuzzleException;

class FlagsShow extends Command
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
     * @return mixed
     */
    public function handle(LaunchDarklyService $ldService)
    {
        $this->ldService = $ldService;

        try {
            $flagKey = $this->argument('flag-key');
            $projectKey = $this->option('project') ?: config('launchdarkly.default_project');
            $environmentKey = $this->option('environment') ?: config('launchdarkly.default_environment');

            if (empty($projectKey)) {
                $this->error('No project specified and no default project configured.');
                return 1;
            }

            // Get detailed flag information using the enhanced method
            $flagDetails = $this->ldService->getFlagDetails($projectKey, $flagKey);

            if (!$flagDetails) {
                $this->error("Flag '{$flagKey}' not found in project '{$projectKey}'.");
                return 1;
            }

            // If JSON output is requested
            if ($this->option('json')) {
                $this->line(json_encode($flagDetails, JSON_PRETTY_PRINT));
                return 0;
            }

            // Display flag details
            $this->displayFlagDetails($flagDetails, $projectKey, $environmentKey);

            return 0;
        } catch (GuzzleException $e) {
            $this->error('Error connecting to LaunchDarkly: ' . $e->getMessage());
            return 1;
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * Display detailed information about a flag.
     *
     * @param array $flag
     * @param string $projectKey
     * @param string|null $environmentKey
     * @return void
     */
    protected function displayFlagDetails($flag, $projectKey, $environmentKey = null)
    {
        $this->info('==== Flag Details ====');
        $this->line("<fg=green>Key:</> {$flag['key']}");
        $this->line("<fg=green>Name:</> {$flag['name']}");
        $this->line("<fg=green>Description:</> " . ($flag['description'] ?? 'N/A'));
        $this->line("<fg=green>Kind:</> " . ($flag['kind'] ?? 'N/A'));
        $this->line("<fg=green>Created:</> " . ($flag['creationDate'] ?? 'Unknown'));

        if (!empty($flag['tags'])) {
            $this->line("<fg=green>Tags:</> " . implode(', ', $flag['tags']));
        }

        $this->line("<fg=green>Temporary:</> " . ($flag['temporary'] ? 'Yes' : 'No'));
        $this->line("<fg=green>Archived:</> " . ($flag['archived'] ? 'Yes' : 'No'));
        $this->line("<fg=green>Deprecated:</> " . ($flag['deprecated'] ? 'Yes' : 'No'));

        // Show client-side availability
        if (isset($flag['clientSideAvailability'])) {
            $this->newLine();
            $this->info('==== Client-side Availability ====');
            $this->line("<fg=green>Using Mobile Key:</> " . ($flag['clientSideAvailability']['usingMobileKey'] ? 'Yes' : 'No'));
            $this->line("<fg=green>Using Environment ID:</> " . ($flag['clientSideAvailability']['usingEnvironmentId'] ? 'Yes' : 'No'));
        }

        // Show defaults
        if (isset($flag['defaults'])) {
            $this->newLine();
            $this->info('==== Default Settings ====');
            $onVariation = $flag['defaults']['onVariation'] ?? 'N/A';
            $offVariation = $flag['defaults']['offVariation'] ?? 'N/A';
            $this->line("<fg=green>On Variation:</> {$onVariation}");
            $this->line("<fg=green>Off Variation:</> {$offVariation}");
        }

        // Show maintainer info
        if (isset($flag['maintainer'])) {
            $this->newLine();
            $this->info('==== Maintainer ====');
            $maintainer = $flag['maintainer'];
            $this->line("<fg=green>Name:</> {$maintainer['firstName']} {$maintainer['lastName']}");
            $this->line("<fg=green>Email:</> {$maintainer['email']}");
            $this->line("<fg=green>Role:</> {$maintainer['role']}");
        }

        // Show variations
        if (!empty($flag['variations'])) {
            $this->newLine();
            $this->info('==== Variations ====');

            foreach ($flag['variations'] as $index => $variation) {
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

        // Show custom properties if present
        if (!empty($flag['customProperties'])) {
            $this->newLine();
            $this->info('==== Custom Properties ====');
            foreach ($flag['customProperties'] as $key => $property) {
                $propName = $property['name'] ?? $key;
                $propValue = is_array($property['value'])
                    ? implode(', ', $property['value'])
                    : $property['value'];
                $this->line("<fg=green>{$propName}:</> {$propValue}");
            }
        }

        // Get environment-specific details if specified
        if ($environmentKey) {
            if (isset($flag['environments'][$environmentKey])) {
                $flagEnv = $flag['environments'][$environmentKey];

                $this->newLine();
                $this->info("==== Environment: {$environmentKey} ====");
                $this->line("<fg=green>Status:</> " . ($flagEnv['on'] ? 'ON' : 'OFF'));

                if (isset($flagEnv['lastModified'])) {
                    $this->line("<fg=green>Last Modified:</> " . $flagEnv['lastModified']);
                }

                if (isset($flagEnv['version'])) {
                    $this->line("<fg=green>Version:</> " . $flagEnv['version']);
                }

                if (isset($flagEnv['fallthrough'])) {
                    $this->line("<fg=green>Default rule:</> " . $this->describeVariation($flagEnv['fallthrough'], $flag['variations']));
                }

                // Show targeting rules if present
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

                        // Determine what this rule returns
                        if (isset($rule['variation'])) {
                            $returnValue = $this->describeVariation(['variation' => $rule['variation']], $flag['variations']);
                        } elseif (isset($rule['rollout'])) {
                            $returnValue = $this->describeVariation(['rollout' => $rule['rollout']], $flag['variations']);
                        } else {
                            $returnValue = 'Unknown';
                        }

                        $this->line("  Returns: " . $returnValue);

                        if (isset($rule['trackEvents'])) {
                            $this->line("  Track Events: " . ($rule['trackEvents'] ? 'Yes' : 'No'));
                        }

                        $this->newLine();
                    }
                }

                // Show targets (specific users) if present
                if (!empty($flagEnv['targets'])) {
                    $this->info('==== User Targets ====');

                    foreach ($flagEnv['targets'] as $target) {
                        if (!empty($target['values'])) {
                            $variationDesc = isset($flag['variations'][$target['variation']])
                                ? ($flag['variations'][$target['variation']]['name'] ?? "Variation {$target['variation']}")
                                : "Variation {$target['variation']}";

                            $this->line("<fg=yellow>{$variationDesc}:</> " . implode(', ', $target['values']));
                        }
                    }
                }

                // Show context targets if present (new in LD)
                if (!empty($flagEnv['contextTargets'])) {
                    $this->newLine();
                    $this->info('==== Context Targets ====');

                    foreach ($flagEnv['contextTargets'] as $target) {
                        if (!empty($target['values'])) {
                            $variationDesc = isset($flag['variations'][$target['variation']])
                                ? ($flag['variations'][$target['variation']]['name'] ?? "Variation {$target['variation']}")
                                : "Variation {$target['variation']}";

                            $contextKind = isset($target['contextKind']) ? " ({$target['contextKind']})" : "";
                            $this->line("<fg=yellow>{$variationDesc}{$contextKind}:</> " . implode(', ', $target['values']));
                        }
                    }
                }

                // Show prerequisites if present
                if (!empty($flagEnv['prerequisites'])) {
                    $this->newLine();
                    $this->info('==== Prerequisites ====');

                    foreach ($flagEnv['prerequisites'] as $prereq) {
                        $this->line("<fg=green>Flag {$prereq['key']}</> must be variation {$prereq['variation']}");
                    }
                }

                // Show environment summary if available
                if (isset($flagEnv['summary'])) {
                    $this->newLine();
                    $this->info('==== Environment Summary ====');

                    if (isset($flagEnv['summary']['variations'])) {
                        foreach ($flagEnv['summary']['variations'] as $varIndex => $summary) {
                            $variationName = isset($flag['variations'][$varIndex]) && isset($flag['variations'][$varIndex]['name'])
                                ? $flag['variations'][$varIndex]['name']
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
            } else {
                $this->warn("Environment '{$environmentKey}' not found or not accessible for this flag.");
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
}