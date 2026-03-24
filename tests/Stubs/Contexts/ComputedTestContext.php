<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Contexts;

use Tarfinlabs\EventMachine\ContextManager;

class ComputedTestContext extends ContextManager
{
    public function __construct(
        public int $count = 0,
        public string $status = 'active',
    ) {}

    protected function computedContext(): array
    {
        return [
            'is_count_even' => $this->count % 2 === 0,
            'display_label' => "Item #{$this->count} ({$this->status})",
        ];
    }
}
