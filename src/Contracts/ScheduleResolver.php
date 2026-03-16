<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Contracts;

use Illuminate\Support\Collection;

/**
 * Resolves which machine instances should receive a scheduled event.
 *
 * A resolver returns a Collection of root_event_ids by querying
 * the application's model layer. EventMachine receives only IDs —
 * zero model awareness needed.
 *
 * The resolver owns the model knowledge: table, column name,
 * business conditions. Different models use different machine
 * columns (application_mre, order_mre, etc.).
 *
 * Resolvers are container-resolved, supporting constructor DI.
 */
interface ScheduleResolver
{
    /**
     * @return Collection<int, string> Root event IDs of machine instances to receive the event
     */
    public function __invoke(): Collection;
}
