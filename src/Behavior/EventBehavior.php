<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Tarfinlabs\EventMachine\Definition\SourceType;
use Spatie\LaravelData\Attributes\WithoutValidation;

abstract class EventBehavior extends Data
{
    public function __construct(
        public null|string|Optional $type = null,
        public null|array|Optional $payload = null,
        public int|Optional $version = 1,

        #[WithoutValidation]
        public SourceType $source = SourceType::EXTERNAL,
    ) {
        if ($this->type === null) {
            $this->type = static::getType();
        }
    }

    abstract public static function getType(): string;
}
