<?php

namespace App\Commands;

use App\Services\LaunchDarklyService;
use LaravelZero\Framework\Commands\Command;
use GuzzleHttp\Exception\GuzzleException;

class EnvironmentsList extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'environments:list
                            {--project= : LaunchDarkly project ID}
                            {--json : Output in JSON format}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'List all environments for a LaunchDarkly project';

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
     * @param LaunchDarklyService $ldService
     * @return int
     */
    public function handle(LaunchDarklyService $ldService): int
    {
        $this->ldService = $ldService;

        try {
            $projectKey = $this->option('project') ?: config('launchdarkly.default_project');

            if (empty($projectKey)) {
                $this->error('No project specified and no default project configured.');
                return 1;
            }

            $this->info("Fetching environments for project: {$projectKey}");

            // Get environments for the project
            $environments = $this->ldService->getProjectEnvironments($projectKey);

            if (empty($environments)) {
                $this->warn("No environments found for project: {$projectKey}");
                return 0;
            }

            // If JSON output is requested
            if ($this->option('json')) {
                $this->line(json_encode($environments, JSON_PRETTY_PRINT));
                return 0;
            }

            // Display environments in a table
            $this->displayEnvironmentsTable($environments);

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
     * Display environments in a table format.
     *
     * @param array $environments
     * @return void
     */
    protected function displayEnvironmentsTable(array $environments): void
    {
        // Sort environments, putting critical ones first, then alphabetically
        usort($environments, fn($a, $b) => $a['critical'] !== $b['critical'] ? ($b['critical'] ? 1 : -1) : strcmp($a['name'], $b['name']));

        // Prepare table data
        $tableData = array_map(fn($env) => $this->formatEnvironmentRow($env), $environments);

        // Define headers based on available data
        $headers = array_keys($tableData[0]);

        // Format headers for display
        $formattedHeaders = array_map(fn($header) => ucfirst(preg_replace('/(?<!^)[A-Z]/', ' $0', $header)), $headers);

        // Display table
        $this->table($formattedHeaders, $tableData);
        $this->info("Total environments: " . count($environments));

        // Display environment keys for easy copy-paste
        $this->newLine();
        $this->info("Environment Keys:");
        foreach ($environments as $env) {
            $critical = $env['critical'] ? ' (critical)' : '';
            $this->line("  {$env['key']}{$critical}");
        }
    }

    /**
     * Format a single environment row for the table.
     *
     * @param array $env
     * @return array
     */
    protected function formatEnvironmentRow(array $env): array
    {
        $row = [
            'key' => $env['key'],
            'name' => $env['name'],
            'critical' => $env['critical'] ? 'Yes' : 'No',
            'secure' => $env['secure'] ? 'Yes' : 'No',
        ];

        // Format color if available
        if (!empty($env['color'])) {
            $row['color'] = "#{$env['color']}";
        }

        // Include client-side ID if available
        if (!empty($env['clientSideId'])) {
            $row['clientSideId'] = $env['clientSideId'];
        }

        // Include mobile SDK key if available (truncated for security)
        if (!empty($env['mobileSdkKey'])) {
            $row['mobileSdkKey'] = substr($env['mobileSdkKey'], 0, 8) . '...';
        }

        // Add approval settings if available
        if (!empty($env['approvalSettings']) && is_array($env['approvalSettings'])) {
            $row['approvals'] = $env['approvalSettings']['required'] ? 'Required' : 'Optional';

            if (!empty($env['approvalSettings']['minNumApprovals']) && $env['approvalSettings']['minNumApprovals'] > 1) {
                $row['approvals'] .= " ({$env['approvalSettings']['minNumApprovals']})";
            }
        }

        // Add workflow settings
        $workflow = [];
        if ($env['requireComments']) $workflow[] = 'comments';
        if ($env['confirmChanges']) $workflow[] = 'confirmation';
        if (!empty($workflow)) {
            $row['workflow'] = ucfirst(implode(', ', $workflow));
        }

        // Add any tags
        if (!empty($env['tags'])) {
            $row['tags'] = implode(', ', $env['tags']);
        }

        return $row;
    }
}