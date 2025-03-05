<?php

use App\Services\LaunchDarklyService;

beforeEach(function () {
    // Create a mock of the LaunchDarklyService
    $this->ldService = Mockery::mock(LaunchDarklyService::class);
    // Bind the mock to the container BEFORE creating the command
    $this->app->instance(LaunchDarklyService::class, $this->ldService);

    // Set up mock data
    $mockFlags = [
        [
            'key' => 'flag-1',
            'name' => 'First Flag',
            'kind' => 'boolean',
            'tags' => ['test', 'example'],
            'temporary' => false,
            'variations' => [
                ['value' => true],
                ['value' => false]
            ],
            'description' => 'This is the first flag'
        ],
        [
            'key' => 'flag-2',
            'name' => 'Second Flag',
            'kind' => 'multivariate',
            'tags' => ['production'],
            'temporary' => true,
            'variations' => [
                ['value' => 'A'],
                ['value' => 'B'],
                ['value' => 'C']
            ],
            'description' => 'This is the second flag'
        ]
    ];

    // Mock flag details
    $mockFlagDetails1 = [
        'key' => 'flag-1',
        'name' => 'First Flag',
        'tags' => ['test'],
        'description' => 'First flag description',
        'variations' => [
            ['name' => 'True', 'value' => true],
            ['name' => 'False', 'value' => false]
        ],
        'environments' => [
            'production' => [
                'on' => true,
                'rules' => [['id' => 'rule1'], ['id' => 'rule2']],
                'fallthrough' => ['variation' => 0]
            ]
        ]
    ];

    $mockFlagDetails2 = [
        'key' => 'flag-2',
        'name' => 'Second Flag',
        'tags' => ['production'],
        'description' => 'Second flag description',
        'variations' => [
            ['name' => 'On', 'value' => true],
            ['name' => 'Off', 'value' => false]
        ],
        'environments' => [
            'production' => [
                'on' => false,
                'rules' => [],
                'fallthrough' => ['variation' => 1]
            ]
        ]
    ];

    // Set up expectations
    $this->ldService
        ->shouldReceive('getFlags')
        ->with('my-project')
        ->andReturn($mockFlags);

    $this->ldService
        ->shouldReceive('getFlagDetails')
        ->with('my-project', 'flag-1')
        ->andReturn($mockFlagDetails1);

    $this->ldService
        ->shouldReceive('getFlagDetails')
        ->with('my-project', 'flag-2')
        ->andReturn($mockFlagDetails2);
});

afterEach(function () {
    Mockery::close();
});

test('flags:list command returns all flags for a project', function () {
    // Execute the command
    $commandTester = $this->artisan('flags:list', [
        '--project' => 'my-project',
        '--environment' => 'production',
    ]);

    // Make assertions
    $commandTester->assertExitCode(0);
    $commandTester->expectsOutput('Getting LaunchDarkly flags for project: my-project');
    $commandTester->expectsOutput('Total flags: 2');
});

test('flags:list command filters by tag', function () {
    // Execute the command with tag filter
    $commandTester = $this->artisan('flags:list', [
        '--project' => 'my-project',
        '--environment' => 'production',
        '--tag' => 'production'
    ]);

    // Verify successful execution
    $commandTester->assertExitCode(0);

    // Verify that the output contains expected messages
    $commandTester->expectsOutput('Getting LaunchDarkly flags for project: my-project');

    // Verify flag count - should only be one flag with 'production' tag
    $commandTester->expectsOutput('Total flags: 1');

    // Verify that flag-2 is shown in the table (it has the 'production' tag)
    $commandTester->expectsOutputToContain('flag-2');

    // Verify that flag-1 is NOT shown (it doesn't have the 'production' tag)
    $commandTester->doesntExpectOutputToContain('flag-1');
});

test('flags:list command handles no flags matching tag', function () {
    // Execute the command with a non-existent tag
    $commandTester = $this->artisan('flags:list', [
        '--project' => 'my-project',
        '--tag' => 'non-existent-tag'
    ]);

    // Verify successful execution
    $commandTester->assertExitCode(0);

    // Verify expected messages
    $commandTester->expectsOutput('Getting LaunchDarkly flags for project: my-project');
    $commandTester->expectsOutput('No flags found with tag: non-existent-tag');
});

