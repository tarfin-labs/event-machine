<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\TestStartEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\ProvideCardEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\FqcnForwardParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\ForwardChildEndpointMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\ForwardParentEndpointMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\FullConfigForwardParentMachine;

// ═══════════════════════════════════════════════════════════════
//  StateConfigValidator — Format 3 key validation
// ═══════════════════════════════════════════════════════════════

test('it rejects forward array config with unknown keys', function (): void {
    expect(function (): void {
        MachineDefinition::define(
            config: [
                'id'      => 'val_unknown_keys',
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle'       => ['on' => ['START' => 'processing']],
                    'processing' => [
                        'machine' => ForwardChildEndpointMachine::class,
                        'queue'   => 'default',
                        'forward' => [
                            'PROVIDE_CARD' => [
                                'child_event' => 'PROVIDE_CARD',
                                'unknown_key' => 'value',
                            ],
                        ],
                        '@done' => 'done',
                    ],
                    'done' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'context' => GenericContext::class,
                'events'  => ['START' => TestStartEvent::class],
            ],
        );
    })->toThrow(InvalidArgumentException::class, 'unknown keys');
});

test('it rejects forward with uri not starting with slash', function (): void {
    expect(function (): void {
        MachineDefinition::define(
            config: [
                'id'      => 'val_bad_uri',
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle'       => ['on' => ['START' => 'processing']],
                    'processing' => [
                        'machine' => ForwardChildEndpointMachine::class,
                        'queue'   => 'default',
                        'forward' => [
                            'PROVIDE_CARD' => [
                                'uri' => 'no-leading-slash',
                            ],
                        ],
                        '@done' => 'done',
                    ],
                    'done' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'context' => GenericContext::class,
                'events'  => ['START' => TestStartEvent::class],
            ],
        );
    })->toThrow(InvalidArgumentException::class, "uri must start with '/'");
});

// ═══════════════════════════════════════════════════════════════
//  StateConfigValidator — forward requires queue
// ═══════════════════════════════════════════════════════════════

test('it rejects forward without queue', function (): void {
    expect(function (): void {
        MachineDefinition::define(
            config: [
                'id'      => 'val_no_queue',
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle'       => ['on' => ['START' => 'processing']],
                    'processing' => [
                        'machine' => ForwardChildEndpointMachine::class,
                        'forward' => ['PROVIDE_CARD'],
                        '@done'   => 'done',
                    ],
                    'done' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'context' => GenericContext::class,
                'events'  => ['START' => TestStartEvent::class],
            ],
        );
    })->toThrow(InvalidArgumentException::class, 'forward');
});

// ═══════════════════════════════════════════════════════════════
//  StateConfigValidator — fire-and-forget + forward
// ═══════════════════════════════════════════════════════════════

test('it rejects forward with fire-and-forget (queue without @done)', function (): void {
    expect(function (): void {
        MachineDefinition::define(
            config: [
                'id'      => 'val_fire_and_forget',
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle'       => ['on' => ['START' => 'processing']],
                    'processing' => [
                        'machine' => ForwardChildEndpointMachine::class,
                        'queue'   => 'default',
                        'forward' => ['PROVIDE_CARD'],
                    ],
                ],
            ],
            behavior: [
                'context' => GenericContext::class,
                'events'  => ['START' => TestStartEvent::class],
            ],
        );
    })->toThrow(InvalidArgumentException::class, 'forward');
});

// ═══════════════════════════════════════════════════════════════
//  MachineDefinition — overlap rejection
// ═══════════════════════════════════════════════════════════════

