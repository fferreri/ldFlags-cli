<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

class LaunchDarklyService
{
    /**
     * LaunchDarkly API base URL.
     */
    protected string $apiUrl;

    /**
     * Guzzle HTTP client instance.
     */
    protected Client $client;

    /**
     * Test mode flag.
     */
    protected bool $testMode = false;

    /**
     * Constructor.
     *
     * The $testMode parameter allows bypassing API connection during testing.
     */
    public function __construct(bool $testMode = false)
    {
        $this->testMode = $testMode;
        $this->apiUrl = config('launchdarkly.api_url', 'https://app.launchdarkly.com/api/v2');

        if ($testMode) {
            // In test mode, create a client with a mock handler that returns empty responses
            // This won't be used in tests because we'll mock the service methods
            $mock = new MockHandler([
                new Response(200, [], '{"items":[]}')
            ]);

            $handlerStack = HandlerStack::create($mock);
            $this->client = new Client(['handler' => $handlerStack]);
            return;
        }

        $apiKey = config('launchdarkly.api_key');
        if (empty($apiKey)) {
            throw new \RuntimeException('LaunchDarkly API key is required. Configure it in the configuration file.');
        }

        // Initialize Guzzle HTTP client with headers for real API calls
        $this->client = new Client([
            'headers' => [
                'Authorization' => $apiKey,
                'Content-Type' => 'application/json',
            ]
        ]);
    }

    /**
     * Check if running in test mode.
     *
     * @return bool
     */
    public function isTestMode(): bool
    {
        return $this->testMode;
    }

    /**
     * Get all projects from LaunchDarkly.
     *
     * @throws \Exception
     */
    public function getProjects(): array
    {
        if ($this->testMode) {
            // In test mode, return empty array by default (will be mocked in tests)
            return [];
        }

        try {
            $response = $this->client->request('GET', "{$this->apiUrl}/projects");

            if ($response->getStatusCode() !== 200) {
                throw new \Exception("Error fetching projects: " . $response->getStatusCode());
            }

            $data = json_decode($response->getBody()->getContents(), true);
            return $data['items'] ?? [];
        } catch (GuzzleException $e) {
            throw new \Exception("Connection error: " . $e->getMessage());
        }
    }

    /**
     * Get all feature flags for a specific project.
     *
     * @throws \Exception
     */
    public function getFlags(string $projectKey): array
    {
        if ($this->testMode) {
            // In test mode, return empty array by default (will be mocked in tests)
            return [];
        }

        try {
            $response = $this->client->request('GET', "{$this->apiUrl}/flags/{$projectKey}");

            if ($response->getStatusCode() !== 200) {
                throw new \Exception("Error fetching flags for project {$projectKey}: " . $response->getStatusCode());
            }

            $data = json_decode($response->getBody()->getContents(), true);
            return $data['items'] ?? [];
        } catch (GuzzleException $e) {
            throw new \Exception("Connection error: " . $e->getMessage());
        }
    }

    /**
     * Get a specific flag with complete information including environments.
     *
     * @throws \Exception
     */
    public function getFlag(string $projectKey, string $flagKey): ?array
    {
        try {
            $response = $this->client->request('GET', "{$this->apiUrl}/flags/{$projectKey}/{$flagKey}");

            if ($response->getStatusCode() === 200) {
                return json_decode($response->getBody()->getContents(), true);
            }

            throw new \Exception("Error fetching flag: HTTP " . $response->getStatusCode());
        } catch (GuzzleException $e) {
            throw new \Exception("Connection error: " . $e->getMessage());
        }
    }

