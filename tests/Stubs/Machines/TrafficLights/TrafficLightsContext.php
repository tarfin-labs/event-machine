<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Tests\Stubs\Models\ModelA;

class TrafficLightsContext extends ContextManager
{
    public function __construct(
        public int $count = 0,
        public ?ModelA $modelA = null,
    ) {}

    public static function rules(): array
    {
        return [
            'count' => ['integer', 'min:0'],
        ];
    }

    public function isCountEven(): bool
    {
        return $this->count % 2 === 0;
    }
}
