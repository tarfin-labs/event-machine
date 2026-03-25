<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;

// ============================================================
// Calculator Context Interleaving
// ============================================================
// Calculators update context before guards and actions in the same
// transition step. Actions in the same transition must be able to
// read the context values set by calculators.

test('calculator sets context key that subsequent action reads in same transition', function (): void {
    $actionSawValue = null;

    $machine = Machine::create([
        'config' => [
            'initial' => 'start',
            'context' => [
                'raw_price' => 200,
                'tax_rate'  => 0.18,
                'total'     => null,
            ],
            'states' => [
                'start' => [
                    'on' => [
                        'CALCULATE' => [
                            'target'      => 'calculated',
                            'calculators' => 'computeTotalCalculator',
                            'actions'     => 'readTotalAction',
                        ],
                    ],
                ],
                'calculated' => [],
            ],
        ],
        'behavior' => [
            'calculators' => [
                'computeTotalCalculator' => function (ContextManager $ctx): void {
                    $price   = $ctx->get('raw_price');
                    $taxRate = $ctx->get('tax_rate');
                    $ctx->set('total', $price + (int) ($price * $taxRate));
                },
            ],
            'actions' => [
                'readTotalAction' => function (ContextManager $ctx) use (&$actionSawValue): void {
                    $actionSawValue = $ctx->get('total');
                },
            ],
        ],
    ]);

    $state = $machine->send(['type' => 'CALCULATE']);

    // Calculator ran first, set total = 200 + 36 = 236
    expect($state->context->get('total'))->toBe(236);
    // Action should have read the calculator's value
    expect($actionSawValue)->toBe(236);
    expect($state->matches('calculated'))->toBeTrue();
});

test('multiple calculators set values visible to action in same step', function (): void {
    $actionSawSubtotal = null;
    $actionSawDiscount = null;

    $machine = Machine::create([
        'config' => [
            'initial' => 'start',
            'context' => [
                'items'    => [100, 200, 300],
                'subtotal' => null,
                'discount' => null,
                'final'    => null,
            ],
            'states' => [
                'start' => [
                    'on' => [
                        'PROCESS' => [
                            'target'      => 'done',
                            'calculators' => ['sumCalculator', 'discountCalculator'],
                            'actions'     => 'buildFinalAction',
                        ],
                    ],
                ],
                'done' => [],
            ],
        ],
        'behavior' => [
            'calculators' => [
                'sumCalculator' => function (ContextManager $ctx): void {
                    $ctx->set('subtotal', array_sum($ctx->get('items')));
                },
                'discountCalculator' => function (ContextManager $ctx): void {
                    // This calculator can read the value from the previous calculator
                    $subtotal = $ctx->get('subtotal');
                    $ctx->set('discount', (int) ($subtotal * 0.1));
                },
            ],
            'actions' => [
                'buildFinalAction' => function (ContextManager $ctx) use (&$actionSawSubtotal, &$actionSawDiscount): void {
                    $actionSawSubtotal = $ctx->get('subtotal');
                    $actionSawDiscount = $ctx->get('discount');
                    $ctx->set('final', $actionSawSubtotal - $actionSawDiscount);
                },
            ],
        ],
    ]);

    $state = $machine->send(['type' => 'PROCESS']);

    expect($state->context->get('subtotal'))->toBe(600);
    expect($state->context->get('discount'))->toBe(60);
    expect($state->context->get('final'))->toBe(540);
    expect($actionSawSubtotal)->toBe(600);
    expect($actionSawDiscount)->toBe(60);
});

test('calculator sets context from event payload visible to action', function (): void {
    $actionSawComputed = null;

    $machine = Machine::create([
        'config' => [
            'initial' => 'start',
            'context' => [
                'computed_label' => null,
            ],
            'states' => [
                'start' => [
                    'on' => [
                        'LABEL' => [
                            'target'      => 'labeled',
                            'calculators' => 'buildLabelCalculator',
                            'actions'     => 'captureLabelAction',
                        ],
                    ],
                ],
                'labeled' => [],
            ],
        ],
        'behavior' => [
            'calculators' => [
                'buildLabelCalculator' => function (ContextManager $ctx, EventBehavior $event): void {
                    $prefix = $event->payload['prefix'] ?? 'ITEM';
                    $number = $event->payload['number'] ?? 0;
                    $ctx->set('computed_label', $prefix.'-'.$number);
                },
            ],
            'actions' => [
                'captureLabelAction' => function (ContextManager $ctx) use (&$actionSawComputed): void {
                    $actionSawComputed = $ctx->get('computed_label');
                },
            ],
        ],
    ]);

    $state = $machine->send([
        'type'    => 'LABEL',
        'payload' => ['prefix' => 'ORD', 'number' => 42],
    ]);

    expect($state->context->get('computed_label'))->toBe('ORD-42');
    expect($actionSawComputed)->toBe('ORD-42');
});
