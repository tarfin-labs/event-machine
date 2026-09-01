<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Analysis\StateClassification;

test('classifications the scenario supplies nothing for weigh 0', function (): void {
    expect(StateClassification::TRANSIENT->weight())->toBe(0)
        ->and(StateClassification::COMPOUND->weight())->toBe(0)
        ->and(StateClassification::FINAL->weight())->toBe(0);
});

test('classifications the scenario must drive carry a price', function (): void {
    expect(StateClassification::INTERACTIVE->weight())->toBe(1)
        ->and(StateClassification::DELEGATION->weight())->toBe(3)
        ->and(StateClassification::PARALLEL->weight())->toBe(5);
});

test('every classification is priced, so a new case cannot default to 0', function (): void {
    // The match in weight() carries no default arm. A seventh case added without a weight
    // raises UnhandledMatchError here rather than pricing itself at 0 in silence — this
    // test is what makes that failure land on the change that introduced it.
    foreach (StateClassification::cases() as $classification) {
        expect($classification->weight())->toBeInt();
    }
});
