<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Qwerty\Events\EEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Qwerty\Events\QEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Qwerty\Events\REvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Qwerty\QwertyMachine;

test('An actor can be defined to an event', function (): void {
    $machine = QwertyMachine::create();

    Log::shouldReceive('debug')->with('Q Event Even Actor')->once();
    Log::shouldReceive('debug')->with('Q Event Odd Actor')->once();

    $machine->send(event: new QEvent);

    Log::shouldReceive('debug')->with(null)->once();
    $machine->send(event: new EEvent);

    Log::shouldReceive('debug')->with('R Actor')->times(3);
    $machine->send(event: new REvent);
});
