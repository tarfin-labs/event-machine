<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Context;
use Illuminate\Support\Facades\Queue;
use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Models\MachineChild;
use Tarfinlabs\EventMachine\Testing\TestMachine;
use Tarfinlabs\EventMachine\Routing\ForwardContext;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Routing\ForwardedEndpointDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\TestStartEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\AbortEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\ProvideCardEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\PaymentStepResult;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\ForwardEndpointAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\RenameForwardParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\ForwardChildEndpointMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\ForwardParentEndpointMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\FullConfigForwardParentMachine;

// ═══════════════════════════════════════════════════════════════
//  Multiple Delegating States — Different Forward Events
// ═══════════════════════════════════════════════════════════════

test('different forward events from different states do not conflict', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'multi_fwd',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['GO_A' => 'state_a', 'GO_B' => 'state_b'],
                ],
                'state_a' => [
                    'machine' => ForwardChildEndpointMachine::class,
                    'queue'   => 'default',
                    'forward' => ['PROVIDE_CARD'],
                    '@done'   => 'done',
                ],
                'state_b' => [
                    'machine' => ForwardChildEndpointMachine::class,
                    'queue'   => 'default',
                    'forward' => ['CONFIRM_PAYMENT'],
                    '@done'   => 'done',
                ],
                'done' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'events' => [
                'GO_A' => TestStartEvent::class,
                'GO_B' => TestStartEvent::class,
            ],
        ],
    );

    expect($definition->forwardedEndpoints)->toHaveCount(2)
        ->and($definition->forwardedEndpoints)->toHaveKeys(['PROVIDE_CARD', 'CONFIRM_PAYMENT']);
});

test('each forward endpoint in multiple delegating states maps to correct child event class', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'multi_fwd_cls',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['GO_A' => 'state_a', 'GO_B' => 'state_b'],
                ],
                'state_a' => [
                    'machine' => ForwardChildEndpointMachine::class,
                    'queue'   => 'default',
                    'forward' => ['PROVIDE_CARD'],
                    '@done'   => 'done',
                ],
                'state_b' => [
                    'machine' => ForwardChildEndpointMachine::class,
                    'queue'   => 'default',
                    'forward' => ['ABORT'],
                    '@done'   => 'done',
                ],
                'done' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'events' => [
                'GO_A' => TestStartEvent::class,
                'GO_B' => TestStartEvent::class,
            ],
        ],
    );

    $provideCard = $definition->forwardedEndpoints['PROVIDE_CARD'];
    $abort       = $definition->forwardedEndpoints['ABORT'];

    expect($provideCard->childEventClass)->toBe(ProvideCardEvent::class)
        ->and($abort->childEventClass)->toBe(AbortEvent::class);
});

// ═══════════════════════════════════════════════════════════════
//  Overlap Rejection
// ═══════════════════════════════════════════════════════════════

test('overlap rejection throws when forward event collides with parent endpoints', function (): void {
    expect(function (): void {
        MachineDefinition::define(
            config: [
                'id'      => 'overlap_edge',
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'START'        => 'processing',
                            'PROVIDE_CARD' => 'done',
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
                'events' => [
                    'START'        => TestStartEvent::class,
                    'PROVIDE_CARD' => ProvideCardEvent::class,
                ],
            ],
            endpoints: ['PROVIDE_CARD'],
        );
    })->toThrow(InvalidArgumentException::class);
});

test('overlap rejection error message contains removal instructions', function (): void {
    try {
        MachineDefinition::define(
            config: [
                'id'      => 'msg_test',
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'START'        => 'processing',
                            'PROVIDE_CARD' => 'done',
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
                'events' => [
                    'START'        => TestStartEvent::class,
                    'PROVIDE_CARD' => ProvideCardEvent::class,
                ],
            ],
            endpoints: ['PROVIDE_CARD'],
        );

        test()->fail('Expected InvalidArgumentException was not thrown.');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('Remove');
    }
});

