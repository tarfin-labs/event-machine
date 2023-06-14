<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Events;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class ValidatedEvent extends EventBehavior
{
    public static function rules(ValidationContext $context): array
    {
        return [
            'payload.attribute' => ['required', 'integer', 'min:1', 'max:10'],
        ];
    }

    public static function messages(...$args): array
    {
        return [
            'payload.attribute' => 'Custom validation message for the attribute.',
        ];
    }

    public static function getType(): string
    {
        return 'VALIDATED_EVENT';
    }
}
