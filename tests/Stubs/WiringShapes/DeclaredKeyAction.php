<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\WiringShapes;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class DeclaredKeyAction extends ActionBehavior
{
    /** @var array<array-key, string> */
    public static array $requiredContext = ['known' => 'string'];

    public function __invoke(): void {}
}
