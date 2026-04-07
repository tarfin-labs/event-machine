<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Testing\TestMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Outputs\ParallelSummaryOutput;

it('OutputBehavior on parallel state resolves correctly via $machine->output()', function (): void {
    $test = TestMachine::define([
        'initial' => 'idle',
        'context' => [
            'regionAData' => null,
            'regionBData' => null,
        ],
        'states' => [
            'idle'       => ['on' => ['START' => 'processing']],
            'processing' => [
                'type'   => 'parallel',
                'output' => ParallelSummaryOutput::class,
                '@done'  => 'completed',
                'states' => [
                    'region_a' => [
                        'initial' => 'working',
                        'states'  => [
                            'working' => ['on' => ['A_DONE' => 'done']],
                            'done'    => ['type' => 'final'],
                        ],
                    ],
                    'region_b' => [
                        'initial' => 'working',
                        'states'  => [
                            'working' => ['on' => ['B_DONE' => 'done']],
                            'done'    => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'completed' => ['type' => 'final'],
        ],
    ]);

    $test->send('START');

    // Machine is now in parallel state — output should come from ParallelSummaryOutput
    $output = $test->machine()->output();

    expect($output)->toBeArray()
        ->and($output['combinedStatus'])->toBe('in_progress')
        ->and($output['regionAData'])->toBeNull()
        ->and($output['regionBData'])->toBeNull();
});

it('parallel state output composes data from all regions after actions', function (): void {
    $test = TestMachine::define([
        'initial' => 'idle',
        'context' => [
            'regionAData' => null,
            'regionBData' => null,
        ],
        'states' => [
            'idle'       => ['on' => ['START' => 'processing']],
            'processing' => [
                'type'   => 'parallel',
                'output' => ParallelSummaryOutput::class,
                '@done'  => 'completed',
                'states' => [
                    'region_a' => [
                        'initial' => 'working',
                        'states'  => [
                            'working' => [
                                'on' => [
                                    'A_DONE' => [
                                        'target'  => 'done',
                                        'actions' => 'setRegionAAction',
                                    ],
                                ],
                            ],
                            'done' => ['type' => 'final'],
                        ],
                    ],
                    'region_b' => [
                        'initial' => 'working',
                        'states'  => [
                            'working' => [
                                'on' => [
                                    'B_DONE' => [
                                        'target'  => 'done',
                                        'actions' => 'setRegionBAction',
                                    ],
                                ],
                            ],
                            'done' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'completed' => ['type' => 'final'],
        ],
    ], behavior: [
        'actions' => [
            'setRegionAAction' => fn (ContextManager $ctx) => $ctx->set('regionAData', 'a_processed'),
            'setRegionBAction' => fn (ContextManager $ctx) => $ctx->set('regionBData', 'b_processed'),
        ],
    ]);

    $test->send('START');
    $test->send('A_DONE');

    // After region A completes, output reflects partial state
    $output = $test->machine()->output();
    expect($output['regionAData'])->toBe('a_processed')
        ->and($output['regionBData'])->toBeNull()
        ->and($output['combinedStatus'])->toBe('in_progress');

    $test->send('B_DONE');

    // After both regions complete, @done fires → machine moves to 'completed'
    // Now output comes from 'completed' state (no output defined → toResponseArray fallback)
    expect($test->machine()->state->currentStateDefinition->key)->toBe('completed');
});

it('output array filter on parallel state filters context from all regions', function (): void {
    $test = TestMachine::define([
        'initial' => 'idle',
        'context' => [
            'regionAData'  => null,
            'regionBData'  => null,
            'internalFlag' => true,
        ],
        'states' => [
            'idle'       => ['on' => ['START' => 'processing']],
            'processing' => [
                'type'   => 'parallel',
                'output' => ['regionAData', 'regionBData'],
                '@done'  => 'completed',
                'states' => [
                    'region_a' => [
                        'initial' => 'working',
                        'states'  => [
                            'working' => ['on' => ['A_DONE' => 'done']],
                            'done'    => ['type' => 'final'],
                        ],
                    ],
                    'region_b' => [
                        'initial' => 'working',
                        'states'  => [
                            'working' => ['on' => ['B_DONE' => 'done']],
                            'done'    => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'completed' => ['type' => 'final'],
        ],
    ]);

    $test->send('START');

    // Array filter — should only include regionAData and regionBData, NOT internalFlag
    $output = $test->machine()->output();
    expect($output)->toHaveKey('regionAData')
        ->and($output)->toHaveKey('regionBData')
        ->and($output)->not->toHaveKey('internalFlag');
});
