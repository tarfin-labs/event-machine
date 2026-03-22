<?php

declare(strict_types=1);

use Orchestra\Testbench\Foundation\Application;
use Tarfinlabs\EventMachine\MachineServiceProvider;

$app = Application::create(
    options: [
        'extra' => [
            'providers' => [
                MachineServiceProvider::class,
            ],
        ],
    ],
);

config()->set('database.default', 'testing');
config()->set('cache.default', 'array');
