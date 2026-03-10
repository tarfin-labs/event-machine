<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint;

use Illuminate\Http\JsonResponse;
use Tarfinlabs\EventMachine\Routing\MachineEndpointAction;

class TestEndpointAction extends MachineEndpointAction
{
    public static bool $beforeCalled         = false;
    public static bool $afterCalled          = false;
    public static ?\Throwable $lastException = null;

    public static function reset(): void
    {
        self::$beforeCalled  = false;
        self::$afterCalled   = false;
        self::$lastException = null;
    }

    public function before(): void
    {
        self::$beforeCalled = true;
    }

    public function after(): void
    {
        self::$afterCalled = true;
    }

    public function onException(\Throwable $e): ?JsonResponse
    {
        self::$lastException = $e;

        return null;
    }
}
