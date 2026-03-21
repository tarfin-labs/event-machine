<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Jobs;

class FakeExternalService implements ExternalServiceContract
{
    public function execute(): string
    {
        return 'fake-result';
    }
}
