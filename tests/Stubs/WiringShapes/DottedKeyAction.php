<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\WiringShapes;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class DottedKeyAction extends ActionBehavior
{
    /** @var array<array-key, string> */
    public static array $requiredContext = ['known.nested.deep' => 'string'];

    public function __invoke(): void {}
}
