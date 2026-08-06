<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\WiringShapes;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class ListFormAction extends ActionBehavior
{
    /** @var array<array-key, string> */
    public static array $requiredContext = ['string', 'int'];

    public function __invoke(): void {}
}
