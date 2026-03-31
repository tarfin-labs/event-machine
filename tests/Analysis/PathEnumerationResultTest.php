<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Analysis\PathStep;
use Tarfinlabs\EventMachine\Analysis\PathType;
use Tarfinlabs\EventMachine\Analysis\MachinePath;
use Tarfinlabs\EventMachine\Analysis\PathEnumerationResult;

function makePath(PathType $type, string $terminal = 'end'): MachinePath
{
    return new MachinePath(
        steps: [new PathStep(stateId: "m.{$terminal}", stateKey: $terminal)],
        type: $type,
        terminalStateId: "m.{$terminal}",
    );
}

test('filter methods return correct paths by type', function (): void {
    $result = new PathEnumerationResult(paths: [
        makePath(PathType::HAPPY, 'completed'),
        makePath(PathType::HAPPY, 'approved'),
        makePath(PathType::FAIL, 'failed'),
        makePath(PathType::TIMEOUT, 'expired'),
        makePath(PathType::LOOP, 'loop'),
        makePath(PathType::GUARD_BLOCK, 'blocked'),
        makePath(PathType::DEAD_END, 'stuck'),
    ]);

    expect($result->happyPaths())->toHaveCount(2)
        ->and($result->failPaths())->toHaveCount(1)
        ->and($result->timeoutPaths())->toHaveCount(1)
        ->and($result->loopPaths())->toHaveCount(1)
        ->and($result->guardBlockPaths())->toHaveCount(1)
        ->and($result->deadEndPaths())->toHaveCount(1);
});

test('empty result returns empty arrays for all filters', function (): void {
    $result = new PathEnumerationResult();

    expect($result->happyPaths())->toBe([])
        ->and($result->failPaths())->toBe([])
        ->and($result->paths)->toBe([])
        ->and($result->parallelGroups)->toBe([]);
});
