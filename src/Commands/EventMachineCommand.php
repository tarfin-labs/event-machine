<?php

namespace Tarfinlabs\EventMachine\Commands;

use Illuminate\Console\Command;

class EventMachineCommand extends Command
{
    public $signature = 'event-machine';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
