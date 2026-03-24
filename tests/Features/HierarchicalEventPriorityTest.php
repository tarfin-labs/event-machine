<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Compound\HierarchicalEventPriorityMachine;

// ============================================================
// Hierarchical event priority: child state wins over parent
// ============================================================

it('child state handles event with priority over parent state', function (): void {
    // Machine starts at form.editing (the initial child of 'form').
    // Both form.editing and form define SUBMIT, but child takes priority.
    HierarchicalEventPriorityMachine::test()
        ->assertState('form.editing')
        ->send('SUBMIT')
        ->assertState('form.validating');
});

it('parent state handles event when child state does not define it', function (): void {
    // Machine is placed at form.waiting which does NOT handle SUBMIT.
    // The event bubbles up to parent 'form', which routes to 'review'.
    HierarchicalEventPriorityMachine::startingAt('hierarchical_event_priority.form.waiting')
        ->assertState('form.waiting')
        ->send('SUBMIT')
        ->assertState('review');
});
