<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Contracts;

/**
 * Interface ContextCast.
 *
 * Symmetric serialize/deserialize interface for context property casting.
 * Replaces Spatie's separate Cast + Transformer interfaces with a single contract.
 */
interface ContextCast
{
    /**
     * Object → scalar (DB'ye yazılacak form).
     */
    public function serialize(mixed $value): mixed;

    /**
     * Scalar → object (DB'den okunduğunda).
     */
    public function deserialize(mixed $value): mixed;
}
