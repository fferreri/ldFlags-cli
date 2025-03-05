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
     * @return mixed
     */
    public function handle(LaunchDarklyService $ldService)
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
    protected function displayEnvironmentsTable($environments)
    {
        // Sort environments, putting critical ones first, then alphabetically
        usort($environments, function($a, $b) {
            if ($a['critical'] !== $b['critical']) {
                return $b['critical'] ? 1 : -1; // Critical environments first
            }
            return strcmp($a['name'], $b['name']); // Then alphabetically
        });

        // Prepare table data
        $tableData = [];
        foreach ($environments as $env) {
            $row = [
                'key' => $env['key'],
                'name' => $env['name'],
                'critical' => $env['critical'] ? 'Yes' : 'No',
                'secure' => $env['secure'] ? 'Yes' : 'No',
            ];

            // Format color if available
            if (isset($env['color']) && !empty($env['color'])) {
                $row['color'] = "#{$env['color']}";
            }

            // Include client-side ID if available
            if (isset($env['clientSideId']) && !empty($env['clientSideId'])) {
                $row['clientSideId'] = $env['clientSideId'];
            }

            // Include mobile SDK key if available (truncated for security)
            if (isset($env['mobileSdkKey']) && !empty($env['mobileSdkKey'])) {
                $row['mobileSdkKey'] = substr($env['mobileSdkKey'], 0, 8) . '...';
            }

            // Add approval settings if available
            if (isset($env['approvalSettings']) && is_array($env['approvalSettings'])) {
                $row['approvals'] = $env['approvalSettings']['required'] ? 'Required' : 'Optional';

                if (isset($env['approvalSettings']['minNumApprovals']) && $env['approvalSettings']['minNumApprovals'] > 1) {
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

            $tableData[] = $row;
        }

        // Define headers based on available data
        $headers = array_keys($tableData[0]);

        // Format headers for display
        $formattedHeaders = array_map(function($header) {
            return ucfirst(preg_replace('/(?<!^)[A-Z]/', ' $0', $header));
        }, $headers);

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
}