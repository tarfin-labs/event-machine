<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Outputs\FormatOutput;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\SetLevelAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Guards\IsAmountInRangeGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Guards\IsAboveThresholdGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\AddValueByParamAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\MultiplyByParamAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Calculators\ApplyDiscountCalculator;

class NamedParamsMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'named_params',
                'initial' => 'idle',
                'context' => [
                    'amount' => 0,
                    'total'  => 100,
                    'level'  => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => [
                            'CHECK_RANGE' => [
                                'target' => 'in_range',
                                'guards' => [[IsAmountInRangeGuard::class, 'min' => 10, 'max' => 1000]],
                            ],
                            'CHECK_THRESHOLD' => [
                                'target' => 'above_threshold',
                                'guards' => [[IsAboveThresholdGuard::class, 'threshold' => 50]],
                            ],
                            'ADD_VALUE' => [
                                'actions' => [[AddValueByParamAction::class, 'value' => 25]],
                            ],
                            'MULTIPLY' => [
                                'actions' => [[MultiplyByParamAction::class, 'factor' => 3]],
                            ],
                            'SET_LEVEL' => [
                                'actions' => [[SetLevelAction::class, 'level' => 'info']],
                            ],
                            'APPLY_DISCOUNT' => [
                                'target'      => 'discounted',
                                'calculators' => [[ApplyDiscountCalculator::class, 'rate' => 0.15]],
                                'guards'      => [[IsAboveThresholdGuard::class, 'threshold' => 0]],
                            ],
                            'FINISH' => 'completed',
                        ],
                    ],
                    'in_range'        => ['on' => ['RESET' => 'idle']],
                    'above_threshold' => ['on' => ['RESET' => 'idle']],
                    'discounted'      => ['on' => ['RESET' => 'idle']],
                    'completed'       => [
                        'type'   => 'final',
                        'output' => [[FormatOutput::class, 'format' => 'json']],
                    ],
                ],
            ],
        );
    }
}
