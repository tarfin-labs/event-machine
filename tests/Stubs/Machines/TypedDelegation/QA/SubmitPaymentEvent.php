<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation\QA;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class SubmitPaymentEvent extends EventBehavior
{
    public static function rules(): array
    {
        return [];
    }
}
