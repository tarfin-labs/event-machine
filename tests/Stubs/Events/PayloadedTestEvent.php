<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Events;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class PayloadedTestEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'PAYLOADED_TEST';
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'payload.decision' => ['required', 'in:approved,rejected'],
        ];
    }
}
