<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\GetEndpoint;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class ChildStatusEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'CHILD_STATUS';
    }

    public static function rules(): array
    {
        return [
            'payload.child_param' => ['required', 'string'],
        ];
    }
}
