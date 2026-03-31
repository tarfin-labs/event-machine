<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Scenarios;

use Tarfinlabs\EventMachine\Behavior\GuardBehavior;

/**
 * Base class for scenario guard overrides.
 * Type-compatible with GuardBehavior — can replace the original in the container.
 */
abstract class GuardScenarioBehavior extends GuardBehavior {}
