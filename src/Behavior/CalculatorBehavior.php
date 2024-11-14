<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

/**
 * Abstract base class for calculator behaviors.
 *
 * Calculators are responsible for performing computations and preparing data
 * before guards and actions are executed in a transition.
 */
abstract class CalculatorBehavior extends InvokableBehavior {}
