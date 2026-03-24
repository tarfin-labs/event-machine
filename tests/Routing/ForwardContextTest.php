<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Routing\ForwardContext;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint\ForwardChildEndpointMachine;

// === Construction ===

test('it constructs with childContext and childState', function (): void {
    $context = GenericContext::from(['order_id' => 42, 'card_last4' => '1234', 'status' => 'card_provided']);

    $definition      = ForwardChildEndpointMachine::definition();
    $stateDefinition = $definition->idMap['forward_endpoint_child.awaiting_confirmation'];

    $state = State::forTesting(
        context: $context,
        currentStateDefinition: $stateDefinition,
    );

    $forwardContext = new ForwardContext(
        childContext: $context,
        childState: $state,
    );

    expect($forwardContext->childContext)->toBe($context)
        ->and($forwardContext->childState)->toBe($state);
});

// === childContext accessor ===

test('childContext is the ContextManager from child machine', function (): void {
    $context = GenericContext::from(['order_id' => 99, 'card_last4' => '5678', 'status' => 'pending']);

    $state = State::forTesting(context: $context);

    $forwardContext = new ForwardContext(
        childContext: $context,
        childState: $state,
    );

    expect($forwardContext->childContext)
        ->toBeInstanceOf(ContextManager::class)
        ->and($forwardContext->childContext->order_id)->toBe(99)
        ->and($forwardContext->childContext->card_last4)->toBe('5678')
        ->and($forwardContext->childContext->status)->toBe('pending');
});

// === childState accessor ===

test('childState is the State from child machine', function (): void {
    $definition      = ForwardChildEndpointMachine::definition();
    $stateDefinition = $definition->idMap['forward_endpoint_child.awaiting_card'];

    $context = GenericContext::from(['order_id' => null, 'card_last4' => null, 'status' => 'pending']);

    $state = State::forTesting(
        context: $context,
        currentStateDefinition: $stateDefinition,
    );

    $forwardContext = new ForwardContext(
        childContext: $context,
        childState: $state,
    );

    expect($forwardContext->childState)
        ->toBeInstanceOf(State::class)
        ->and($forwardContext->childState->value)->toBe(['forward_endpoint_child.awaiting_card']);
});

// === Readonly properties ===

test('properties are readonly', function (): void {
    $contextProperty = new ReflectionProperty(ForwardContext::class, 'childContext');
    $stateProperty   = new ReflectionProperty(ForwardContext::class, 'childState');

    expect($contextProperty->isReadOnly())->toBeTrue()
        ->and($stateProperty->isReadOnly())->toBeTrue();
});