    /**
     * Get detailed information about a flag including its defaults, maintainer,
     * and custom properties.
     */
    public function getFlagDetails(string $projectKey, string $flagKey): ?array
    {
        $flag = $this->getFlag($projectKey, $flagKey);
        if (!$flag) {
            return null;
        }

        // Extract the most important details
        $details = [
            'key' => $flag['key'] ?? null,
            'name' => $flag['name'] ?? null,
            'description' => $flag['description'] ?? null,
            'kind' => $flag['kind'] ?? null,
            'creationDate' => isset($flag['creationDate']) ? date('Y-m-d H:i:s', $flag['creationDate'] / 1000) : null,
            'tags' => $flag['tags'] ?? [],
            'temporary' => $flag['temporary'] ?? false,
            'archived' => $flag['archived'] ?? false,
            'deprecated' => $flag['deprecated'] ?? false,
            'includeInSnippet' => $flag['includeInSnippet'] ?? false,
            'clientSideAvailability' => $flag['clientSideAvailability'] ?? null,
            'variations' => $flag['variations'] ?? [],
            'defaults' => $flag['defaults'] ?? null,
            'customProperties' => $flag['customProperties'] ?? [],
            'maintainer' => $flag['_maintainer'] ?? $flag['maintainer'] ?? null,
            'environments' => []
        ];

        // Process environments
        if (isset($flag['environments'])) {
            foreach ($flag['environments'] as $envKey => $envData) {
                $details['environments'][$envKey] = [
                    'name' => $envData['_environmentName'] ?? $envData['name'] ?? $envKey,
                    'on' => $envData['on'] ?? false,
                    'lastModified' => isset($envData['lastModified']) ? date('Y-m-d H:i:s', $envData['lastModified'] / 1000) : null,
                    'version' => $envData['version'] ?? null,
                    'targets' => $envData['targets'] ?? [],
                    'contextTargets' => $envData['contextTargets'] ?? [],
                    'rules' => $envData['rules'] ?? [],
                    'fallthrough' => $envData['fallthrough'] ?? null,
                    'offVariation' => $envData['offVariation'] ?? null,
                    'trackEvents' => $envData['trackEvents'] ?? false,
                    'summary' => $envData['_summary'] ?? $envData['summary'] ?? null
                ];
            }
        }

        return $details;
    }

    /**
     * Get flag details for a specific environment.
     *
     * This method can be used in two ways:
     * 1. If you already have the full flag data from getFlag(), you can pass the environmentKey
     *    to extract just that environment's data.
     * 2. If you don't have the full flag data, it will make a separate API call.
     *
     * @throws \Exception
     */
    public function getFlagEnvironment(string $projectKey, string $flagKey, string $environmentKey, ?array $flagData = null): ?array
    {
        // If flag data is provided, extract the environment info from it
        if ($flagData) {
            if (isset($flagData['environments'][$environmentKey])) {
                $envData = $flagData['environments'][$environmentKey];
                // Add the flag key and environment name for consistency
                $envData['key'] = $flagKey;
                $envData['environmentKey'] = $environmentKey;
                return $envData;
            } else {
                throw new \Exception("Environment '{$environmentKey}' not found in flag data. Available environments: " .
                    implode(', ', array_keys($flagData['environments'] ?? [])));
            }
        }

        // If no flag data provided, make a direct API call
        try {
            // First get the full flag data to ensure we have the right environment key
            $fullFlagData = $this->getFlag($projectKey, $flagKey);

            if (!$fullFlagData) {
                throw new \Exception("Flag '{$flagKey}' not found");
            }

            // Check if the environment exists in the flag data
            if (!isset($fullFlagData['environments'][$environmentKey])) {
                throw new \Exception("Environment '{$environmentKey}' not found in flag data. Available environments: " .
                    implode(', ', array_keys($fullFlagData['environments'] ?? [])));
            }

            // Now make the specific environment API call
            $response = $this->client->request('GET', "{$this->apiUrl}/flags/{$projectKey}/{$flagKey}/environments/{$environmentKey}");

            if ($response->getStatusCode() === 200) {
                $envData = json_decode($response->getBody()->getContents(), true);
                // Add environment key for consistency
                $envData['environmentKey'] = $environmentKey;
                return $envData;
            }

            throw new \Exception("Error fetching environment: HTTP " . $response->getStatusCode());
        } catch (GuzzleException $e) {
            throw new \Exception("Connection error: " . $e->getMessage());
        }
    }

