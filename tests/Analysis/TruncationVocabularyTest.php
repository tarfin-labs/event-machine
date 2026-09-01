<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Scenarios\ScenarioValidator;
use Tarfinlabs\EventMachine\Analysis\ScenarioPathResolver;
use Tarfinlabs\EventMachine\Commands\MachineScenarioCommand;
use Tarfinlabs\EventMachine\Commands\MachineScenarioValidateCommand;
use Tarfinlabs\EventMachine\Exceptions\NoScenarioPathFoundException;

/**
 * Truncation used to mean "everything within the hop-count frontier the cap reached". It now
 * means "everything within the cost frontier". Any operator-facing text still describing the
 * search in the old vocabulary would be wrong, and none of it is asserted anywhere else — so
 * the check that used to be a reading is an assertion instead.
 */
function truncationSources(): array
{
    return [
        'NoScenarioPathFoundException' => (new ReflectionClass(NoScenarioPathFoundException::class))->getFileName(),
        'MachineScenarioCommand'       => (new ReflectionClass(MachineScenarioCommand::class))->getFileName(),
        'ScenarioValidator'            => (new ReflectionClass(ScenarioValidator::class))->getFileName(),
        'MachineScenarioValidate'      => (new ReflectionClass(MachineScenarioValidateCommand::class))->getFileName(),
        'ScenarioPathResolver'         => (new ReflectionClass(ScenarioPathResolver::class))->getFileName(),
    ];
}

test('no truncation-rendering site describes the old search order', function (): void {
    foreach (truncationSources() as $label => $file) {
        expect($file)->toBeString();

        $source = file_get_contents((string) $file);

        expect($source)->toBeString();

        // Matched on whole words so that unrelated identifiers cannot trip the check.
        foreach (['hop', 'breadth', 'shortest', 'BFS'] as $stale) {
            expect(preg_match('/\b'.preg_quote($stale, '/').'\b/i', (string) $source))
                ->toBe(0, "{$label} still describes the search as '{$stale}'");
        }
    }
});

test('the exception still says a truncated search is not a finding of absence', function (): void {
    $exception = NoScenarioPathFoundException::truncated('a', 'b', 'SomeMachine');

    // The distinction the message exists to draw survives the change in what truncation cuts by.
    expect($exception->getMessage())->toContain('truncated at the search limit')
        ->and($exception->getMessage())->toContain('A path may still exist');
});