// ═══════════════════════════════════════════════════════════════
//  ForwardContext Injection Edge Cases
// ═══════════════════════════════════════════════════════════════

test('ForwardContext.childContext is the child ContextManager', function (): void {
    $childCtx   = Context::from(['card_last4' => '4242', 'status' => 'ok']);
    $childDef   = ForwardChildEndpointMachine::definition();
    $childState = State::forTesting(
        context: $childCtx,
        currentStateDefinition: $childDef->idMap['forward_endpoint_child.awaiting_confirmation'],
    );

    $fc = new ForwardContext(childContext: $childCtx, childState: $childState);

    expect($fc->childContext)->toBe($childCtx)
        ->and($fc->childContext->get('card_last4'))->toBe('4242');
});

test('ForwardContext.childState exposes child state value', function (): void {
    $childCtx   = Context::from(['order_id' => 1, 'card_last4' => null, 'status' => 'pending']);
    $childDef   = ForwardChildEndpointMachine::definition();
    $childState = State::forTesting(
        context: $childCtx,
        currentStateDefinition: $childDef->idMap['forward_endpoint_child.awaiting_card'],
    );

    $fc = new ForwardContext(childContext: $childCtx, childState: $childState);

    expect($fc->childState)->toBe($childState)
        ->and($fc->childState->value)->toBe(['forward_endpoint_child.awaiting_card']);
});

test('ForwardContext can carry a child state in final state', function (): void {
    $childCtx   = Context::from(['order_id' => 99, 'card_last4' => '1111', 'status' => 'charged']);
    $childDef   = ForwardChildEndpointMachine::definition();
    $childState = State::forTesting(
        context: $childCtx,
        currentStateDefinition: $childDef->idMap['forward_endpoint_child.charged'],
    );

    $fc = new ForwardContext(childContext: $childCtx, childState: $childState);

    expect($fc->childState->value)->toBe(['forward_endpoint_child.charged'])
        ->and($fc->childContext->get('status'))->toBe('charged');
});

// ═══════════════════════════════════════════════════════════════
//  available_events Edge Cases
// ═══════════════════════════════════════════════════════════════

test('available_events on final state returns empty array', function (): void {
    $tm = TestMachine::define(config: [
        'id'      => 'final_test',
        'initial' => 'idle',
        'context' => [],
        'states'  => [
            'idle' => ['on' => ['GO' => 'done']],
            'done' => ['type' => 'final'],
        ],
    ]);

    $tm->send('GO');

    expect($tm->state()->availableEvents())->toBe([]);
});

