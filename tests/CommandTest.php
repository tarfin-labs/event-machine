<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Commands\GenerateUmlCommand;

it('it has GenerateUmlCommand', function (): void {
    $this->assertTrue(class_exists(GenerateUmlCommand::class));
});
