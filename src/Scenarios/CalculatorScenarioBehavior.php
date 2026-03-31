<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Scenarios;

use Tarfinlabs\EventMachine\Behavior\CalculatorBehavior;

/**
 * Base class for scenario calculator overrides.
 * Type-compatible with CalculatorBehavior — can replace the original in the container.
 */
abstract class CalculatorScenarioBehavior extends CalculatorBehavior {}