test('it rejects forward event that also appears in parent endpoints', function (): void {
    expect(function (): void {
        MachineDefinition::define(
            config: [
                'id'      => 'overlap_endpoints',
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'START'        => 'processing',
                            'PROVIDE_CARD' => 'processing',
                        ],
                    ],
                    'processing' => [
                        'machine' => ForwardChildEndpointMachine::class,
                        'queue'   => 'default',
                        'forward' => ['PROVIDE_CARD'],
                        '@done'   => 'done',
                    ],
                    'done' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'context' => GenericContext::class,
                'events'  => [
                    'START'        => TestStartEvent::class,
                    'PROVIDE_CARD' => ProvideCardEvent::class,
                ],
            ],
            endpoints: ['START', 'PROVIDE_CARD'],
        );
    })->toThrow(InvalidArgumentException::class, 'endpoints');
});

test('it rejects forward event that also appears in parent behavior.events', function (): void {
    expect(function (): void {
        MachineDefinition::define(
            config: [
                'id'      => 'overlap_behavior',
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle'       => ['on' => ['START' => 'processing']],
                    'processing' => [
                        'machine' => ForwardChildEndpointMachine::class,
                        'queue'   => 'default',
                        'forward' => ['PROVIDE_CARD'],
                        '@done'   => 'done',
                    ],
                    'done' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'context' => GenericContext::class,
                'events'  => [
                    'START'        => TestStartEvent::class,
                    'PROVIDE_CARD' => ProvideCardEvent::class,
                ],
            ],
        );
    })->toThrow(InvalidArgumentException::class, 'behavior.events');
});

test('it rejects forward FQCN that resolves to same type as parent endpoint', function (): void {
    // ProvideCardEvent::class resolves to 'PROVIDE_CARD' which also appears in endpoints
    expect(function (): void {
        MachineDefinition::define(
            config: [
                'id'      => 'fqcn_overlap_endpoints',
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'START'        => 'processing',
                            'PROVIDE_CARD' => 'processing',
                        ],
                    ],
                    'processing' => [
                        'machine' => ForwardChildEndpointMachine::class,
                        'queue'   => 'default',
                        'forward' => [
                            ProvideCardEvent::class,
                        ],
                        '@done' => 'done',
                    ],
                    'done' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'context' => GenericContext::class,
                'events'  => [
                    'START'        => TestStartEvent::class,
                    'PROVIDE_CARD' => ProvideCardEvent::class,
                ],
            ],
            endpoints: ['START', 'PROVIDE_CARD'],
        );
    })->toThrow(InvalidArgumentException::class, 'endpoints');
});

test('it rejects same forward event in two delegating states', function (): void {
    expect(function (): void {
        MachineDefinition::define(
            config: [
                'id'      => 'duplicate_forward',
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle'     => ['on' => ['START' => 'step_one']],
                    'step_one' => [
                        'machine' => ForwardChildEndpointMachine::class,
                        'queue'   => 'default',
                        'forward' => ['PROVIDE_CARD'],
                        '@done'   => 'step_two',
                        '@fail'   => 'failed',
                    ],
                    'step_two' => [
                        'machine' => ForwardChildEndpointMachine::class,
                        'queue'   => 'default',
                        'forward' => ['PROVIDE_CARD'],
                        '@done'   => 'completed',
                        '@fail'   => 'failed',
                    ],
                    'completed' => ['type' => 'final'],
                    'failed'    => ['type' => 'final'],
                ],
            ],
            behavior: [
                'context' => GenericContext::class,
                'events'  => ['START' => TestStartEvent::class],
            ],
        );
    })->toThrow(InvalidArgumentException::class, 'multiple delegating states');
});

// ═══════════════════════════════════════════════════════════════
//  Error message quality
// ═══════════════════════════════════════════════════════════════

test('error message for endpoint overlap includes clear migration instructions', function (): void {
    try {
        MachineDefinition::define(
            config: [
                'id'      => 'migration_msg',
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'START'        => 'processing',
                            'PROVIDE_CARD' => 'processing',
                        ],
                    ],
                    'processing' => [
                        'machine' => ForwardChildEndpointMachine::class,
                        'queue'   => 'default',
                        'forward' => ['PROVIDE_CARD'],
                        '@done'   => 'done',
                    ],
                    'done' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'context' => GenericContext::class,
                'events'  => [
                    'START'        => TestStartEvent::class,
                    'PROVIDE_CARD' => ProvideCardEvent::class,
                ],
            ],
            endpoints: ['START', 'PROVIDE_CARD'],
        );

        $this->fail('Expected InvalidArgumentException was not thrown.');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())
            ->toContain('endpoints')
            ->toContain("Remove 'PROVIDE_CARD'")
            ->toContain('forward is the single source of truth');
    }
});

