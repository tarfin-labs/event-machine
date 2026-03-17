<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class ProvideCardEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'PROVIDE_CARD';
    }

    public static function rules(): array
    {
        return [
            'payload.card_number' => ['required', 'string', 'min:13', 'max:19'],
        ];
    }
}
