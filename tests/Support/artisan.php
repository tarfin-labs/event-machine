<?php

declare(strict_types=1);

/**
 * A self-contained artisan entry point for tests that must reach a command through a real shell.
 *
 * vendor/bin/testbench would do, except that it resolves the package's commands through package
 * discovery in the shared testbench skeleton, which other tests in a parallel run can be clearing
 * at the same moment. Registering the providers here leaves nothing shared to race on.
 *
 * Usage: php artisan.php <command> [options]
 */

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArgvInput;
use Orchestra\Testbench\Foundation\Application;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Symfony\Component\Console\Output\ConsoleOutput;
use Tarfinlabs\EventMachine\MachineServiceProvider;

require __DIR__.'/../../vendor/autoload.php';

$app = Application::create(options: [
    'extra' => [
        'providers'     => [MachineServiceProvider::class, LaravelDataServiceProvider::class],
        'dont-discover' => ['*'],
    ],
]);

$app['config']->set('queue.default', 'sync');

$kernel = $app->make(Kernel::class);
$input  = new ArgvInput();
$status = $kernel->handle($input, new ConsoleOutput());

$kernel->terminate($input, $status);

exit($status);
