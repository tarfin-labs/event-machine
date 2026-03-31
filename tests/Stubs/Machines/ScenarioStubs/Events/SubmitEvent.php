<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Events;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class SubmitEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'SUBMIT';
    }

    public static function rules(): array
    {
        return [
            'payload.amount' => ['required', 'integer', 'min:1'],
            'payload.note'   => ['nullable', 'string'],
        ];
    }
}
