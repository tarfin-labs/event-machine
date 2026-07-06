<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Contexts;

use Spatie\LaravelData\Optional;
use Tarfinlabs\EventMachine\ContextManager;

class ForTestingBareOptionalContext extends ContextManager
{
    public function __construct(
        public Optional $pure,
    ) {
        parent::__construct();
    }
}
