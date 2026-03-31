<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Testing\TestMachine;
use Tarfinlabs\EventMachine\Behavior\OutputBehavior;

// ═══════════════════════════════════════════════════════════════
//  State-Level Output on Non-Final States
// ═══════════════════════════════════════════════════════════════

class StateLevelTestOutput extends OutputBehavior
{
    public function __invoke(ContextManager $ctx): array
    {
        return ['computed' => $ctx->get('raw') * 2];
    }
}

it('output array filter on non-final state returns filtered context', function (): void {
    $test = TestMachine::define([
        'initial' => 'active',
        'context' => ['price' => 100, 'internal' => 'hidden'],
        'states'  => [
            'active' => [
                'output' => ['price'],
                'on'     => ['DONE' => 'completed'],
            ],
            'completed' => ['type' => 'final'],
        ],
    ]);

    expect($test->machine()->output())->toBe(['price' => 100]);
});

it('output on compound state applies to children without own output', function (): void {
    $test = TestMachine::define([
        'initial' => 'parent',
        'context' => ['value' => 42, 'secret' => 'hidden'],
        'states'  => [
            'parent' => [
                'initial' => 'child_a',
                'output'  => ['value'],
                'states'  => [
                    'child_a' => ['on' => ['NEXT' => 'child_b']],
                    'child_b' => [],
                ],
            ],
        ],
    ]);

    // child_a has no output → parent's output applies
    expect($test->machine()->output())->toBe(['value' => 42]);
});

it('state without output returns toResponseArray() fallback', function (): void {
    $test = TestMachine::define([
        'initial' => 'active',
        'context' => ['a' => 1, 'b' => 2],
        'states'  => [
            'active'    => ['on' => ['DONE' => 'completed']],
            'completed' => ['type' => 'final'],
        ],
    ]);

    $output = $test->machine()->output();
    expect($output)->toBeArray();
});

it('output with OutputBehavior class returns computed output', function (): void {
    $test = TestMachine::define([
        'initial' => 'active',
        'context' => ['raw' => 21],
        'states'  => [
            'active' => [
                'output' => StateLevelTestOutput::class,
                'on'     => ['DONE' => 'completed'],
            ],
            'completed' => ['type' => 'final'],
        ],
    ]);

    expect($test->machine()->output())->toBe(['computed' => 42]);
});

it('output with empty array returns empty (metadata only)', function (): void {
    $test = TestMachine::define([
        'initial' => 'active',
        'context' => ['value' => 123],
        'states'  => [
            'active' => [
                'output' => [],
                'on'     => ['DONE' => 'completed'],
            ],
            'completed' => ['type' => 'final'],
        ],
    ]);

    expect($test->machine()->output())->toBe([]);
});

it('output with closure returns closure result', function (): void {
    $test = TestMachine::define([
        'initial' => 'active',
        'context' => ['amount' => 500],
        'states'  => [
            'active' => [
                'output' => fn (ContextManager $ctx) => ['total' => $ctx->get('amount') * 1.18],
                'on'     => ['DONE' => 'completed'],
            ],
            'completed' => ['type' => 'final'],
        ],
    ]);

    expect($test->machine()->output())->toBe(['total' => 590.0]);
});
