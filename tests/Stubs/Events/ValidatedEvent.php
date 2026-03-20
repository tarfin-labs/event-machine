<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Events;

use Tarfinlabs\EventMachine\Testing\HasBuilder;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Tarfinlabs\EventMachine\Tests\Stubs\Builders\ValidatedEventBuilder;

/**
 * @use HasBuilder<ValidatedEventBuilder>
 */
class ValidatedEvent extends EventBehavior
{
    use HasBuilder;

    public static function rules(ValidationContext $context): array
    {
        return [
            'payload.attribute' => ['required', 'integer', 'min:1', 'max:10'],
            'payload.value'     => ['nullable', 'integer', 'min:1', 'max:10'],
        ];
    }

    public static function messages(...$args): array
    {
        return [
            'payload.attribute' => 'Custom validation message for the attribute.',
            'payload.value'     => 'Custom validation message for the value.',
        ];
    }

    public static function getType(): string
    {
        return 'VALIDATED_EVENT';
    }

    protected static function resolveBuilderClass(): string
    {
        return ValidatedEventBuilder::class;
    }
}
