<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights;

use Spatie\LaravelData\Optional;
use Tarfinlabs\EventMachine\ContextManager;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\WithTransformer;
use Tarfinlabs\EventMachine\Tests\Stubs\Models\ModelA;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Tarfinlabs\EventMachine\Transformers\ModelTransformer;

class TrafficLightsContext extends ContextManager
{
    public function __construct(
        #[IntegerType]
        #[Min(0)]
        public int|Optional $count,
        #[WithTransformer(ModelTransformer::class)]
        public Optional|ModelA|int $modelA,
    ) {
        parent::__construct();

        if ($this->count instanceof Optional) {
            $this->count = 0;
        }
    }

    public function isCountEven(): bool
    {
        return $this->count % 2 === 0;
    }
}
