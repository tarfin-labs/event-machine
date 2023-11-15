<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Tarfinlabs\EventMachine\Actor\Machine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\Events\EEvent;

class AsdMachinejobJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Machine $asdMachine)
    {
    }

    public function handle(): void
    {
        $this->asdMachine->send(new EEvent());
    }
}
