<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Jobs;

use Tarfinlabs\EventMachine\Contracts\ReturnsResult;

class DependencyInjectedTestJob implements ReturnsResult
{
    private string $serviceResult = '';

    public function handle(ExternalServiceContract $service): void
    {
        $this->serviceResult = $service->execute();
    }

    public function result(): array
    {
        return ['service_result' => $this->serviceResult];
    }
}
