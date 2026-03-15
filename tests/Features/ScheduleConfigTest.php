<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Definition\ScheduleDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Events\SimpleEvent;

it('define() accepts schedules parameter with class resolver', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'schedule_test',
            'initial' => 'active',
            'states'  => [
                'active' => [
                    'on' => [
                        'CHECK_EXPIRY' => 'expired',
                    ],
                ],
                'expired' => [
                    'type' => 'final',
                ],
            ],
        ],
        schedules: [
            'CHECK_EXPIRY' => 'App\\Resolvers\\ExpiredResolver',
        ],
    );

    expect($definition->parsedSchedules)
        ->toBeArray()
        ->toHaveCount(1)
        ->toHaveKey('CHECK_EXPIRY')
        ->and($definition->parsedSchedules['CHECK_EXPIRY'])
        ->toBeInstanceOf(ScheduleDefinition::class)
        ->and($definition->parsedSchedules['CHECK_EXPIRY']->eventType)
        ->toBe('CHECK_EXPIRY')
        ->and($definition->parsedSchedules['CHECK_EXPIRY']->resolver)
        ->toBe('App\\Resolvers\\ExpiredResolver');
});

it('define() accepts schedules with closure resolver', function (): void {
    $closure = fn () => collect(['id-1', 'id-2']);

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'schedule_closure',
            'initial' => 'active',
            'states'  => [
                'active' => [
                    'on' => [
                        'SEND_REMINDER' => 'reminded',
                    ],
                ],
                'reminded' => [
                    'type' => 'final',
                ],
            ],
        ],
        schedules: [
            'SEND_REMINDER' => $closure,
        ],
    );

    expect($definition->parsedSchedules['SEND_REMINDER']->resolver)->toBe($closure)
        ->and($definition->parsedSchedules['SEND_REMINDER']->hasResolver())->toBeTrue();
});

it('define() accepts schedules with null resolver for auto-detect', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'schedule_null',
            'initial' => 'active',
            'states'  => [
                'active' => [
                    'on' => [
                        'DAILY_REPORT' => 'reported',
                    ],
                ],
                'reported' => [
                    'type' => 'final',
                ],
            ],
        ],
        schedules: [
            'DAILY_REPORT' => null,
        ],
    );

    expect($definition->parsedSchedules['DAILY_REPORT']->resolver)->toBeNull()
        ->and($definition->parsedSchedules['DAILY_REPORT']->hasResolver())->toBeFalse();
});

it('schedules normalizes EventBehavior FQCN key to event type string', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'schedule_fqcn',
            'initial' => 'active',
            'states'  => [
                'active' => [
                    'on' => [
                        'SIMPLE_EVENT' => 'done',
                    ],
                ],
                'done' => [
                    'type' => 'final',
                ],
            ],
        ],
        schedules: [
            SimpleEvent::class => 'App\\Resolvers\\SimpleResolver',
        ],
    );

    expect($definition->parsedSchedules)
        ->toHaveKey('SIMPLE_EVENT')
        ->not->toHaveKey(SimpleEvent::class);
});

it('parsedSchedules is null when no schedules provided', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'no_schedules',
            'initial' => 'idle',
            'states'  => [
                'idle' => [],
            ],
        ],
    );

    expect($definition->parsedSchedules)->toBeNull();
});

it('define() accepts multiple schedules', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'multi_schedule',
            'initial' => 'active',
            'states'  => [
                'active' => [
                    'on' => [
                        'CHECK_EXPIRY'  => 'expired',
                        'SEND_REMINDER' => 'active',
                    ],
                ],
                'expired' => [
                    'type' => 'final',
                ],
            ],
        ],
        schedules: [
            'CHECK_EXPIRY'  => 'App\\Resolvers\\ExpiredResolver',
            'SEND_REMINDER' => fn () => collect(),
        ],
    );

    expect($definition->parsedSchedules)
        ->toHaveCount(2)
        ->toHaveKeys(['CHECK_EXPIRY', 'SEND_REMINDER']);
});
