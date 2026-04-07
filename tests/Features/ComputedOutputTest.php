<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Testing\TestMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Outputs\ComputedTestOutput;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\ComputedContextManager;

it('output array filter includes computed value from computedContext()', function (): void {
    $test = TestMachine::define([
        'initial' => 'active',
        'context' => ComputedContextManager::class,
        'states'  => [
            'active' => [
                'output' => ['subtotal', 'total'],
                'on'     => ['DONE' => 'completed'],
            ],
            'completed' => ['type' => 'final'],
        ],
    ]);

    $test->machine()->state->context->subtotal = 100;
    $test->machine()->state->context->tax      = 18;

    $output = $test->machine()->output();
    expect($output)->toHaveKey('subtotal')
        ->and($output['subtotal'])->toBe(100)
        ->and($output)->toHaveKey('total')
        ->and($output['total'])->toBe(118);
});

it('OutputBehavior can compute values from typed ContextManager', function (): void {
    $test = TestMachine::define([
        'initial' => 'active',
        'context' => ComputedContextManager::class,
        'states'  => [
            'active' => [
                'output' => ComputedTestOutput::class,
                'on'     => ['DONE' => 'completed'],
            ],
            'completed' => ['type' => 'final'],
        ],
    ]);

    $test->machine()->state->context->subtotal = 200;
    $test->machine()->state->context->tax      = 36;

    expect($test->machine()->output())->toBe(['computedTotal' => 236]);
});

it('toResponseArray() fallback includes computedContext() values', function (): void {
    $test = TestMachine::define([
        'initial' => 'active',
        'context' => ComputedContextManager::class,
        'states'  => [
            'active'    => ['on' => ['DONE' => 'completed']],
            'completed' => ['type' => 'final'],
        ],
    ]);

    $test->machine()->state->context->subtotal = 50;
    $test->machine()->state->context->tax      = 9;

    $output = $test->machine()->output();
    // No output defined → toResponseArray() fallback includes computed 'total'
    expect($output)->toHaveKey('total')
        ->and($output['total'])->toBe(59);
});
