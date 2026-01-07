<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\EventCollection;
use Tarfinlabs\EventMachine\Enums\SourceType;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Models\MachineEventArchive;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

describe('Archive Transparency Integration', function (): void {
    beforeEach(function (): void {
        // Enable archival for tests
        config([
            'machine.archival.enabled'   => true,
            'machine.archival.level'     => 6,
            'machine.archival.threshold' => 100,
        ]);
    });

    it('can restore machine state from archived events transparently', function (): void {
        $rootEventId = '01H8BM4VK82JKPK7RPR3YGT2DM';
        $machineId   = 'test_machine';

        // Create test events
        $events = collect([
            MachineEvent::create([
                'id'              => '01H8BM4VK82JKPK7RPR3YGT2DM',
                'sequence_number' => 1,
                'created_at'      => now()->subMinutes(5),
                'machine_id'      => $machineId,
                'machine_value'   => ['test_machine.initial'],
                'root_event_id'   => $rootEventId,
                'source'          => SourceType::INTERNAL,
                'type'            => 'machine.start',
                'payload'         => ['data' => 'initial_data'],
                'context'         => ['step' => 1],
                'meta'            => ['debug' => 'start'],
                'version'         => 1,
            ]),
            MachineEvent::create([
                'id'              => '01H8BM4VK82JKPK7RPR3YGT2DN',
                'sequence_number' => 2,
                'created_at'      => now(),
                'machine_id'      => $machineId,
                'machine_value'   => ['test_machine.processing'],
                'root_event_id'   => $rootEventId,
                'source'          => SourceType::INTERNAL,
                'type'            => 'machine.process',
                'payload'         => ['data' => 'process_data'],
                'context'         => ['step' => 2],
                'meta'            => ['debug' => 'processing'],
                'version'         => 1,
            ]),
        ]);

        $eventCollection = new EventCollection($events->all());

        // Archive the events
        $archive = MachineEventArchive::archiveEvents($eventCollection);
        expect($archive)->toBeInstanceOf(MachineEventArchive::class);

        // Delete original events (simulate they've been cleaned up)
        MachineEvent::where('root_event_id', $rootEventId)->delete();

        // Verify events are gone from active table
        expect(MachineEvent::where('root_event_id', $rootEventId)->count())->toBe(0);

        // Create a simple machine definition for testing
        $machineDefinition = MachineDefinition::define([
            'id'      => 'test_machine',
            'initial' => 'initial',
            'states'  => [
                'initial' => [
                    'on' => [
                        'machine.process' => 'processing',
                    ],
                ],
                'processing' => [
                    'type' => 'final',
                ],
            ],
        ]);

        $machine = Machine::withDefinition($machineDefinition);

        // This should transparently restore from archive
        $state = $machine->restoreStateFromRootEventId($rootEventId);

        expect($state)->toBeInstanceOf(\Tarfinlabs\EventMachine\Actor\State::class);
        expect($state->history)->toHaveCount(2);
        expect($state->history->first()->type)->toBe('machine.start');
        expect($state->history->last()->type)->toBe('machine.process');
        expect($state->history->first()->payload)->toEqual(['data' => 'initial_data']);
        expect($state->history->last()->payload)->toEqual(['data' => 'process_data']);
    });

    it('auto-restores archived events when new event is created', function (): void {
        $rootEventId = '01H8BM4VK82JKPK7RPR3YGT2DO';
        $machineId   = 'test_machine';

        // Create and archive some events
        $archivedEvents = collect([
            MachineEvent::create([
                'id'              => '01H8BM4VK82JKPK7RPR3YGT2DO',
                'sequence_number' => 1,
                'created_at'      => now()->subMinutes(10),
                'machine_id'      => $machineId,
                'machine_value'   => ['test_machine.initial'],
                'root_event_id'   => $rootEventId,
                'source'          => SourceType::INTERNAL,
                'type'            => 'old.event',
                'payload'         => ['data' => 'archived_data'],
                'context'         => ['step' => 1],
                'meta'            => ['debug' => 'archived'],
                'version'         => 1,
            ]),
        ]);

        MachineEventArchive::archiveEvents(new EventCollection($archivedEvents->all()));

        // Delete the original events
        MachineEvent::where('root_event_id', $rootEventId)->delete();

        // Verify archive exists, no active events
        expect(MachineEventArchive::where('root_event_id', $rootEventId)->exists())->toBeTrue();
        expect(MachineEvent::where('root_event_id', $rootEventId)->count())->toBe(0);

        // Create new event - this triggers auto-restore
        MachineEvent::create([
            'id'              => '01H8BM4VK82JKPK7RPR3YGT2DP',
            'sequence_number' => 2,
            'created_at'      => now(),
            'machine_id'      => $machineId,
            'machine_value'   => ['test_machine.processing'],
            'root_event_id'   => $rootEventId,
            'source'          => SourceType::INTERNAL,
            'type'            => 'new.event',
            'payload'         => ['data' => 'active_data'],
            'context'         => ['step' => 2],
            'meta'            => ['debug' => 'active'],
            'version'         => 1,
        ]);

        // Auto-restore should have merged all events and deleted archive
        expect(MachineEventArchive::where('root_event_id', $rootEventId)->exists())->toBeFalse();
        expect(MachineEvent::where('root_event_id', $rootEventId)->count())->toBe(2);

        $machineDefinition = MachineDefinition::define([
            'id'      => 'test_machine',
            'initial' => 'initial',
            'states'  => [
                'initial'    => ['on' => ['new.event' => 'processing']],
                'processing' => ['type' => 'final'],
            ],
        ]);

        $machine = Machine::withDefinition($machineDefinition);
        $state   = $machine->restoreStateFromRootEventId($rootEventId);

        // Should have both events (archived was restored + new)
        expect($state->history)->toHaveCount(2);
        expect($state->history->first()->type)->toBe('old.event');
        expect($state->history->first()->payload)->toEqual(['data' => 'archived_data']);
        expect($state->history->last()->type)->toBe('new.event');
        expect($state->history->last()->payload)->toEqual(['data' => 'active_data']);
    });

    it('throws exception when machine is not found in either table', function (): void {
        $rootEventId = '01H8BM4VK82JKPK7RPR3YGT2XX'; // Non-existent

        $machineDefinition = MachineDefinition::define([
            'id'      => 'test_machine',
            'initial' => 'initial',
            'states'  => [
                'initial' => ['type' => 'final'],
            ],
        ]);

        $machine = Machine::withDefinition($machineDefinition);

        expect(fn () => $machine->restoreStateFromRootEventId($rootEventId))
            ->toThrow(\Tarfinlabs\EventMachine\Exceptions\RestoringStateException::class, 'Machine state is not found.');
    });
});
