<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\WiringShapes;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class DottedMissingRootAction extends ActionBehavior
{
    /** @var array<array-key, string> */
    public static array $requiredContext = ['absent.nested' => 'string'];

    public function __invoke(): void {}
}
