<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Events;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * An event with the same type string as EEvent but with stricter validation rules.
 * Used to test that the machine's own class is used for validation.
 */
class StrictEEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'E_EVENT';
    }

    public static function rules(ValidationContext $context): array
    {
        return [
            'payload.amount' => ['required', 'integer', 'min:1'],
        ];
    }

    public static function messages(...$args): array
    {
        return [
            'payload.amount.required' => 'The amount field is required.',
        ];
    }
}
