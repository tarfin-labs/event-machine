<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Routing\EndpointDefinition;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Routing\ForwardedEndpointDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\TestStartEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\TestNoEndpointMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\AbortEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\ProvideCardEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\PaymentStepResult;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\ConfirmPaymentEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\ForwardEndpointAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\FqcnForwardParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\RenameForwardParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\ForwardChildEndpointMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\ForwardParentEndpointMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\FullConfigForwardParentMachine;

// ═══════════════════════════════════════════════════════════════
//  Forward format parsing — Format 1 (plain)
// ═══════════════════════════════════════════════════════════════

test('it parses plain string forward as ForwardedEndpointDefinition', function (): void {
    $definition = ForwardParentEndpointMachine::definition();

    expect($definition->forwardedEndpoints)->toHaveKey('PROVIDE_CARD');

    $fwd = $definition->forwardedEndpoints['PROVIDE_CARD'];

    expect($fwd)->toBeInstanceOf(ForwardedEndpointDefinition::class)
        ->and($fwd->parentEventType)->toBe('PROVIDE_CARD')
        ->and($fwd->childEventType)->toBe('PROVIDE_CARD')
        ->and($fwd->childMachineClass)->toBe(ForwardChildEndpointMachine::class)
        ->and($fwd->childEventClass)->toBe(ProvideCardEvent::class)
        ->and($fwd->method)->toBe('POST')
        ->and($fwd->actionClass)->toBeNull()
        ->and($fwd->output)->toBeNull()
        ->and($fwd->statusCode)->toBeNull()
        ->and($fwd->middleware)->toBe([])
        ->and($fwd->availableEvents)->toBeNull();
});

test('it parses plain FQCN forward as ForwardedEndpointDefinition (resolves via getType)', function (): void {
    $definition = FqcnForwardParentMachine::definition();

    // ProvideCardEvent::class is resolved to 'PROVIDE_CARD' during normalization
    expect($definition->forwardedEndpoints)->toHaveKey('PROVIDE_CARD');

    $fwd = $definition->forwardedEndpoints['PROVIDE_CARD'];

    expect($fwd)->toBeInstanceOf(ForwardedEndpointDefinition::class)
        ->and($fwd->parentEventType)->toBe('PROVIDE_CARD')
        ->and($fwd->childEventType)->toBe('PROVIDE_CARD')
        ->and($fwd->childMachineClass)->toBe(ForwardChildEndpointMachine::class)
        ->and($fwd->childEventClass)->toBe(ProvideCardEvent::class);
});

// ═══════════════════════════════════════════════════════════════
//  Forward format parsing — Format 2 (rename)
// ═══════════════════════════════════════════════════════════════

test('it parses rename string forward as ForwardedEndpointDefinition', function (): void {
    $definition = RenameForwardParentMachine::definition();

    expect($definition->forwardedEndpoints)->toHaveKey('CANCEL_ORDER');

    $fwd = $definition->forwardedEndpoints['CANCEL_ORDER'];

    expect($fwd)->toBeInstanceOf(ForwardedEndpointDefinition::class)
        ->and($fwd->parentEventType)->toBe('CANCEL_ORDER')
        ->and($fwd->childEventType)->toBe('ABORT')
        ->and($fwd->childMachineClass)->toBe(ForwardChildEndpointMachine::class)
        ->and($fwd->childEventClass)->toBe(AbortEvent::class)
        ->and($fwd->method)->toBe('POST')
        ->and($fwd->actionClass)->toBeNull()
        ->and($fwd->output)->toBeNull()
        ->and($fwd->statusCode)->toBeNull()
        ->and($fwd->middleware)->toBe([]);
});

test('it parses rename FQCN key forward as ForwardedEndpointDefinition', function (): void {
    // FqcnForwardParentMachine has: ConfirmPaymentEvent::class => AbortEvent::class
    // Resolved to: 'CONFIRM_PAYMENT' => 'ABORT'
    $definition = FqcnForwardParentMachine::definition();

    expect($definition->forwardedEndpoints)->toHaveKey('CONFIRM_PAYMENT');

    $fwd = $definition->forwardedEndpoints['CONFIRM_PAYMENT'];

    expect($fwd)->toBeInstanceOf(ForwardedEndpointDefinition::class)
        ->and($fwd->parentEventType)->toBe('CONFIRM_PAYMENT')
        ->and($fwd->childEventType)->toBe('ABORT')
        ->and($fwd->childMachineClass)->toBe(ForwardChildEndpointMachine::class)
        ->and($fwd->childEventClass)->toBe(AbortEvent::class);
});

