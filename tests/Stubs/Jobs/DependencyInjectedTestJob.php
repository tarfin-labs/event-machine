<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Jobs;

use Tarfinlabs\EventMachine\Contracts\ReturnsOutput;

class DependencyInjectedTestJob implements ReturnsOutput
{
    private string $serviceData = '';

    public function handle(ExternalServiceContract $service): void
    {
        $this->serviceData = $service->execute();
    }

    public function output(): array
    {
        return ['serviceData' => $this->serviceData];
    }
}
