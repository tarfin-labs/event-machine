<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Failures;

use Throwable;
use Tarfinlabs\EventMachine\Behavior\MachineFailure;

/**
 * Tests custom fromException override for domain-specific mapping.
 */
class CustomMappingFailure extends MachineFailure
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $message,
        public readonly ?string $gatewayResponse = null,
    ) {}

    public static function fromException(Throwable $e): static
    {
        return new static(
            errorCode: $e->getCode() !== 0 ? (string) $e->getCode() : 'UNKNOWN',
            message: $e->getMessage(),
            gatewayResponse: method_exists($e, 'getResponse') ? $e->getResponse() : null,
        );
    }
}
