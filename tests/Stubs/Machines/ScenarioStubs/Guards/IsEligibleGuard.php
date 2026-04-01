<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Guards;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\GuardBehavior;

class IsEligibleGuard extends GuardBehavior
{
    public static array $requiredContext = ['userId' => 'int'];

    public function __invoke(ContextManager $ctx): bool
    {
        return $ctx->get('eligible', true);
    }
}
