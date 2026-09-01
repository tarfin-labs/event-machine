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
        // ContextManager::get() takes ONE argument, so the second one here was silently
        // discarded: with 'eligible' absent this returned null and tripped the bool return
        // type. `?? true` is the default the second argument was reaching for.
        return $ctx->get('eligible') ?? true;
    }
}
