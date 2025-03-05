<?php

use App\Services\LaunchDarklyService;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->ldService = Mockery::mock(LaunchDarklyService::class);
    $this->app->instance(LaunchDarklyService::class, $this->ldService);
});

afterEach(function () {
    Mockery::close();
});

test('flags:add-rule command adds rule successfully', function () {
    // Mock flag data
    $mockFlag = [
        'key' => 'api-v6-rollout-endpoints',
        'variations' => [
            [
                '_id' => 'var-id-1',
                'value' => 'v5',
                'name' => 'Restler'
            ],
            [
                '_id' => 'var-id-2',
                'value' => 'v6',
                'name' => 'Laravel'
            ]
        ],
        'environments' => [
            'production' => [
                'on' => true,
                'rules' => []
            ]
        ]
    ];

    // Mock endpoint rule
    $mockRule = [
        'description' => 'Test Rule',
        'trackEvents' => true,
        'clauses' => [
            [
                'attribute' => 'endpoint_pattern',
                'op' => 'matches',
                'values' => ['GET /api/test'],
                'negate' => false,
                'contextKind' => 'request'
            ]
        ],
        'rollout' => [
            'bucketBy' => 'key',
            'contextKind' => 'request',
            'variations' => [
                [
                    'variation' => 0,
                    'weight' => 100000
                ],
                [
                    'variation' => 1,
                    'weight' => 0
                ]
            ]
        ]
    ];

    // Set up mocks
    $this->ldService
        ->shouldReceive('getFlag')
        ->once()
        ->with('my-project', 'api-v6-rollout-endpoints')
        ->andReturn($mockFlag);

    $this->ldService
        ->shouldReceive('createEndpointMatchingRule')
        ->once()
        ->with('Test Rule', 'GET /api/test', [100, 0], 'key', 'request', true, Mockery::any())
        ->andReturn($mockRule);

    $this->ldService
        ->shouldReceive('addTargetingRule')
        ->once()
        ->with('my-project', 'api-v6-rollout-endpoints', 'production', $mockRule, null, 0)
        ->andReturn(true);

    // Execute command with confirmation auto-accepted
    $command = $this->artisan('flags:add-rule', [
        'name' => 'Test Rule',
        'pattern' => 'GET /api/test',
        '--project' => 'my-project',
        '--environment' => 'production',
    ]);

    $command->expectsConfirmation('Do you want to continue?', 'yes');

    // Assertions
    $command->assertExitCode(0);
    $command->expectsOutput("Successfully added targeting rule 'Test Rule' to flag 'api-v6-rollout-endpoints'.");
});

test('flags:add-rule command validates percentages', function () {
    // Execute command with invalid percentages
    $command = $this->artisan('flags:add-rule', [
        'name' => 'Test Rule',
        'pattern' => 'GET /api/test',
        '--project' => 'my-project',
        '--environment' => 'production',
        '--v5-percentage' => 80,
        '--v6-percentage' => 30, // Total: 110%
    ]);

    // Assertions
    $command->assertExitCode(1);
    $command->expectsOutput('Percentages must sum to 100%. Current sum: 110%');
});

test('flags:add-rule command validates endpoint pattern format', function () {
    // Execute command with invalid pattern
    $command = $this->artisan('flags:add-rule', [
        'name' => 'Test Rule',
        'pattern' => 'invalid-pattern', // Missing HTTP method
        '--project' => 'my-project',
        '--environment' => 'production',
    ]);

    // Assertions
    $command->assertExitCode(1);
    $command->expectsOutput('Pattern must be in the format "HTTP_METHOD /path" (e.g. "GET /api/users")');
});

test('flags:add-rule command handles flag not found', function () {
    // Set up mock to return null (flag not found)
    $this->ldService
        ->shouldReceive('getFlag')
        ->with('my-project', 'api-v6-rollout-endpoints')
        ->andReturn(null);

    // Execute command
    $command = $this->artisan('flags:add-rule', [
        'name' => 'Test Rule',
        'pattern' => 'GET /api/test',
        '--project' => 'my-project',
        '--environment' => 'production',
        '--force'
    ]);

    // Assertions
    $command->assertExitCode(1);
    $command->expectsConfirmation('Do you want to continue?', 'yes');
    $command->expectsOutput("Flag 'api-v6-rollout-endpoints' not found in project 'my-project'.");
});

test('flags:add-rule command handles environment not found', function () {
    // Mock flag data without the requested environment
    $mockFlag = [
        'key' => 'api-v6-rollout-endpoints',
        'variations' => [
            ['value' => 'v5'],
            ['value' => 'v6']
        ],
        'environments' => [
            'production' => [
                'on' => true,
                'rules' => []
            ]
            // 'staging' environment not present
        ]
    ];

    // Set up mock
    $this->ldService
        ->shouldReceive('getFlag')
        ->once()
        ->with('my-project', 'api-v6-rollout-endpoints')
        ->andReturn($mockFlag);

    // Execute command
    $command = $this->artisan('flags:add-rule', [
        'name' => 'Test Rule',
        'pattern' => 'GET /api/test',
        '--project' => 'my-project',
        '--environment' => 'staging', // Requesting non-existent environment
    ]);

    // Assertions
    $command->assertExitCode(1);
    $command->expectsConfirmation('Do you want to continue?', 'yes');
    $command->expectsOutput("Environment 'staging' not found for flag 'api-v6-rollout-endpoints'.");
});

