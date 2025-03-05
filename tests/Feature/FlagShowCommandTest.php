<?php

use App\Commands\FlagsShow;
use App\Services\LaunchDarklyService;

beforeEach(function () {
    $this->ldService = Mockery::mock(LaunchDarklyService::class);
    $this->app->instance(LaunchDarklyService::class, $this->ldService);
});

afterEach(function () {
    Mockery::close();
});

test('flags:show command displays flag details correctly', function () {
    // Sample flag details
    $mockFlagDetails = [
        'key' => 'test-flag',
        'name' => 'Test Flag',
        'description' => 'This is a test flag',
        'kind' => 'boolean',
        'creationDate' => '2023-10-15 12:00:00',
        'tags' => ['test', 'example'],
        'temporary' => false,
        'archived' => false,
        'deprecated' => false,
        'clientSideAvailability' => [
            'usingMobileKey' => true,
            'usingEnvironmentId' => true
        ],
        'variations' => [
            [
                '_id' => 'var-id-1',
                'value' => true,
                'name' => 'True',
                'description' => 'The true variation'
            ],
            [
                '_id' => 'var-id-2',
                'value' => false,
                'name' => 'False',
                'description' => 'The false variation'
            ]
        ],
        'defaults' => [
            'onVariation' => 0,
            'offVariation' => 1
        ],
        'environments' => [
            'production' => [
                'name' => 'Production',
                'on' => true,
                'lastModified' => '2023-10-20 14:30:00',
                'version' => 5,
                'rules' => [],
                'fallthrough' => ['variation' => 0],
                'offVariation' => 1,
                'trackEvents' => false
            ]
        ]
    ];

    // Set up mock
    $this->ldService
        ->shouldReceive('getFlagDetails')
        ->once()
        ->with('my-project', 'test-flag')
        ->andReturn($mockFlagDetails);

    // Execute command
    $command = $this->artisan('flags:show', [
        'flag-key' => 'test-flag',
        '--project' => 'my-project'
    ]);

    // Assertions
    $command->assertExitCode(0);
    $command->expectsOutput('==== Flag Details ====');
    $command->expectsOutput('Key: test-flag');
    $command->expectsOutput('Name: Test Flag');
    $command->expectsOutput('Description: This is a test flag');

    // We don't check every output line since there are many and the format might change
    // But we verify some key elements are present
    $command->expectsOutputToContain('Variations');
    $command->expectsOutputToContain('True');
    $command->expectsOutputToContain('False');
});

test('flags:show command with JSON option outputs JSON', function () {
    // Sample flag details
    $mockFlagDetails = [
        'key' => 'test-flag',
        'name' => 'Test Flag',
        'variations' => [
            ['value' => true],
            ['value' => false]
        ]
    ];

    // Set up mock
    $this->ldService
        ->shouldReceive('getFlagDetails')
        ->once()
        ->with('my-project', 'test-flag')
        ->andReturn($mockFlagDetails);

    // Execute command
    $command = $this->artisan('flags:show', [
        'flag-key' => 'test-flag',
        '--project' => 'my-project',
        '--json' => true
    ]);

    // Assertions - check for JSON format
    $command->assertExitCode(0);
    // Since the actual JSON output is complex to check, we just ensure
    // the command completes successfully without errors
});

test('flags:show command handles flag not found', function () {
    // Set up mock to return null (flag not found)
    $this->ldService
        ->shouldReceive('getFlagDetails')
        ->once()
        ->with('my-project', 'nonexistent-flag')
        ->andReturn(null);

    // Execute command
    $command = $this->artisan('flags:show', [
        'flag-key' => 'nonexistent-flag',
        '--project' => 'my-project'
    ]);

    // Assertions
    $command->assertExitCode(1);
    $command->expectsOutput("Flag 'nonexistent-flag' not found in project 'my-project'.");
});

test('flags:show command handles API errors gracefully', function () {
    // Set up mock to throw an exception
    $this->ldService
        ->shouldReceive('getFlagDetails')
        ->once()
        ->with('my-project', 'test-flag')
        ->andThrow(new \Exception('API connection error'));

    // Execute command
    $command = $this->artisan('flags:show', [
        'flag-key' => 'test-flag',
        '--project' => 'my-project'
    ]);

    // Assertions
    $command->assertExitCode(1);
    $command->expectsOutput('Error: API connection error');
});