    /**
     * Update a flag with JSON patch.
     *
     * @throws \Exception
     */
    public function updateFlagWithJsonPatch(
        string $projectKey,
        string $flagKey,
        array $patches,
        ?string $comment = null
    ): bool {
        try {
            $payload = [
                'patch' => $patches
            ];

            if ($comment) {
                $payload['comment'] = $comment;
            }

            $response = $this->client->request('PATCH', "{$this->apiUrl}/flags/{$projectKey}/{$flagKey}", [
                'json' => $payload
            ]);

            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            throw new \Exception("Connection error: " . $e->getMessage());
        }
    }

    /**
     * Add a targeting rule to a feature flag using semantic patch.
     *
     * @throws \Exception
     */
    public function addTargetingRule(
        string $projectKey,
        string $flagKey,
        string $environmentKey,
        array $rule,
        ?string $comment = null,
        int $position = 0
    ): bool {
        try {
            // First verify flag and environment exist
            $flagData = $this->getFlag($projectKey, $flagKey);

            if (!$flagData) {
                throw new \Exception("Could not fetch flag configuration for {$flagKey}");
            }

            // Check if the environment exists
            if (!isset($flagData['environments'][$environmentKey])) {
                $availableEnvs = array_keys($flagData['environments'] ?? []);
                throw new \Exception("Environment {$environmentKey} not found for flag {$flagKey}. Available environments: " . implode(', ', $availableEnvs));
            }

            // Get existing rules to determine position
            $existingRules = $flagData['environments'][$environmentKey]['rules'] ?? [];

            // Create instruction for adding rule
            $instruction = [
                'kind' => 'addRule'
            ];

            // Add clauses
            if (isset($rule['clauses'])) {
                $instruction['clauses'] = $rule['clauses'];
            }

            // Add description if provided
            if (isset($rule['description'])) {
                $instruction['description'] = $rule['description'];
            }

            // Add tracking if specified
            if (isset($rule['trackEvents'])) {
                $instruction['trackEvents'] = $rule['trackEvents'];
            }

            // Add variation or rollout based on what's provided in the rule
            if (isset($rule['variation'])) {
                $instruction['variationId'] = $rule['variation'];
            } elseif (isset($rule['rollout'])) {
                $instruction['rolloutContextKind'] = $rule['rollout']['contextKind'] ?? 'request';
                $instruction['rolloutBucketBy'] = $rule['rollout']['bucketBy'] ?? 'key';

                // Format rollout weights as expected by LD API
                $instruction['rolloutWeights'] = [];
                foreach ($rule['rollout']['variations'] as $variation) {
                    // Use variation ID if available, otherwise use index
                    $variationId = $variation['variationId'] ?? $variation['variation'];
                    $instruction['rolloutWeights'][$variationId] = $variation['weight'];
                }
            }

            // Add position information if specified
            if ($position > 0 && !empty($existingRules)) {
                // Make sure position is in range
                $actualPosition = min($position, count($existingRules));

                // If there's a valid position and there are rules
                if ($actualPosition < count($existingRules) && !empty($existingRules[$actualPosition]['_id'])) {
                    $instruction['beforeRuleId'] = $existingRules[$actualPosition]['_id'];
                }
            }

            // Prepare the payload
            $payload = [
                'environmentKey' => $environmentKey,
                'instructions' => [$instruction]
            ];

            // Add optional comment if provided
            if ($comment) {
                $payload['comment'] = $comment;
            }

            // Send the semantic patch request
            $response = $this->client->request('PATCH', "{$this->apiUrl}/flags/{$projectKey}/{$flagKey}", [
                'headers' => [
                    'Content-Type' => 'application/json; domain-model=launchdarkly.semanticpatch'
                ],
                'json' => $payload
            ]);

            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            throw new \Exception("Connection error: " . $e->getMessage());
        }
    }

