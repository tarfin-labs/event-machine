<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint;

use Illuminate\Http\JsonResponse;
use Tarfinlabs\EventMachine\Routing\MachineEndpointAction;

/**
 * Parent-level MachineEndpointAction for forward lifecycle testing.
 * Tracks before/after/onException calls via static properties.
 */
class ForwardEndpointAction extends MachineEndpointAction
{
    public static bool $beforeCalled    = false;
    public static bool $afterCalled     = false;
    public static bool $exceptionCaught = false;

    public static function reset(): void
    {
        self::$beforeCalled    = false;
        self::$afterCalled     = false;
        self::$exceptionCaught = false;
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
        self::$exceptionCaught = true;

        return null;
    }
}
