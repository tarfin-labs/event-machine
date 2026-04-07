<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Guards;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ValidationGuardBehavior;

class MinAmountValidationGuard extends ValidationGuardBehavior
{
    public ?string $errorMessage = 'Amount below minimum';

    public function __invoke(ContextManager $ctx, int $minimum): bool
    {
        return $ctx->get('amount') >= $minimum;
    }
}
