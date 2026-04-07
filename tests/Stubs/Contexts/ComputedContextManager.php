<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Contexts;

use Tarfinlabs\EventMachine\ContextManager;

class ComputedContextManager extends ContextManager
{
    public function __construct(
        public int $subtotal = 0,
        public int $tax = 0,
    ) {
        parent::__construct();
    }

    /**
     * @return array<string, mixed>
     */
    protected function computedContext(): array
    {
        return ['total' => $this->subtotal + $this->tax];
    }
}
