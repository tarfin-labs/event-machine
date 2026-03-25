<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysEventPreservation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Machine with a 3-state @always chain for testing event preservation.
 *
 * Flow: idle → (SUBMIT) → routing(@always) → eligibility(@always) → verification
 *
 * Guards, actions, and calculators on @always transitions should receive
 * the original SUBMIT event, not the synthetic @always event.
 */
class AlwaysChainMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'always_chain',
                'initial' => 'idle',
                'context' => [
                    'capturedEventType'   => null,
                    'capturedPayload'     => null,
                    'capturedActor'       => null,
                    'guardEventType'      => null,
                    'calculatorPayload'   => null,
                    'entryEventType'      => null,
                    'currentBehaviorType' => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => [
                            'SUBMIT' => 'routing',
                        ],
                    ],
                    'routing' => [
                        'on' => [
                            '@always' => [
                                'target'      => 'eligibility',
                                'guards'      => 'captureGuardEventAction',
                                'calculators' => 'captureCalculatorPayloadAction',
                                'actions'     => 'captureEventAction',
                            ],
                        ],
                    ],
                    'eligibility' => [
                        'entry' => 'captureEntryEventAction',
                        'on'    => [
                            '@always' => [
                                'target'  => 'verification',
                                'actions' => 'captureCurrentEventBehaviorAction',
                            ],
                        ],
                    ],
                    'verification' => [],
                ],
            ],
            behavior: [
                'actions' => [
                    'captureEventAction'                => CaptureEventAction::class,
                    'captureEntryEventAction'           => CaptureEntryEventAction::class,
                    'captureCurrentEventBehaviorAction' => CaptureCurrentEventBehaviorAction::class,
                ],
                'guards' => [
                    'captureGuardEventAction' => CaptureGuardEventGuard::class,
                ],
                'calculators' => [
                    'captureCalculatorPayloadAction' => CaptureCalculatorPayloadCalculator::class,
                ],
            ],
        );
    }
}
