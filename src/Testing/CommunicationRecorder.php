<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Testing;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

/**
 * Static recorder for cross-machine communication during tests.
 *
 * Opt-in via TestMachine::recordingCommunication(). When recording is active,
 * InvokableBehavior::sendTo() skips the actual send and records the call.
 * InvokableBehavior::raise() records the call but still pushes to the event queue.
 */
class CommunicationRecorder
{
    /** @var list<array{machineClass: string, rootEventId: string, event: EventBehavior|array<string, mixed>}> */
    private static array $sendToRecords = [];

    /** @var list<array{event: EventBehavior|array<string, mixed>}> */
    private static array $raiseRecords = [];

    private static bool $recording = false;

    public static function startRecording(): void
    {
        self::$recording = true;
    }

    public static function stopRecording(): void
    {
        self::$recording = false;
    }

    public static function isRecording(): bool
    {
        return self::$recording;
    }

    /**
     * @param  EventBehavior|array<string, mixed>  $event
     */
    public static function recordSendTo(string $machineClass, string $rootEventId, EventBehavior|array $event): void
    {
        self::$sendToRecords[] = [
            'machineClass' => $machineClass,
            'rootEventId'  => $rootEventId,
            'event'        => $event,
        ];
    }

    /**
     * @param  EventBehavior|array<string, mixed>  $event
     */
    public static function recordRaise(EventBehavior|array $event): void
    {
        self::$raiseRecords[] = [
            'event' => $event,
        ];
    }

    /**
     * @return list<array{machineClass: string, rootEventId: string, event: EventBehavior|array<string, mixed>}>
     */
    public static function getSendToRecords(?string $machineClass = null): array
    {
        if ($machineClass === null) {
            return self::$sendToRecords;
        }

        return array_values(array_filter(
            self::$sendToRecords,
            fn (array $record): bool => $record['machineClass'] === $machineClass,
        ));
    }

    /**
     * @return list<array{event: EventBehavior|array<string, mixed>}>
     */
    public static function getRaiseRecords(?string $eventType = null): array
    {
        if ($eventType === null) {
            return self::$raiseRecords;
        }

        return array_values(array_filter(
            self::$raiseRecords,
            function (array $record) use ($eventType): bool {
                $type = is_array($record['event'])
                    ? ($record['event']['type'] ?? null)
                    : $record['event']->type;

                return $type === $eventType;
            },
        ));
    }

    public static function reset(): void
    {
        self::$sendToRecords = [];
        self::$raiseRecords  = [];
        self::$recording     = false;
    }
}
