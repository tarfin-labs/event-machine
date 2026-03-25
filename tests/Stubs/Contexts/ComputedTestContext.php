<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Contexts;

use Tarfinlabs\EventMachine\ContextManager;

class ComputedTestContext extends ContextManager
{
    public function __construct(
        public int $count = 0,
        public string $status = 'active',
    ) {
        parent::__construct();
    }

    protected function computedContext(): array
    {
        return [
            'isCountEven'  => $this->count % 2 === 0,
            'displayLabel' => "Item #{$this->count} ({$this->status})",
        ];
    }
}
