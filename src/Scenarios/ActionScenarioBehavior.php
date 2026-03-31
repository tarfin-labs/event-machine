<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Scenarios;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

/**
 * Base class for scenario action overrides.
 * Type-compatible with ActionBehavior — can replace the original in the container.
 */
abstract class ActionScenarioBehavior extends ActionBehavior {}
