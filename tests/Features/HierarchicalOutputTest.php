<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Testing\TestMachine;

it('child atomic state output overrides parent compound state output', function (): void {
    $test = TestMachine::define([
        'initial' => 'parent',
        'context' => ['a' => 1, 'b' => 2, 'c' => 3],
        'states'  => [
            'parent' => [
                'initial' => 'child',
                'output'  => ['a', 'b'],
                'states'  => [
                    'child' => [
                        'output' => ['c'],
                        'on'     => ['DONE' => 'sibling'],
                    ],
                    'sibling' => [],
                ],
            ],
        ],
    ]);

    // child has output → overrides parent's output
    expect($test->machine()->output())->toBe(['c' => 3]);
});

it('parent compound output used when child has no output', function (): void {
    $test = TestMachine::define([
        'initial' => 'parent',
        'context' => ['a' => 1, 'b' => 2, 'c' => 3],
        'states'  => [
            'parent' => [
                'initial' => 'child',
                'output'  => ['a', 'b'],
                'states'  => [
                    'child'   => ['on' => ['DONE' => 'sibling']],
                    'sibling' => [],
                ],
            ],
        ],
    ]);

    // child has no output → parent's output applies
    expect($test->machine()->output())->toBe(['a' => 1, 'b' => 2]);
});

it('no output on either parent or child → toResponseArray() fallback', function (): void {
    $test = TestMachine::define([
        'initial' => 'parent',
        'context' => ['x' => 99],
        'states'  => [
            'parent' => [
                'initial' => 'child',
                'states'  => [
                    'child' => [],
                ],
            ],
        ],
    ]);

    $output = $test->machine()->output();
    expect($output)->toBeArray();
});

it('three levels deep: grandchild > child > parent resolution', function (): void {
    $test = TestMachine::define([
        'initial' => 'level1',
        'context' => ['a' => 1, 'b' => 2, 'c' => 3],
        'states'  => [
            'level1' => [
                'initial' => 'level2',
                'output'  => ['a'],
                'states'  => [
                    'level2' => [
                        'initial' => 'level3',
                        'output'  => ['b'],
                        'states'  => [
                            'level3' => [
                                'output' => ['c'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    // level3 (grandchild) has output → wins over level2 and level1
    expect($test->machine()->output())->toBe(['c' => 3]);
});