    /**
     * Create a rollout rule for API endpoint matching with percentage rollout.
     */
    public function createEndpointMatchingRule(
        string $ruleName,
        string $endpointPattern,
        array $percentages = [100, 0],
        string $rolloutAttribute = 'key',
        string $contextKind = 'request',
        bool $trackEvents = true,
        array $variationIds = []
    ): array {
        // Build the rule structure
        $rule = [
            'description' => $ruleName,
            'trackEvents' => $trackEvents,
            'clauses' => [
                [
                    'attribute' => 'endpoint_pattern',
                    'op' => 'matches',
                    'values' => [$endpointPattern],
                    'negate' => false,
                    'contextKind' => $contextKind
                ]
            ],
            'rollout' => [
                'bucketBy' => $rolloutAttribute,
                'contextKind' => $contextKind,
                'variations' => []
            ]
        ];

        // Add variations with weights
        // If we have variation IDs, use them, otherwise use indices
        if (count($variationIds) >= 2) {
            $rule['rollout']['variations'] = [
                [
                    'variationId' => $variationIds[0],
                    'weight' => $percentages[0] * 1000
                ],
                [
                    'variationId' => $variationIds[1],
                    'weight' => $percentages[1] * 1000
                ]
            ];
        } else {
            $rule['rollout']['variations'] = [
                [
                    'variation' => 0,
                    'weight' => $percentages[0] * 1000
                ],
                [
                    'variation' => 1,
                    'weight' => $percentages[1] * 1000
                ]
            ];
        }

        return $rule;
    }

    /**
     * Get flag variations for a specific project and flag.
     */
    public function getFlagVariations(string $projectKey, string $flagKey): array
    {
        $flag = $this->getFlag($projectKey, $flagKey);
        if ($flag && isset($flag['variations'])) {
            return $flag['variations'];
        }
        return [];
    }

    /**
     * Get all environments for a specific project with detailed information.
     *
     * @throws \Exception
     */
    public function getProjectEnvironments(string $projectKey): array
    {
        try {
            // Use the expand=environments query parameter to get all environments in one request
            $response = $this->client->request('GET', "{$this->apiUrl}/projects/{$projectKey}?expand=environments");

            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getBody()->getContents(), true);

                // Extract and process environments data
                $environments = [];

                if (isset($data['environments']) && isset($data['environments']['items'])) {
                    foreach ($data['environments']['items'] as $env) {
                        $environments[] = [
                            'key' => $env['key'],
                            'name' => $env['name'],
                            'color' => $env['color'] ?? null,
                            'default' => $env['_id'] === ($data['defaultEnvironment'] ?? '') || ($env['key'] === 'production'),
                            'secure' => $env['secureMode'] ?? false,
                            'mobileSdkKey' => $env['mobileKey'] ?? null,
                            'clientSideId' => $env['clientSideId'] ?? null,
                            'apiKey' => $env['apiKey'] ?? null,
                            'tags' => $env['tags'] ?? [],
                            'requireComments' => $env['requireComments'] ?? false,
                            'confirmChanges' => $env['confirmChanges'] ?? false,
                            'approvalSettings' => $env['approvalSettings'] ?? null,
                            'critical' => $env['critical'] ?? false,
                        ];
                    }
                }

                return $environments;
            }

            throw new \Exception("Error getting environments: HTTP " . $response->getStatusCode());
        } catch (GuzzleException $e) {
            throw new \Exception("Connection error: " . $e->getMessage());
        }
    }

    /**
     * Get the status of a specific flag in a project and optionally in a specific environment.
     *
     * @throws \Exception
     */
    public function getFlagStatus(string $projectKey, string $flagKey, ?string $environmentKey = null): ?array
    {
        try {
            $url = "{$this->apiUrl}/flags/{$projectKey}/{$flagKey}/environments";
            if ($environmentKey) {
                $url .= "/{$environmentKey}";
            }

            $response = $this->client->get($url);
            $data = json_decode($response->getBody()->getContents(), true);

            return $data;
        } catch (GuzzleException $e) {
            return null;
        }
    }
}
