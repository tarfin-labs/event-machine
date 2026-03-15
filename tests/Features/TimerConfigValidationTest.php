<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Support\Timer;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

// ─── after key accepted ─────────────────────────────────────────

it('accepts after key on transition', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'valid_after',
            'initial' => 'idle',
            'states'  => [
                'idle' => [
                    'on' => [
                        'EXPIRED' => ['target' => 'done', 'after' => Timer::days(7)],
                    ],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
    );

    expect($machine)->toBeInstanceOf(MachineDefinition::class);
});

// ─── every key accepted ─────────────────────────────────────────

it('accepts every key on transition', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'valid_every',
            'initial' => 'active',
            'states'  => [
                'active' => [
                    'on' => [
                        'BILLING' => ['actions' => 'billingAction', 'every' => Timer::days(30)],
                        'CANCEL'  => 'done',
                    ],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'billingAction' => function (): void {},
            ],
        ],
    );

    expect($machine)->toBeInstanceOf(MachineDefinition::class);
});

// ─── max/then accepted with every ───────────────────────────────

it('accepts max and then with every', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'valid_max',
            'initial' => 'retrying',
            'states'  => [
                'retrying' => [
                    'on' => [
                        'RETRY'       => ['actions' => 'retryAction', 'every' => Timer::hours(6), 'max' => 3, 'then' => 'MAX_RETRIES'],
                        'MAX_RETRIES' => 'failed',
                    ],
                ],
                'failed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'retryAction' => function (): void {},
            ],
        ],
    );

    expect($machine)->toBeInstanceOf(MachineDefinition::class);
});

// ─── TimerDefinition extraction ─────────────────────────────────

it('extracts TimerDefinition from after key on transition', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'extract_after',
            'initial' => 'waiting',
            'states'  => [
                'waiting' => [
                    'on' => [
                        'TIMEOUT' => ['target' => 'expired', 'after' => Timer::days(7)],
                    ],
                ],
                'expired' => ['type' => 'final'],
            ],
        ],
    );

    $state      = $machine->idMap['extract_after.waiting'];
    $transition = $state->transitionDefinitions['TIMEOUT'];

    expect($transition->timerDefinition)->not->toBeNull()
        ->and($transition->timerDefinition->isAfter())->toBeTrue()
        ->and($transition->timerDefinition->delaySeconds)->toBe(604800)
        ->and($transition->timerDefinition->eventName)->toBe('TIMEOUT')
        ->and($transition->timerDefinition->stateId)->toBe('extract_after.waiting')
        ->and($transition->timerDefinition->key())->toBe('extract_after.waiting:TIMEOUT:604800');
});

it('extracts TimerDefinition from every key on transition', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'extract_every',
            'initial' => 'active',
            'states'  => [
                'active' => [
                    'on' => [
                        'BILLING' => ['actions' => 'billingAction', 'every' => Timer::days(30), 'max' => 12, 'then' => 'EXPIRED'],
                        'EXPIRED' => 'done',
                    ],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'billingAction' => function (): void {},
            ],
        ],
    );

    $state      = $machine->idMap['extract_every.active'];
    $transition = $state->transitionDefinitions['BILLING'];

    expect($transition->timerDefinition)->not->toBeNull()
        ->and($transition->timerDefinition->isEvery())->toBeTrue()
        ->and($transition->timerDefinition->delaySeconds)->toBe(2592000)
        ->and($transition->timerDefinition->max)->toBe(12)
        ->and($transition->timerDefinition->then)->toBe('EXPIRED');
});

it('does not extract timer when no after/every key', function (): void {
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'no_timer',
            'initial' => 'idle',
            'states'  => [
                'idle' => [
                    'on' => ['GO' => 'done'],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
    );

    $state      = $machine->idMap['no_timer.idle'];
    $transition = $state->transitionDefinitions['GO'];

    expect($transition->timerDefinition)->toBeNull();
});

// ─── Negative Validation Tests ──────────────────────────────────

it('Timer rejects zero duration', function (): void {
    Timer::seconds(0);
})->throws(InvalidArgumentException::class, 'must be positive');

it('Timer rejects negative duration', function (): void {
    Timer::days(-1);
})->throws(InvalidArgumentException::class, 'must be positive');
