<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights;

use Spatie\LaravelData\Optional;
use Tarfinlabs\EventMachine\ContextManager;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\IntegerType;

class TrafficLightsContext extends ContextManager
{
    public function __construct(
        #[IntegerType]
        #[Min(0)]
        public int|Optional $count = 1,
    ) {
        parent::__construct();
    }

    public function isCountEven(): bool
    {
        return $this->count % 2 === 0;
    }
}