test('flags:add-rule command handles rule creation failure', function () {
    // Mock flag data
    $mockFlag = [
        'key' => 'api-v6-rollout-endpoints',
        'variations' => [
            ['value' => 'v5'],
            ['value' => 'v6']
        ],
        'environments' => [
            'production' => [
                'on' => true,
                'rules' => []
            ]
        ]
    ];

    // Mock rule
    $mockRule = [
        'description' => 'Test Rule',
        'clauses' => [['attribute' => 'endpoint_pattern']]
    ];

    // Set up mocks
    $this->ldService
        ->shouldReceive('getFlag')
        ->once()
        ->with('my-project', 'api-v6-rollout-endpoints')
        ->andReturn($mockFlag);

    $this->ldService
        ->shouldReceive('createEndpointMatchingRule')
        ->once()
        ->andReturn($mockRule);

    $this->ldService
        ->shouldReceive('addTargetingRule')
        ->once()
        ->with('my-project', 'api-v6-rollout-endpoints', 'production', Mockery::any(), Mockery::any(), Mockery::any())
        ->andReturn(false);

    // Execute command with confirmation auto-accepted
    $command = $this->artisan('flags:add-rule', [
        'name' => 'Test Rule',
        'pattern' => 'GET /api/test',
        '--project' => 'my-project',
        '--environment' => 'production',
    ]);

    // Assertions
    $command->assertExitCode(1);
    $command->expectsConfirmation('Do you want to continue?', 'yes');
    $command->expectsOutputToContain('Failed to add targeting rule');
});

test('flags:add-rule command handles operation cancellation', function () {
    // Mock flag data
    $mockFlag = [
        'key' => 'api-v6-rollout-endpoints',
        'variations' => [
            ['value' => 'v5'],
            ['value' => 'v6']
        ],
        'environments' => [
            'production' => [
                'on' => true,
                'rules' => []
            ]
        ]
    ];

    // Set up mock
    $this->ldService
        ->shouldReceive('getFlag')
        ->with('my-project', 'api-v6-rollout-endpoints')
        ->andReturn($mockFlag);

    // Execute command with confirmation rejected
    $command = $this->artisan('flags:add-rule', [
        'name' => 'Test Rule',
        'pattern' => 'GET /api/test',
        '--project' => 'my-project',
        '--environment' => 'production',
    ]);

    // Assertions
    $command->assertExitCode(0);
    $command->expectsConfirmation('Do you want to continue?', 'no');
    $command->expectsOutput('Operation cancelled.');
});

test('flags:add-rule command with custom flag parameter works correctly', function () {
    // Mock flag data for custom flag
    $mockFlag = [
        'key' => 'custom-flag',
        'variations' => [
            [
                '_id' => 'var-id-1',
                'value' => 'v5',
                'name' => 'Old Version'
            ],
            [
                '_id' => 'var-id-2',
                'value' => 'v6',
                'name' => 'New Version'
            ]
        ],
        'environments' => [
            'production' => [
                'on' => true,
                'rules' => []
            ]
        ]
    ];

    // Mock rule
    $mockRule = [
        'description' => 'Test Rule',
        'clauses' => [['attribute' => 'endpoint_pattern']]
    ];

    // Set up mocks
    $this->ldService
        ->shouldReceive('getFlag')
        ->once()
        ->with('my-project', 'custom-flag')
        ->andReturn($mockFlag);

    $this->ldService
        ->shouldReceive('createEndpointMatchingRule')
        ->once()
        ->andReturn($mockRule);

    $this->ldService
        ->shouldReceive('addTargetingRule')
        ->once()
        ->with('my-project', 'custom-flag', 'production', $mockRule, null, 0)
        ->andReturn(true);

    // Execute command with custom flag parameter
    $command = $this->artisan('flags:add-rule', [
        'name' => 'Test Rule',
        'pattern' => 'GET /api/test',
        '--project' => 'my-project',
        '--environment' => 'production',
        '--flag' => 'custom-flag'
    ]);

    $command->expectsConfirmation('Do you want to continue?', 'yes');

    // Assertions
    $command->assertExitCode(0);
    $command->expectsOutput("Successfully added targeting rule 'Test Rule' to flag 'custom-flag'.");
});

test('flags:add-rule command with percentage rollout works correctly', function () {
    // Mock flag data
    $mockFlag = [
        'key' => 'api-v6-rollout-endpoints',
        'variations' => [
            ['value' => 'v5'],
            ['value' => 'v6']
        ],
        'environments' => [
            'production' => [
                'on' => true,
                'rules' => []
            ]
        ]
    ];

    // Mock rule with specific percentages
    $mockRule = [
        'description' => 'Split Traffic Rule',
        'clauses' => [['attribute' => 'endpoint_pattern']],
        'rollout' => [
            'variations' => [
                ['variation' => 0, 'weight' => 70000],
                ['variation' => 1, 'weight' => 30000]
            ]
        ]
    ];

    // Set up mocks
    $this->ldService
        ->shouldReceive('getFlag')
        ->once()
        ->with('my-project', 'api-v6-rollout-endpoints')
        ->andReturn($mockFlag);

    $this->ldService
        ->shouldReceive('createEndpointMatchingRule')
        ->once()
        ->with('Split Traffic Rule', 'GET /api/test', [70, 30], 'key', 'request', true, Mockery::any())
        ->andReturn($mockRule);

    $this->ldService
        ->shouldReceive('addTargetingRule')
        ->once()
        ->andReturn(true);

    // Execute command with specific percentages
    $command = $this->artisan('flags:add-rule', [
        'name' => 'Split Traffic Rule',
        'pattern' => 'GET /api/test',
        '--project' => 'my-project',
        '--environment' => 'production',
        '--v5-percentage' => 70,
        '--v6-percentage' => 30
    ]);

    $command->expectsConfirmation('Do you want to continue?', 'yes');

    // Assertions
    $command->assertExitCode(0);
    $command->expectsOutput("Traffic split: 70% to v5, 30% to v6");
});