test('error message for behavior.events overlap includes clear removal instructions', function (): void {
    try {
        MachineDefinition::define(
            config: [
                'id'      => 'behavior_msg',
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle'       => ['on' => ['START' => 'processing']],
                    'processing' => [
                        'machine' => ForwardChildEndpointMachine::class,
                        'queue'   => 'default',
                        'forward' => ['PROVIDE_CARD'],
                        '@done'   => 'done',
                    ],
                    'done' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'context' => GenericContext::class,
                'events'  => [
                    'START'        => TestStartEvent::class,
                    'PROVIDE_CARD' => ProvideCardEvent::class,
                ],
            ],
        );

        $this->fail('Expected InvalidArgumentException was not thrown.');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())
            ->toContain('behavior.events')
            ->toContain("Remove 'PROVIDE_CARD'")
            ->toContain('forward auto-discovers child events');
    }
});

// ═══════════════════════════════════════════════════════════════
//  Happy-path acceptance tests
// ═══════════════════════════════════════════════════════════════

test('it accepts forward with valid result, contextKeys, and status', function (): void {
    // ForwardParentEndpointMachine has Format 3 with result/contextKeys/status on CONFIRM_PAYMENT
    $definition = ForwardParentEndpointMachine::definition();

    expect($definition->forwardedEndpoints)->toHaveKey('CONFIRM_PAYMENT')
        ->and($definition->forwardedEndpoints['CONFIRM_PAYMENT']->statusCode)->toBe(200);
});

test('it accepts forward with all Format 3 keys', function (): void {
    // FullConfigForwardParentMachine uses every allowed key in Format 3
    $definition = FullConfigForwardParentMachine::definition();

    expect($definition->forwardedEndpoints)->toHaveKey('PROVIDE_CARD');
});

test('it accepts mixed forward formats in same array', function (): void {
    // ForwardParentEndpointMachine mixes Format 1 (PROVIDE_CARD) and Format 3 (CONFIRM_PAYMENT)
    $definition = ForwardParentEndpointMachine::definition();

    expect($definition->forwardedEndpoints)
        ->toHaveKey('PROVIDE_CARD')
        ->toHaveKey('CONFIRM_PAYMENT')
        ->toHaveCount(2);
});

test('it accepts FQCN keys in all forward formats', function (): void {
    // FqcnForwardParentMachine uses FQCN in Format 1 and Format 2
    $definition = FqcnForwardParentMachine::definition();

    expect($definition->forwardedEndpoints)
        ->toHaveKey('PROVIDE_CARD')
        ->toHaveKey('CONFIRM_PAYMENT')
        ->toHaveCount(2);
});

test('it accepts forward event NOT in parent endpoints or behavior.events', function (): void {
    // Normal happy-path: forward events are disjoint from parent events
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'happy_path',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle'       => ['on' => ['START' => 'processing']],
                'processing' => [
                    'machine' => ForwardChildEndpointMachine::class,
                    'queue'   => 'default',
                    'forward' => ['PROVIDE_CARD', 'CONFIRM_PAYMENT'],
                    '@done'   => 'done',
                    '@fail'   => 'failed',
                ],
                'done'   => ['type' => 'final'],
                'failed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'context' => GenericContext::class,
            'events'  => ['START' => TestStartEvent::class],
        ],
        endpoints: ['START'],
    );

    expect($definition->forwardedEndpoints)
        ->toHaveKey('PROVIDE_CARD')
        ->toHaveKey('CONFIRM_PAYMENT')
        ->toHaveCount(2);
});
