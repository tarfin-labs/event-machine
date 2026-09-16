<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Inputs;

use DateTimeImmutable;
use Tarfinlabs\EventMachine\Behavior\MachineInput;

/**
 * Exists to exercise every arm of the XState export's type → default-value table.
 * PaymentInput only covers string, int and "has a default"; the remaining shapes
 * (bool, array, float, a union, an unrecognised class type) live here so the
 * export's defaults stay pinned rather than being whatever the last edit produced.
 */
class ContractShapeInput extends MachineInput
{
    public function __construct(
        public readonly bool $isExpress,
        public readonly array $lineItems,
        public readonly float $discountRate,
        public readonly int|string $reference,
        public readonly DateTimeImmutable $placedAt,
    ) {}
}
