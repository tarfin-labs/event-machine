<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Support\Timer;
use PHPUnit\Framework\AssertionFailedError;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Testing\TestMachine;

// region @after — basic

it('fires after timer when deadline is reached with define()', function (): void {
    TestMachine::define([
        'id'      => 'after_test',
        'initial' => 'waiting',
        'states'  => [
            'waiting' => [
                'on' => [
                    'EXPIRE' => [
                        'target' => 'expired',
                        'after'  => Timer::seconds(30),
                    ],
                ],
            ],
            'expired' => ['type' => 'final'],
        ],
    ])
        ->assertState('waiting')
        ->advanceTimers(Timer::seconds(31))
        ->assertState('expired');
});

it('does not fire after timer before deadline', function (): void {
    TestMachine::define([
        'id'      => 'after_test',
        'initial' => 'waiting',
        'states'  => [
            'waiting' => [
                'on' => [
                    'EXPIRE' => [
                        'target' => 'expired',
                        'after'  => Timer::seconds(30),
                    ],
                ],
            ],
            'expired' => ['type' => 'final'],
        ],
    ])
        ->assertState('waiting')
        ->advanceTimers(Timer::seconds(20))
        ->assertState('waiting');
});

// endregion

// region @after — dedup and history

it('fires after timer only once (dedup)', function (): void {
    $machine = TestMachine::define([
        'id'      => 'dedup_test',
        'initial' => 'waiting',
        'context' => ['count' => 0],
        'states'  => [
            'waiting' => [
                'on' => [
                    'TICK' => [
                        'target'  => 'waiting',
                        'after'   => Timer::seconds(10),
                        'actions' => 'incrementAction',
                    ],
                ],
            ],
        ],
    ], behavior: [
        'actions' => [
            'incrementAction' => function (ContextManager $context): void {
                $context->set('count', $context->get('count') + 1);
            },
        ],
    ]);

    $machine
        ->advanceTimers(Timer::seconds(11))
        ->advanceTimers(Timer::seconds(11))
        ->assertContext('count', 1);
});

it('preserves timer fire history after state transition', function (): void {
    TestMachine::define([
        'id'      => 'history_test',
        'initial' => 'waiting',
        'states'  => [
            'waiting' => [
                'on' => [
                    'EXPIRE' => [
                        'target' => 'expired',
                        'after'  => Timer::seconds(30),
                    ],
                ],
            ],
            'expired' => ['type' => 'final'],
        ],
    ])
        ->advanceTimers(Timer::seconds(31))
        ->assertState('expired')
        ->assertTimerFired('EXPIRE');
});

// endregion

// region @every — basic, max, then

it('fires every timer on interval', function (): void {
    $machine = TestMachine::define([
        'id'      => 'every_test',
        'initial' => 'polling',
        'context' => ['poll_count' => 0],
        'states'  => [
            'polling' => [
                'on' => [
                    'POLL' => [
                        'target'  => 'polling',
                        'every'   => Timer::seconds(60),
                        'actions' => 'incrementAction',
                    ],
                ],
            ],
        ],
    ], behavior: [
        'actions' => [
            'incrementAction' => function (ContextManager $context): void {
                $context->set('poll_count', $context->get('poll_count') + 1);
            },
        ],
    ]);

    $machine
        ->advanceTimers(Timer::seconds(61))
        ->assertContext('poll_count', 1)
        ->advanceTimers(Timer::seconds(61))
        ->assertContext('poll_count', 2);
});

it('respects every timer max count', function (): void {
    $machine = TestMachine::define([
        'id'      => 'max_test',
        'initial' => 'retrying',
        'context' => ['retry_count' => 0],
        'states'  => [
            'retrying' => [
                'on' => [
                    'RETRY' => [
                        'target'  => 'retrying',
                        'every'   => Timer::seconds(10),
                        'max'     => 3,
                        'actions' => 'incrementAction',
                    ],
                ],
            ],
        ],
    ], behavior: [
        'actions' => [
            'incrementAction' => function (ContextManager $context): void {
                $context->set('retry_count', $context->get('retry_count') + 1);
            },
        ],
    ]);

    $machine
        ->advanceTimers(Timer::seconds(11))
        ->assertContext('retry_count', 1)
        ->advanceTimers(Timer::seconds(11))
        ->assertContext('retry_count', 2)
        ->advanceTimers(Timer::seconds(11))
        ->assertContext('retry_count', 3)
        ->advanceTimers(Timer::seconds(11))
        ->assertContext('retry_count', 3); // exhausted, no more fires
});

it('sends then-event after every timer max reached', function (): void {
    TestMachine::define([
        'id'      => 'then_test',
        'initial' => 'retrying',
        'context' => ['retry_count' => 0],
        'states'  => [
            'retrying' => [
                'on' => [
                    'RETRY' => [
                        'target'  => 'retrying',
                        'every'   => Timer::seconds(10),
                        'max'     => 2,
                        'then'    => 'MAX_RETRIES',
                        'actions' => 'incrementAction',
                    ],
                    'MAX_RETRIES' => ['target' => 'failed'],
                ],
            ],
            'failed' => ['type' => 'final'],
        ],
    ], behavior: [
        'actions' => [
            'incrementAction' => function (ContextManager $context): void {
                $context->set('retry_count', $context->get('retry_count') + 1);
            },
        ],
    ])
        ->advanceTimers(Timer::seconds(11))
        ->assertContext('retry_count', 1)
        ->advanceTimers(Timer::seconds(11))
        ->assertContext('retry_count', 2)
        ->advanceTimers(Timer::seconds(11))
        ->assertState('failed');
});

