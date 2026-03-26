<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\GetEndpoint;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class StatusRequestedEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'STATUS_REQUESTED';
    }

    public static function rules(): array
    {
        return [
            'payload.dealer_code'  => ['required', 'string', 'min:3'],
            'payload.plate_number' => ['required', 'string'],
        ];
    }
}