test('it parses rename FQCN both sides as ForwardedEndpointDefinition', function (): void {
    // Build inline definition with FQCN on both key and value sides
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'fqcn_both_rename',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['START' => 'processing'],
                ],
                'processing' => [
                    'machine' => ForwardChildEndpointMachine::class,
                    'queue'   => 'default',
                    'forward' => [
                        ConfirmPaymentEvent::class => AbortEvent::class,
                    ],
                    '@done' => 'completed',
                    '@fail' => 'failed',
                ],
                'completed' => ['type' => 'final'],
                'failed'    => ['type' => 'final'],
            ],
        ],
        behavior: [
            'events' => [
                'START' => TestStartEvent::class,
            ],
        ],
    );

    expect($definition->forwardedEndpoints)->toHaveKey('CONFIRM_PAYMENT');

    $fwd = $definition->forwardedEndpoints['CONFIRM_PAYMENT'];

    expect($fwd->parentEventType)->toBe('CONFIRM_PAYMENT')
        ->and($fwd->childEventType)->toBe('ABORT')
        ->and($fwd->childEventClass)->toBe(AbortEvent::class);
});

// ═══════════════════════════════════════════════════════════════
//  Forward format parsing — Format 3 (full config)
// ═══════════════════════════════════════════════════════════════

test('it parses full array forward with result (now output)', function (): void {
    $definition = ForwardParentEndpointMachine::definition();

    expect($definition->forwardedEndpoints)->toHaveKey('CONFIRM_PAYMENT');

    $fwd = $definition->forwardedEndpoints['CONFIRM_PAYMENT'];

    // In v9, forward config 'result' is resolved as 'output' (class reference takes priority)
    expect($fwd)->toBeInstanceOf(ForwardedEndpointDefinition::class)
        ->and($fwd->parentEventType)->toBe('CONFIRM_PAYMENT')
        ->and($fwd->childEventType)->toBe('CONFIRM_PAYMENT')
        ->and($fwd->output)->toBe(PaymentStepResult::class)
        ->and($fwd->statusCode)->toBe(200);
});

test('it parses full array forward with uri override', function (): void {
    $definition = FullConfigForwardParentMachine::definition();

    $fwd = $definition->forwardedEndpoints['PROVIDE_CARD'];

    expect($fwd->uri)->toBe('/enter-payment-details');
});

test('it parses full array forward with method override', function (): void {
    $definition = FullConfigForwardParentMachine::definition();

    $fwd = $definition->forwardedEndpoints['PROVIDE_CARD'];

    expect($fwd->method)->toBe('PATCH');
});

test('it parses full array forward with middleware array', function (): void {
    $definition = FullConfigForwardParentMachine::definition();

    $fwd = $definition->forwardedEndpoints['PROVIDE_CARD'];

    expect($fwd->middleware)->toBe(['throttle:10']);
});

test('it parses full array forward with action class', function (): void {
    $definition = FullConfigForwardParentMachine::definition();

    $fwd = $definition->forwardedEndpoints['PROVIDE_CARD'];

    expect($fwd->actionClass)->toBe(ForwardEndpointAction::class);
});

test('it parses full array forward with status code', function (): void {
    $definition = FullConfigForwardParentMachine::definition();

    $fwd = $definition->forwardedEndpoints['PROVIDE_CARD'];

    expect($fwd->statusCode)->toBe(202);
});

