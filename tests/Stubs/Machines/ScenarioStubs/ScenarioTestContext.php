<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs;

use Tarfinlabs\EventMachine\ContextManager;

class ScenarioTestContext extends ContextManager
{
    public function __construct(
        public ?int $userId = 1,
        public bool $eligible = true,
        public bool $processed = false,
        public ?string $result = null,
    ) {
        parent::__construct();
    }
}
