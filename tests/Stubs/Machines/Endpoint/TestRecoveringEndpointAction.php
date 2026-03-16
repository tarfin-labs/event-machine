<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint;

use Illuminate\Http\JsonResponse;
use Tarfinlabs\EventMachine\Routing\MachineEndpointAction;

/**
 * Endpoint action that recovers from exceptions by returning a custom response.
 */
class TestRecoveringEndpointAction extends MachineEndpointAction
{
    public static bool $exceptionCaught = false;

    public static function reset(): void
    {
        self::$exceptionCaught = false;
    }

    public function onException(\Throwable $e): ?JsonResponse
    {
        self::$exceptionCaught = true;

        return response()->json([
            'error'   => 'handled',
            'message' => $e->getMessage(),
        ], 503);
    }
}
