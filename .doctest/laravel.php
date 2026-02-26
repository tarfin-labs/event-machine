<?php

declare(strict_types=1);

use Orchestra\Testbench\Foundation\Application;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Tarfinlabs\EventMachine\MachineServiceProvider;

$app = Application::create(
    options: [
        'extra' => [
            'providers' => [
                MachineServiceProvider::class,
                LaravelDataServiceProvider::class,
            ],
        ],
    ],
);

config()->set('database.default', 'testing');
config()->set('cache.default', 'array');
