<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class ConfirmPaymentEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'CONFIRM_PAYMENT';
    }

    public static function rules(): array
    {
        return [
            'payload.confirmationCode' => ['required', 'string'],
        ];
    }
}
