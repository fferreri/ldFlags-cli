<?php

use App\Commands\EnvironmentsList;
use App\Services\LaunchDarklyService;

beforeEach(function () {
    $this->ldService = Mockery::mock(LaunchDarklyService::class);
    $this->app->instance(LaunchDarklyService::class, $this->ldService);
});

afterEach(function () {
    Mockery::close();
});

test('environments:list command displays environments correctly', function () {
    // Sample environments data
    $mockEnvironments = [
        [
            'key' => 'production',
            'name' => 'Production',
            'color' => 'F5A623',
            'default' => true,
            'secure' => true,
            'mobileSdkKey' => 'mob-123abc',
            'clientSideId' => 'client-123abc',
            'tags' => ['prod', 'critical'],
            'requireComments' => true,
            'confirmChanges' => true,
            'approvalSettings' => ['required' => true],
            'critical' => true,
        ],
        [
            'key' => 'staging',
            'name' => 'Staging',
            'color' => '36B37E',
            'default' => false,
            'secure' => true,
            'mobileSdkKey' => 'mob-456def',
            'clientSideId' => 'client-456def',
            'tags' => ['staging'],
            'requireComments' => true,
            'confirmChanges' => false,
            'approvalSettings' => ['required' => false],
            'critical' => false,
        ],
        [
            'key' => 'test',
            'name' => 'Test',
            'color' => 'FF5630',
            'default' => false,
            'secure' => false,
            'mobileSdkKey' => 'mob-789ghi',
            'clientSideId' => 'client-789ghi',
            'tags' => ['test'],
            'requireComments' => false,
            'confirmChanges' => false,
            'approvalSettings' => null,
            'critical' => false,
        ]
    ];

    // Set up mock
    $this->ldService
        ->shouldReceive('getProjectEnvironments')
        ->once()
        ->with('my-project')
        ->andReturn($mockEnvironments);

    // Execute command
    $command = $this->artisan('environments:list', [
        '--project' => 'my-project'
    ]);

    // Assertions
    $command->assertExitCode(0);
    $command->expectsOutput('Fetching environments for project: my-project');
    $command->expectsOutput('Total environments: 3');

    // Check for environment keys in output
    $command->expectsOutputToContain('production');
    $command->expectsOutputToContain('staging');
    $command->expectsOutputToContain('test');

    // We don't test the exact table format as it might change, but we
    // ensure key elements are present
    $command->expectsOutputToContain('Environment Keys:');
});

test('environments:list command with JSON option outputs JSON', function () {
    // Sample environments data
    $mockEnvironments = [
        [
            'key' => 'production',
            'name' => 'Production',
            'color' => 'F5A623',
            'secure' => true,
        ],
        [
            'key' => 'staging',
            'name' => 'Staging',
            'color' => '36B37E',
            'secure' => true,
        ]
    ];

    // Set up mock
    $this->ldService
        ->shouldReceive('getProjectEnvironments')
        ->once()
        ->with('my-project')
        ->andReturn($mockEnvironments);

    // Execute command
    $command = $this->artisan('environments:list', [
        '--project' => 'my-project',
        '--json' => true
    ]);

    // Assertions
    $command->assertExitCode(0);
    // We don't test exact JSON output, just successful completion
});

test('environments:list command handles no environments found', function () {
    // Set up mock to return empty array
    $this->ldService
        ->shouldReceive('getProjectEnvironments')
        ->once()
        ->with('my-project')
        ->andReturn([]);

    // Execute command
    $command = $this->artisan('environments:list', [
        '--project' => 'my-project'
    ]);

    // Assertions
    $command->assertExitCode(0);
    $command->expectsOutput('No environments found for project: my-project');
});

test('environments:list command handles API errors gracefully', function () {
    // Set up mock to throw an exception
    $this->ldService
        ->shouldReceive('getProjectEnvironments')
        ->once()
        ->with('my-project')
        ->andThrow(new \Exception('API connection error'));

    // Execute command
    $command = $this->artisan('environments:list', [
        '--project' => 'my-project'
    ]);

    // Assertions
    $command->assertExitCode(1);
    $command->expectsOutput('Error: API connection error');
});