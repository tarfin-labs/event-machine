<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Analysis\PathType;
use Tarfinlabs\EventMachine\Analysis\PathEnumerator;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AbcMachine;

test('AbcMachine enumerates 1 DEAD_END path', function (): void {
    $definition = AbcMachine::definition();
    $enumerator = new PathEnumerator($definition);
    $result     = $enumerator->enumerate();

    // AbcMachine: initial=state_b → @always(unguarded) → state_c (no transitions, not FINAL)
    expect($result->paths)->toHaveCount(1)
        ->and($result->deadEndPaths())->toHaveCount(1)
        ->and($result->paths[0]->type)->toBe(PathType::DEAD_END);
});