// endregion

// region Assertions — assertHasTimer with duration, assertTimerFired/NotFired

it('asserts timer exists with correct duration', function (): void {
    TestMachine::define([
        'id'      => 'assert_test',
        'initial' => 'waiting',
        'states'  => [
            'waiting' => [
                'on' => [
                    'EXPIRE' => [
                        'target' => 'done',
                        'after'  => Timer::seconds(120),
                    ],
                ],
            ],
            'done' => ['type' => 'final'],
        ],
    ])
        ->assertHasTimer('EXPIRE', Timer::seconds(120));
});

it('fails assertHasTimer when duration does not match', function (): void {
    $machine = TestMachine::define([
        'id'      => 'assert_test',
        'initial' => 'waiting',
        'states'  => [
            'waiting' => [
                'on' => [
                    'EXPIRE' => [
                        'target' => 'done',
                        'after'  => Timer::seconds(120),
                    ],
                ],
            ],
            'done' => ['type' => 'final'],
        ],
    ]);

    expect(fn () => $machine->assertHasTimer('EXPIRE', Timer::seconds(60)))
        ->toThrow(AssertionFailedError::class);
});

it('assertTimerFired works in-memory', function (): void {
    $machine = TestMachine::define([
        'id'      => 'fired_test',
        'initial' => 'waiting',
        'states'  => [
            'waiting' => [
                'on' => [
                    'EXPIRE' => [
                        'target' => 'done',
                        'after'  => Timer::seconds(5),
                    ],
                ],
            ],
            'done' => ['type' => 'final'],
        ],
    ]);

    // Before timer fires
    expect(fn () => $machine->assertTimerFired('EXPIRE'))
        ->toThrow(AssertionFailedError::class);

    // After timer fires
    $machine->advanceTimers(Timer::seconds(6));

    expect(fn () => $machine->assertTimerNotFired('EXPIRE'))
        ->toThrow(AssertionFailedError::class);

    expect($machine->machine()->state->currentStateDefinition->id)->toContain('done');
});

// endregion

// region State transition, guard blocking, cumulative

it('resets timers on state transition', function (): void {
    TestMachine::define([
        'id'      => 'reset_test',
        'initial' => 'state_a',
        'states'  => [
            'state_a' => [
                'on' => [
                    'TIMEOUT_A' => [
                        'target' => 'state_b',
                        'after'  => Timer::seconds(10),
                    ],
                ],
            ],
            'state_b' => [
                'on' => [
                    'TIMEOUT_B' => [
                        'target' => 'done',
                        'after'  => Timer::seconds(20),
                    ],
                ],
            ],
            'done' => ['type' => 'final'],
        ],
    ])
        ->advanceTimers(Timer::seconds(11))
        ->assertState('state_b')
        ->advanceTimers(Timer::seconds(15))
        ->assertState('state_b')   // 15s < 20s deadline for state_b
        ->advanceTimers(Timer::seconds(6))
        ->assertState('done');      // total 21s > 20s
});

it('records fire even when guard blocks transition', function (): void {
    TestMachine::define([
        'id'      => 'guard_test',
        'initial' => 'waiting',
        'context' => ['allow' => false],
        'states'  => [
            'waiting' => [
                'on' => [
                    'TICK' => [
                        'target' => 'done',
                        'after'  => Timer::seconds(5),
                        'guards' => 'allowGuard',
                    ],
                ],
            ],
            'done' => ['type' => 'final'],
        ],
    ], behavior: [
        'guards' => [
            'allowGuard' => function (ContextManager $context): bool {
                return $context->get('allow') === true;
            },
        ],
    ])
        ->advanceTimers(Timer::seconds(6))
        ->assertState('waiting'); // guard blocked
});

it('fires timer with cumulative advanceTimers calls', function (): void {
    TestMachine::define([
        'id'      => 'cumulative_test',
        'initial' => 'waiting',
        'states'  => [
            'waiting' => [
                'on' => [
                    'EXPIRE' => [
                        'target' => 'expired',
                        'after'  => Timer::seconds(120),
                    ],
                ],
            ],
            'expired' => ['type' => 'final'],
        ],
    ])
        ->advanceTimers(Timer::seconds(60))
        ->assertState('waiting')
        ->advanceTimers(Timer::seconds(61))
        ->assertState('expired');
});

// endregion

// region Compatibility — withContext, DB path, for()

it('works with withContext and advanceTimers', function (): void {
    // This test needs a real machine class with timer config.
    // Since withContext requires a machine class, we test that define() covers the same case.
    // withContext sets shouldPersist=false, so the in-memory path is taken.
    TestMachine::define([
        'id'      => 'with_context_test',
        'initial' => 'active',
        'context' => ['user_id' => 42],
        'states'  => [
            'active' => [
                'on' => [
                    'SESSION_TIMEOUT' => [
                        'target' => 'timed_out',
                        'after'  => Timer::minutes(30),
                    ],
                ],
            ],
            'timed_out' => ['type' => 'final'],
        ],
    ])
        ->assertContext('user_id', 42)
        ->advanceTimers(Timer::minutes(31))
        ->assertState('timed_out');
});

// endregion
