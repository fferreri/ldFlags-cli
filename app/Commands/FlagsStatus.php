<?php

namespace App\Commands;

use App\Services\LaunchDarklyService;
use GuzzleHttp\Exception\GuzzleException;

class FlagsStatus extends BaseCommand
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
            $this->initializeOptions();

            if (empty($this->projectKey)) {
                $this->error('No project specified and no default project configured.');
                return 1;
            }

            $this->info("Fetching flag status for project: {$this->projectKey}, flag: {$this->flagKey}");

            $flagStatus = $this->ldService->getFlagStatus($this->projectKey, $this->flagKey, $this->environmentKey);

            if (!$flagStatus) {
                $this->error("Flag '{$this->flagKey}' not found in project '{$this->projectKey}'.");
                return 1;
            }

            if ($this->option('json')) {
                $this->outputJson($flagStatus);
                return 0;
            }

            $this->displayFlagStatus($flagStatus);

            return 0;
        } catch (GuzzleException $e) {
            $this->error('Error connecting to LaunchDarkly: ' . $e->getMessage());
            return 1;
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            return 1;
        }
    }

    protected function initializeOptions(): void
    {
        parent::initializeOptions();
        $this->info("Initialized options: projectKey={$this->projectKey}, environmentKey={$this->environmentKey}, flagKey={$this->flagKey}");
    }

    protected function outputJson(array $flagStatus): void
    {
        parent::outputJson($flagStatus);
    }

    protected function displayFlagStatus(array $flagStatus): void
    {
        $this->info("Flag Status: {$this->flagKey} (Project: {$this->projectKey})");
        $this->newLine();

        $tableData = $this->prepareTableData($flagStatus['environments']);
        $this->displayTable($tableData);
        $this->displayStatusDescriptions();
    }

    protected function prepareTableData(array $environments): array
    {
        $tableData = [];

        foreach ($environments as $envKey => $envStatus) {
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

        usort($tableData, fn($a, $b) => strcmp($a['environment'], $b['environment']));

        return $tableData;
    }

    protected function displayTable(array $tableData): void
    {
        $this->table(
            ['Environment', 'Status', 'Last Requested', 'Default Value'],
            $tableData
        );
    }

    protected function displayStatusDescriptions(): void
    {
        $this->newLine();
        $this->info('Status Descriptions:');
        $this->line('  <fg=blue>new</> - Flag has been created but not used yet');
        $this->line('  <fg=yellow>inactive</> - Flag exists but is not requested by your application');
        $this->line('  <fg=green>active</> - Flag is actively being requested by your application');
        $this->line('  <fg=cyan>launched</> - Flag has been rolled out to all users (100% rule)');
    }

    protected function handleException(\Exception $e): int
    {
        $this->error('Error: ' . $e->getMessage());
        return 1;
    }
}