<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Jobs;

use Tarfinlabs\EventMachine\Contracts\ReturnsOutput;

class ProcessJob implements ReturnsOutput
{
    public function __construct(
        public readonly ?int $userId = null,
    ) {}

    public function handle(): void
    {
        // No-op for testing
    }

    public function output(): array
    {
        return [
            'processedBy' => 'process_job',
            'userId'      => $this->userId,
        ];
    }
}