test('available_events on state with only @always guarded returns empty array', function (): void {
    $tm = TestMachine::define(
        config: [
            'id'      => 'always_guarded',
            'initial' => 'idle',
            'context' => ['ready' => false],
            'states'  => [
                'idle' => [
                    'on' => [
                        '@always' => [
                            'target' => 'done',
                            'guards' => 'neverGuard',
                        ],
                    ],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'guards' => [
                'neverGuard' => fn (ContextManager $ctx): bool => $ctx->get('ready') === true,
            ],
        ],
    );

    $tm->assertState('idle');

    expect($tm->state()->availableEvents())->toBe([]);
});

test('available_events on state with null currentStateDefinition returns empty array', function (): void {
    $state = State::forTesting(
        context: [],
        currentStateDefinition: null,
    );

    expect($state->availableEvents())->toBe([]);
});

test('forward events are filtered by child current state', function (): void {
    Queue::fake();

    $parent = ForwardParentEndpointMachine::create();
    $parent->send(['type' => 'START']);

    $childRecord = MachineChild::first();

    // Create child already in awaiting_confirmation (not initial state)
    $childMachine = ForwardChildEndpointMachine::create();
    $childMachine->send(['type' => 'PROVIDE_CARD', 'payload' => ['card_number' => '4111111111111111']]);
    $childRootId = $childMachine->state->history->first()->root_event_id;

    expect($childMachine->state->currentStateDefinition->id)
        ->toBe('forward_endpoint_child.awaiting_confirmation');

    $childRecord->update([
        'child_root_event_id' => $childRootId,
        'status'              => MachineChild::STATUS_RUNNING,
    ]);

    MachineCurrentState::where('root_event_id', $childRootId)
        ->update(['state_id' => 'forward_endpoint_child.awaiting_confirmation']);

    $events = $parent->state->availableEvents();
    $types  = array_column($events, 'type');

    // Child is in awaiting_confirmation which accepts CONFIRM_PAYMENT and ABORT
    // PROVIDE_CARD is NOT accepted by awaiting_confirmation
    expect($types)->toContain('CONFIRM_PAYMENT')
        ->and($types)->not->toContain('PROVIDE_CARD');
});

test('no forward events when child record does not exist', function (): void {
    Queue::fake();

    $parent = ForwardParentEndpointMachine::create();
    $parent->send(['type' => 'START']);

    // Delete the child record to simulate missing child
    MachineChild::truncate();

    $events = $parent->state->availableEvents();
    $types  = array_column($events, 'type');

    // Only parent on-events should be present (CANCEL)
    expect($types)->toContain('CANCEL')
        ->and(array_filter($events, fn (array $e): bool => $e['source'] === 'forward'))->toBeEmpty();
});

test('no forward events when child has no MachineCurrentState record', function (): void {
    Queue::fake();

    $parent = ForwardParentEndpointMachine::create();
    $parent->send(['type' => 'START']);

    $childRecord = MachineChild::first();

    // Create a child but remove its MachineCurrentState entry
    $childMachine = ForwardChildEndpointMachine::create();
    $childRootId  = $childMachine->state->history->first()->root_event_id;

    $childRecord->update([
        'child_root_event_id' => $childRootId,
        'status'              => MachineChild::STATUS_RUNNING,
    ]);

    MachineCurrentState::where('root_event_id', $childRootId)->delete();

    $events  = $parent->state->availableEvents();
    $sources = array_column($events, 'source');

    expect($sources)->not->toContain('forward');
});

// ═══════════════════════════════════════════════════════════════
//  Custom URI + Rename Interaction
// ═══════════════════════════════════════════════════════════════

test('custom URI + rename forward generates correct route path', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'uri_rename',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle'       => ['on' => ['START' => 'processing']],
                'processing' => [
                    'machine' => ForwardChildEndpointMachine::class,
                    'queue'   => 'default',
                    'forward' => [
                        'CANCEL_ORDER' => [
                            'child_event' => 'ABORT',
                            'uri'         => '/cancel-my-order',
                        ],
                    ],
                    '@done' => 'done',
                ],
                'done' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'events' => ['START' => TestStartEvent::class],
        ],
    );

    $fwd = $definition->forwardedEndpoints['CANCEL_ORDER'];

    expect($fwd->uri)->toBe('/cancel-my-order')
        ->and($fwd->childEventType)->toBe('ABORT')
        ->and($fwd->parentEventType)->toBe('CANCEL_ORDER');
});

test('rename forward without custom URI generates URI from parent event type', function (): void {
    $definition = RenameForwardParentMachine::definition();

    $fwd = $definition->forwardedEndpoints['CANCEL_ORDER'];

    // URI is generated from 'CANCEL_ORDER' not 'ABORT'
    expect($fwd->uri)->toBe('/cancel-order')
        ->and($fwd->childEventType)->toBe('ABORT')
        ->and($fwd->parentEventType)->toBe('CANCEL_ORDER');
});

// ═══════════════════════════════════════════════════════════════
//  available_events Configuration (available_events: false)
// ═══════════════════════════════════════════════════════════════

test('full config forward with available_events false is stored in definition', function (): void {
    $definition = FullConfigForwardParentMachine::definition();

    $fwd = $definition->forwardedEndpoints['PROVIDE_CARD'];

    expect($fwd->availableEvents)->toBeFalse();
});

