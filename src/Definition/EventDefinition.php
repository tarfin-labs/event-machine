<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Definition;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class EventDefinition extends Data
{
    public function __construct(
        public string $type,
        public array|Optional $data,
    ) {
    }
}
