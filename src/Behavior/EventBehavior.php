<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

abstract class EventBehavior extends Data
{
    public function __construct(
        public string $type,
        public array|Optional $data,
    ) {
    }

    abstract public static function getType(): string;

    public static function define(): ?array
    {
        return null;
    }
}