test('forward without available_events key defaults to null', function (): void {
    $definition = ForwardParentEndpointMachine::definition();

    // PROVIDE_CARD is Format 1 (plain), no available_events key
    $fwd = $definition->forwardedEndpoints['PROVIDE_CARD'];

    expect($fwd->availableEvents)->toBeNull();
});

// ═══════════════════════════════════════════════════════════════
//  Forward Event with Empty Payload
// ═══════════════════════════════════════════════════════════════

test('child event without rules accepts empty payload via validateAndCreate', function (): void {
    // AbortEvent has no rules() method defined — empty payload should pass validation
    $event = AbortEvent::validateAndCreate(['payload' => []]);

    expect($event->type)->toBe('ABORT')
        ->and($event->payload)->toBe([]);
});

test('child event without rules accepts request with no payload key', function (): void {
    // AbortEvent has no rules — even completely empty input should work
    $event = AbortEvent::validateAndCreate([]);

    expect($event->type)->toBe('ABORT');
});

// ═══════════════════════════════════════════════════════════════
//  Action Lifecycle
// ═══════════════════════════════════════════════════════════════

test('ForwardEndpointAction static flags are reset correctly', function (): void {
    ForwardEndpointAction::$beforeCalled    = true;
    ForwardEndpointAction::$afterCalled     = true;
    ForwardEndpointAction::$exceptionCaught = true;

    ForwardEndpointAction::reset();

    expect(ForwardEndpointAction::$beforeCalled)->toBeFalse()
        ->and(ForwardEndpointAction::$afterCalled)->toBeFalse()
        ->and(ForwardEndpointAction::$exceptionCaught)->toBeFalse();
});

// ═══════════════════════════════════════════════════════════════
//  ForwardedEndpointDefinition Value Object
// ═══════════════════════════════════════════════════════════════

test('ForwardedEndpointDefinition stores all constructor parameters correctly', function (): void {
    $fwd = new ForwardedEndpointDefinition(
        parentEventType: 'SUBMIT_FORM',
        childEventType: 'PROCESS_FORM',
        childMachineClass: ForwardChildEndpointMachine::class,
        childEventClass: ProvideCardEvent::class,
        uri: '/custom-form',
        method: 'PUT',
        actionClass: ForwardEndpointAction::class,
        resultBehavior: PaymentStepResult::class,
        contextKeys: ['field_a', 'field_b'],
        statusCode: 201,
        middleware: ['auth', 'throttle:5'],
        availableEvents: true,
    );

    expect($fwd->parentEventType)->toBe('SUBMIT_FORM')
        ->and($fwd->childEventType)->toBe('PROCESS_FORM')
        ->and($fwd->childMachineClass)->toBe(ForwardChildEndpointMachine::class)
        ->and($fwd->childEventClass)->toBe(ProvideCardEvent::class)
        ->and($fwd->uri)->toBe('/custom-form')
        ->and($fwd->method)->toBe('PUT')
        ->and($fwd->actionClass)->toBe(ForwardEndpointAction::class)
        ->and($fwd->resultBehavior)->toBe(PaymentStepResult::class)
        ->and($fwd->contextKeys)->toBe(['field_a', 'field_b'])
        ->and($fwd->statusCode)->toBe(201)
        ->and($fwd->middleware)->toBe(['auth', 'throttle:5'])
        ->and($fwd->availableEvents)->toBeTrue();
});

test('ForwardedEndpointDefinition defaults are correct', function (): void {
    $fwd = new ForwardedEndpointDefinition(
        parentEventType: 'EVENT',
        childEventType: 'EVENT',
        childMachineClass: ForwardChildEndpointMachine::class,
        childEventClass: ProvideCardEvent::class,
        uri: '/event',
    );

    expect($fwd->method)->toBe('POST')
        ->and($fwd->actionClass)->toBeNull()
        ->and($fwd->resultBehavior)->toBeNull()
        ->and($fwd->contextKeys)->toBeNull()
        ->and($fwd->statusCode)->toBeNull()
        ->and($fwd->middleware)->toBe([])
        ->and($fwd->availableEvents)->toBeNull();
});
