<?php

namespace App\Commands;

use App\Services\LaunchDarklyService;
use LaravelZero\Framework\Commands\Command;
use GuzzleHttp\Exception\GuzzleException;

class FlagsStatus extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'flags:status
                            {flag-key : The key of the feature flag to check status}
                            {--project= : LaunchDarkly project ID}
                            {--environment= : Filter by specific environment}
                            {--json : Output in JSON format}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Show flag status across environments';

    /**
     * LaunchDarkly service instance.
     *
     * @var \App\Services\LaunchDarklyService
     */
    protected LaunchDarklyService $ldService;

    /**
     * Status name colors.
     *
     * @var array
     */
    protected array $statusColors = [
        'new' => 'blue',
        'inactive' => 'yellow',
        'active' => 'green',
        'launched' => 'cyan'
    ];

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
            $flagKey = $this->argument('flag-key');
            $projectKey = $this->option('project') ?: config('launchdarkly.default_project');
            $environmentKey = $this->option('environment');

            if (empty($projectKey)) {
                $this->error('No project specified and no default project configured.');
                return 1;
            }

            // Get flag status across environments
            $flagStatus = $this->ldService->getFlagStatus($projectKey, $flagKey, $environmentKey);

            if (!$flagStatus) {
                $this->error("Flag '{$flagKey}' not found in project '{$projectKey}'.");
                return 1;
            }

            // If JSON output is requested
            if ($this->option('json')) {
                $this->line(json_encode($flagStatus, JSON_PRETTY_PRINT));
                return 0;
            }

            // Display flag status information
            $this->displayFlagStatus($flagStatus, $flagKey, $projectKey);

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
     * Display flag status information.
     *
     * @param array $flagStatus
     * @param string $flagKey
     * @param string $projectKey
     * @return void
     */
    protected function displayFlagStatus(array $flagStatus, string $flagKey, string $projectKey): void
    {
        $this->info("Flag Status: {$flagKey} (Project: {$projectKey})");
        $this->newLine();

        // Create a table of environments and their status
        $tableData = [];

        foreach ($flagStatus['environments'] as $envKey => $envStatus) {
            $statusName = $envStatus['name'];
            $lastRequested = isset($envStatus['lastRequested'])
                ? date('Y-m-d H:i:s', strtotime($envStatus['lastRequested']))
                : 'Never';

            $tableData[] = [
                'environment' => $envKey,
                'status' => "<fg={$this->statusColors[$statusName]}>{$statusName}</fg={$this->statusColors[$statusName]}>",
                'lastRequested' => $lastRequested,
                'defaultValue' => isset($envStatus['default']) ? json_encode($envStatus['default']) : 'N/A'
            ];
        }

        // Sort environments alphabetically
        usort($tableData, fn($a, $b) => strcmp($a['environment'], $b['environment']));

        $this->table(
            ['Environment', 'Status', 'Last Requested', 'Default Value'],
            $tableData
        );

        // Display status descriptions
        $this->newLine();
        $this->info('Status Descriptions:');
        $this->line('  <fg=blue>new</> - Flag has been created but not used yet');
        $this->line('  <fg=yellow>inactive</> - Flag exists but is not requested by your application');
        $this->line('  <fg=green>active</> - Flag is actively being requested by your application');
        $this->line('  <fg=cyan>launched</> - Flag has been rolled out to all users (100% rule)');
    }
}