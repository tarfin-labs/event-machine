<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Fixtures\ScenarioDrift;

use RuntimeException;
use Tarfinlabs\EventMachine\Scenarios\MachineScenario;

/**
 * Hydration itself throws — the shape a scenario with a broken params() definition has.
 *
 * The class resolves and is a scenario, so the restore path accepts it and then fails inside.
 * Before the guard that reached the caller as a 500 no request could clear.
 */
class ThrowsWhenHydratedScenario extends MachineScenario
{
    protected string $machine     = PersistingScenarioMachine::class;
    protected string $source      = 'idle';
    protected string $event       = 'GO';
    protected string $target      = 'done';
    protected string $description = 'Throws while being hydrated';

    /**
     * @param  array<string, mixed>  $rawParams
     */
    public function hydrateParams(array $rawParams, bool $validate = true): void
    {
        throw new RuntimeException('hydration blew up');
    }

    protected function plan(): array
    {
        return [];
    }
}
