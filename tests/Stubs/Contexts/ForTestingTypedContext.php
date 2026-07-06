<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Contexts;

use Spatie\LaravelData\Optional;
use Tarfinlabs\EventMachine\ContextManager;

class ForTestingTypedContext extends ContextManager
{
    public function __construct(
        public Optional|string $name,
        public int|Optional $count,
        public ?string $note = null,
        public Optional|int $limit = 5,
    ) {
        parent::__construct();
    }
}
