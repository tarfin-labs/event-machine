<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Contexts;

use Spatie\LaravelData\Optional;
use Tarfinlabs\EventMachine\ContextManager;

class ForTestingRequiredContext extends ContextManager
{
    public function __construct(
        public string $required,
        public Optional|string $opt,
    ) {
        parent::__construct();
    }
}
