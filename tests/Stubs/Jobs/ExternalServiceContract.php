<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Jobs;

interface ExternalServiceContract
{
    public function execute(): string;
}