test('it parses full array forward with ALL keys simultaneously', function (): void {
    $definition = FullConfigForwardParentMachine::definition();

    expect($definition->forwardedEndpoints)->toHaveKey('PROVIDE_CARD');

    $fwd = $definition->forwardedEndpoints['PROVIDE_CARD'];

    expect($fwd)->toBeInstanceOf(ForwardedEndpointDefinition::class)
        ->and($fwd->parentEventType)->toBe('PROVIDE_CARD')
        ->and($fwd->childEventType)->toBe('PROVIDE_CARD')
        ->and($fwd->childMachineClass)->toBe(ForwardChildEndpointMachine::class)
        ->and($fwd->childEventClass)->toBe(ProvideCardEvent::class)
        ->and($fwd->uri)->toBe('/enter-payment-details')
        ->and($fwd->method)->toBe('PATCH')
        ->and($fwd->middleware)->toBe(['throttle:10'])
        ->and($fwd->actionClass)->toBe(ForwardEndpointAction::class)
        ->and($fwd->output)->toBe(PaymentStepResult::class)
        ->and($fwd->statusCode)->toBe(202)
        ->and($fwd->availableEvents)->toBeFalse();
});

test('it parses full array forward with FQCN key', function (): void {
    // FullConfigForwardParentMachine uses ProvideCardEvent::class as key
    $definition = FullConfigForwardParentMachine::definition();

    // FQCN is resolved to 'PROVIDE_CARD' during normalization
    expect($definition->forwardedEndpoints)->toHaveKey('PROVIDE_CARD')
        ->and($definition->forwardedEndpoints)->not->toHaveKey(ProvideCardEvent::class);
});

test('it parses full array forward with FQCN child_event value', function (): void {
    // Inline definition with FQCN in child_event
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'fqcn_child_event',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['START' => 'processing'],
                ],
                'processing' => [
                    'machine' => ForwardChildEndpointMachine::class,
                    'queue'   => 'default',
                    'forward' => [
                        'PARENT_CARD' => [
                            'child_event' => ProvideCardEvent::class,
                        ],
                    ],
                    '@done' => 'completed',
                    '@fail' => 'failed',
                ],
                'completed' => ['type' => 'final'],
                'failed'    => ['type' => 'final'],
            ],
        ],
        behavior: [
            'events' => [
                'START' => TestStartEvent::class,
            ],
        ],
    );

    expect($definition->forwardedEndpoints)->toHaveKey('PARENT_CARD');

    $fwd = $definition->forwardedEndpoints['PARENT_CARD'];

    expect($fwd->childEventType)->toBe('PROVIDE_CARD')
        ->and($fwd->childEventClass)->toBe(ProvideCardEvent::class);
});

// ═══════════════════════════════════════════════════════════════
//  Mixed formats
// ═══════════════════════════════════════════════════════════════

test('it parses mixed forward formats in same array (strings and FQCNs)', function (): void {
    // FqcnForwardParentMachine has: [ProvideCardEvent::class, ConfirmPaymentEvent::class => AbortEvent::class]
    $definition = FqcnForwardParentMachine::definition();

    expect($definition->forwardedEndpoints)
        ->toHaveKey('PROVIDE_CARD')
        ->toHaveKey('CONFIRM_PAYMENT')
        ->toHaveCount(2);

    // Format 1 (plain FQCN resolved)
    $fwd1 = $definition->forwardedEndpoints['PROVIDE_CARD'];
    expect($fwd1->parentEventType)->toBe('PROVIDE_CARD')
        ->and($fwd1->childEventType)->toBe('PROVIDE_CARD');

    // Format 2 (rename FQCN resolved)
    $fwd2 = $definition->forwardedEndpoints['CONFIRM_PAYMENT'];
    expect($fwd2->parentEventType)->toBe('CONFIRM_PAYMENT')
        ->and($fwd2->childEventType)->toBe('ABORT');
});

