<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Jobs;

use Tarfinlabs\EventMachine\Contracts\ReturnsResult;

class DependencyInjectedTestJob implements ReturnsResult
{
    private string $serviceData = '';

    public function handle(ExternalServiceContract $service): void
    {
        $this->serviceData = $service->execute();
    }

    public function result(): array
    {
        return ['serviceData' => $this->serviceData];
    }
}
