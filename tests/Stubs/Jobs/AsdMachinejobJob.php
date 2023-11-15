<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\AsdMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\Events\EEvent;

class AsdMachinejobJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private string $rootEventId, private int $number)
    {
    }

    /**
     * @throws \Exception
     */
    public function handle(): void
    {
        $machine = AsdMachine::create(state: $this->rootEventId);

        $machine->send(new EEvent());
    }
}
