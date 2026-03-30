<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Testing\TestMachine;
use Tarfinlabs\EventMachine\Behavior\OutputBehavior;

class ComputedContextManager extends ContextManager
{
    public function __construct(
        public int $subtotal = 0,
        public int $tax = 0,
    ) {
        parent::__construct();
    }

    protected function computedContext(): array
    {
        return ['total' => $this->subtotal + $this->tax];
    }
}

class ComputedTestOutput extends OutputBehavior
{
    public function __invoke(ComputedContextManager $ctx): array
    {
        return ['computedTotal' => $ctx->subtotal + $ctx->tax];
    }
}

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
