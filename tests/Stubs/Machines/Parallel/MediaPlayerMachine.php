<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * A simple parallel state machine with two independent regions:
 * - track: playing/paused
 * - volume: unmuted/muted
 */
class MediaPlayerMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'mediaPlayer',
                'initial' => 'active',
                'states'  => [
                    'active' => [
                        'type'   => 'parallel',
                        'states' => [
                            'track' => [
                                'initial' => 'paused',
                                'states'  => [
                                    'paused' => [
                                        'on' => [
                                            'PLAY' => 'playing',
                                        ],
                                    ],
                                    'playing' => [
                                        'on' => [
                                            'PAUSE' => 'paused',
                                            'STOP'  => 'stopped',
                                        ],
                                    ],
                                    'stopped' => [
                                        'type' => 'final',
                                    ],
                                ],
                            ],
                            'volume' => [
                                'initial' => 'unmuted',
                                'states'  => [
                                    'unmuted' => [
                                        'on' => [
                                            'MUTE' => 'muted',
                                        ],
                                    ],
                                    'muted' => [
                                        'on' => [
                                            'UNMUTE' => 'unmuted',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        );
    }
}
