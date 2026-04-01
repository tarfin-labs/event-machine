<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Scenarios;

use Tarfinlabs\EventMachine\Behavior\OutputBehavior;

/**
 * Base class for scenario output overrides.
 * Type-compatible with OutputBehavior — can replace the original in the container.
 */
abstract class OutputScenarioBehavior extends OutputBehavior {}
