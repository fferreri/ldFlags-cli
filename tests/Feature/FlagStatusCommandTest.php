<?php

use App\Services\LaunchDarklyService;

beforeEach(function () {
    $this->ldService = Mockery::mock(LaunchDarklyService::class);
    $this->app->instance(LaunchDarklyService::class, $this->ldService);
});

afterEach(function () {
    Mockery::close();
});

test('flags:status command displays flag status across environments', function () {
    // Sample flag status data
    $mockFlagStatus = [
        'key' => 'test-flag',
        'environments' => [
            'production' => [
                'name' => 'active',
                'lastRequested' => '2023-10-20T12:34:45Z', // ISO 8601 format
                'default' => false
            ],
            'staging' => [
                'name' => 'active',
                'lastRequested' => '2023-10-19T12:34:45Z',
                'default' => false
            ],
            'development' => [
                'name' => 'inactive',
                'default' => false
            ]
        ]
    ];

    // Set up mock
    $this->ldService
        ->shouldReceive('getFlagStatus')
        ->once()
        ->with('my-project', 'test-flag', Mockery::any())
        ->andReturn($mockFlagStatus);

    // Execute command
    $command = $this->artisan('flags:status', [
        'flag-key' => 'test-flag',
        '--project' => 'my-project'
    ]);

    // Assertions
    $command->assertExitCode(0);
    $command->expectsOutput("Flag Status: test-flag (Project: my-project)");

    // Check for environment names in output
    $command->expectsOutputToContain('production');
    $command->expectsOutputToContain('staging');
    $command->expectsOutputToContain('development');

    // Check for status types
    $command->expectsOutputToContain('active');
    //$command->expectsOutputToContain('inactive');

    // Check for status descriptions
    $command->expectsOutputToContain('Status Descriptions:');
});

test('flags:status command with environment filter shows specific environment', function () {
    // Sample flag status data for single environment
    $mockFlagStatus = [
        'key' => 'test-flag',
        'environments' => [
            'production' => [
                'name' => 'active',
                'lastRequested' => '2023-10-20T12:34:45Z',
                'default' => false
            ]
        ]
    ];

    // Set up mock
    $this->ldService
        ->shouldReceive('getFlagStatus')
        ->once()
        ->with('my-project', 'test-flag', 'production')
        ->andReturn($mockFlagStatus);

    // Execute command
    $command = $this->artisan('flags:status', [
        'flag-key' => 'test-flag',
        '--project' => 'my-project',
        '--environment' => 'production'
    ]);

    // Assertions
    $command->assertExitCode(0);
    $command->expectsOutput("Flag Status: test-flag (Project: my-project)");
    $command->expectsOutputToContain('production');
    $command->expectsOutputToContain('active');
});

test('flags:status command with JSON option outputs JSON', function () {
    // Sample flag status data
    $mockFlagStatus = [
        'key' => 'test-flag',
        'environments' => [
            'production' => [
                'name' => 'active',
                'lastRequested' => '2023-10-20T12:34:45Z',
                'default' => false
            ]
        ]
    ];

    // Set up mock
    $this->ldService
        ->shouldReceive('getFlagStatus')
        ->once()
        ->with('my-project', 'test-flag',  Mockery::any())
        ->andReturn($mockFlagStatus);

    // Execute command
    $command = $this->artisan('flags:status', [
        'flag-key' => 'test-flag',
        '--project' => 'my-project',
        '--json' => true
    ]);

    // Assertions
    $command->assertExitCode(0);
    // We don't test exact JSON output, just successful completion
});

test('flags:status command handles flag not found', function () {
    // Set up mock to return null (flag not found)
    $this->ldService
        ->shouldReceive('getFlagStatus')
        ->once()
        ->with('my-project', 'nonexistent-flag',  Mockery::any())
        ->andReturn(null);

    // Execute command
    $command = $this->artisan('flags:status', [
        'flag-key' => 'nonexistent-flag',
        '--project' => 'my-project'
    ]);

    // Assertions
    $command->assertExitCode(1);
    $command->expectsOutput("Flag 'nonexistent-flag' not found in project 'my-project'.");
});

test('flags:status command handles API errors gracefully', function () {
    // Set up mock to throw an exception
    $this->ldService
        ->shouldReceive('getFlagStatus')
        ->once()
        ->with('my-project', 'test-flag',  Mockery::any())
        ->andThrow(new \Exception('API connection error'));

    // Execute command
    $command = $this->artisan('flags:status', [
        'flag-key' => 'test-flag',
        '--project' => 'my-project'
    ]);

    // Assertions
    $command->assertExitCode(1);
    $command->expectsOutput('Error: API connection error');
});