test('it parses Format 1 + Format 2 + Format 3 in same forward array', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'mixed_all_formats',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['START' => 'processing'],
                ],
                'processing' => [
                    'machine' => ForwardChildEndpointMachine::class,
                    'queue'   => 'default',
                    'forward' => [
                        'PROVIDE_CARD',                                       // Format 1: plain
                        'CANCEL_ORDER'    => 'ABORT',                            // Format 2: rename
                        'CONFIRM_PAYMENT' => [                                // Format 3: full config
                            'result'      => PaymentStepResult::class,
                            'contextKeys' => ['cardLast4'],
                            'status'      => 201,
                        ],
                    ],
                    '@done' => 'completed',
                    '@fail' => 'failed',
                ],
                'completed' => ['type' => 'final'],
                'failed'    => ['type' => 'final'],
            ],
        ],
        behavior: [
            'events' => [
                'START' => TestStartEvent::class,
            ],
        ],
    );

    expect($definition->forwardedEndpoints)
        ->toHaveCount(3)
        ->toHaveKey('PROVIDE_CARD')
        ->toHaveKey('CANCEL_ORDER')
        ->toHaveKey('CONFIRM_PAYMENT');

    // Format 1
    $plain = $definition->forwardedEndpoints['PROVIDE_CARD'];
    expect($plain->parentEventType)->toBe('PROVIDE_CARD')
        ->and($plain->childEventType)->toBe('PROVIDE_CARD')
        ->and($plain->output)->toBeNull();

    // Format 2
    $rename = $definition->forwardedEndpoints['CANCEL_ORDER'];
    expect($rename->parentEventType)->toBe('CANCEL_ORDER')
        ->and($rename->childEventType)->toBe('ABORT');

    // Format 3
    $full = $definition->forwardedEndpoints['CONFIRM_PAYMENT'];
    expect($full->parentEventType)->toBe('CONFIRM_PAYMENT')
        ->and($full->childEventType)->toBe('CONFIRM_PAYMENT')
        ->and($full->output)->toBe(PaymentStepResult::class)
        ->and($full->statusCode)->toBe(201);
});

// ═══════════════════════════════════════════════════════════════
//  Child event discovery
// ═══════════════════════════════════════════════════════════════

test('it resolves child EventBehavior class from child definition', function (): void {
    $definition = ForwardParentEndpointMachine::definition();

    $fwd = $definition->forwardedEndpoints['PROVIDE_CARD'];

    expect($fwd->childEventClass)->toBe(ProvideCardEvent::class)
        ->and($fwd->childMachineClass)->toBe(ForwardChildEndpointMachine::class);
});

test('it generates URI from parent event type (PROVIDE_CARD -> /provide-card)', function (): void {
    $definition = ForwardParentEndpointMachine::definition();

    $fwd = $definition->forwardedEndpoints['PROVIDE_CARD'];

    expect($fwd->uri)->toBe('/provide-card')
        ->and($fwd->uri)->toBe(EndpointDefinition::generateUri('PROVIDE_CARD'));
});

test('it generates URI from FQCN-resolved event type', function (): void {
    $definition = FqcnForwardParentMachine::definition();

    // ProvideCardEvent::class resolves to 'PROVIDE_CARD', URI generated from that
    $fwd = $definition->forwardedEndpoints['PROVIDE_CARD'];

    expect($fwd->uri)->toBe('/provide-card');
});

test('uri override in Format 3 takes precedence over auto-generated URI', function (): void {
    $definition = FullConfigForwardParentMachine::definition();

    $fwd = $definition->forwardedEndpoints['PROVIDE_CARD'];

    // Auto-generated would be '/provide-card', but override is '/enter-payment-details'
    expect($fwd->uri)->toBe('/enter-payment-details')
        ->and($fwd->uri)->not->toBe(EndpointDefinition::generateUri('PROVIDE_CARD'));
});

// ═══════════════════════════════════════════════════════════════
//  Validation errors (inline definitions with broken configs)
// ═══════════════════════════════════════════════════════════════

test('it throws when forward event name collides with parent event', function (): void {
    // Forward PROVIDE_CARD while parent also has PROVIDE_CARD in behavior.events
    expect(function (): void {
        MachineDefinition::define(
            config: [
                'id'      => 'test_parent_event_collision',
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle' => [
                        'on' => ['START' => 'processing'],
                    ],
                    'processing' => [
                        'machine' => ForwardChildEndpointMachine::class,
                        'queue'   => 'default',
                        'forward' => ['PROVIDE_CARD'],
                        '@done'   => 'completed',
                    ],
                    'completed' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'events' => [
                    'START'        => TestStartEvent::class,
                    'PROVIDE_CARD' => ProvideCardEvent::class,
                ],
            ],
        );
    })->toThrow(InvalidArgumentException::class, 'behavior.events');
});

test('it throws when same forward event name used in two different delegating states', function (): void {
    // Two different states both forward 'PROVIDE_CARD'
    expect(function (): void {
        MachineDefinition::define(
            config: [
                'id'      => 'test_duplicate_forward',
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle' => [
                        'on' => ['START' => 'step_one'],
                    ],
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
                'events' => [
                    'START' => TestStartEvent::class,
                ],
            ],
        );
    })->toThrow(InvalidArgumentException::class, 'multiple delegating states');
});

// ═══════════════════════════════════════════════════════════════
//  Overlap rejection
// ═══════════════════════════════════════════════════════════════

test('it throws when forward event also declared in parent endpoints', function (): void {
    // PROVIDE_CARD must be in parent's on transitions and behavior.events for endpoint parsing to succeed.
    // Then parseForwardedEndpoints detects the overlap with parsedEndpoints.
    expect(function (): void {
        MachineDefinition::define(
            config: [
                'id'      => 'test_endpoint_overlap',
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
                        '@done'   => 'completed',
                    ],
                    'completed' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'events' => [
                    'START'        => TestStartEvent::class,
                    'PROVIDE_CARD' => ProvideCardEvent::class,
                ],
            ],
            endpoints: [
                'START',
                'PROVIDE_CARD',
            ],
        );
    })->toThrow(InvalidArgumentException::class, 'endpoints');
});

test('it throws when forward event also declared in parent behavior.events', function (): void {
    expect(function (): void {
        MachineDefinition::define(
            config: [
                'id'      => 'test_behavior_event_overlap',
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'START'           => 'processing',
                            'CONFIRM_PAYMENT' => 'processing',
                        ],
                    ],
                    'processing' => [
                        'machine' => ForwardChildEndpointMachine::class,
                        'queue'   => 'default',
                        'forward' => ['CONFIRM_PAYMENT'],
                        '@done'   => 'completed',
                    ],
                    'completed' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'events' => [
                    'START'           => TestStartEvent::class,
                    'CONFIRM_PAYMENT' => ConfirmPaymentEvent::class,
                ],
            ],
        );
    })->toThrow(InvalidArgumentException::class, 'behavior.events');
});

// ═══════════════════════════════════════════════════════════════
//  Existing format compatibility
// ═══════════════════════════════════════════════════════════════

test('it existing forward behavior unchanged for Format 1 and Format 2', function (): void {
    // ForwardParentEndpointMachine: Format 1 (PROVIDE_CARD) + Format 3 (CONFIRM_PAYMENT)
    $definition = ForwardParentEndpointMachine::definition();

    expect($definition->forwardedEndpoints)->toHaveCount(2);

    // Format 1 plain
    $provideCard = $definition->forwardedEndpoints['PROVIDE_CARD'];
    expect($provideCard->parentEventType)->toBe('PROVIDE_CARD')
        ->and($provideCard->childEventType)->toBe('PROVIDE_CARD');

    // Format 3 with result (now output)
    $confirmPayment = $definition->forwardedEndpoints['CONFIRM_PAYMENT'];
    expect($confirmPayment->parentEventType)->toBe('CONFIRM_PAYMENT')
        ->and($confirmPayment->childEventType)->toBe('CONFIRM_PAYMENT')
        ->and($confirmPayment->output)->toBe(PaymentStepResult::class)
        ->and($confirmPayment->statusCode)->toBe(200);
});

test('FQCN forward entries match resolveForwardEvent with resolved type string', function (): void {
    $definition = FqcnForwardParentMachine::definition();

    // Get the invoke definition to call resolveForwardEvent
    $processingState  = $definition->idMap['fqcn_forward_parent.processing'];
    $invokeDefinition = $processingState->getMachineInvokeDefinition();

    // ProvideCardEvent::class was resolved to 'PROVIDE_CARD' during normalization
    expect($invokeDefinition->resolveForwardEvent('PROVIDE_CARD'))->toBe('PROVIDE_CARD');

    // ConfirmPaymentEvent::class => AbortEvent::class resolved to 'CONFIRM_PAYMENT' => 'ABORT'
    expect($invokeDefinition->resolveForwardEvent('CONFIRM_PAYMENT'))->toBe('ABORT');

    // Unforwarded event returns null
    expect($invokeDefinition->resolveForwardEvent('UNKNOWN_EVENT'))->toBeNull();
});

test('machine without forward config has empty forwardedEndpoints', function (): void {
    $definition = TestNoEndpointMachine::definition();

    expect($definition->forwardedEndpoints)->toBe([]);
});
