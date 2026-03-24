<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Compound\ChildOverParentDocOrderMachine;

// ============================================================
// Combined: child-over-parent priority + document-order tiebreaker
// Apache Commons SCXML tie-breaker semantics
// ============================================================

it('child transition wins over parent guarded transitions', function (): void {
    // P.C handles TRIGGER -> Z (child), parent P has two guarded transitions
    // on TRIGGER targeting X and Y. Child must win.
    ChildOverParentDocOrderMachine::test()
        ->assertState('parent.child_with_handler')
        ->send('TRIGGER')
        ->assertState('parent.child_target');
});

it('parent first-match guard wins when child has no handler', function (): void {
    // P.D does NOT handle TRIGGER. Event bubbles to parent P,
    // which has two guarded transitions: first -> X, second -> Y.
    // Both guards pass, so first-match (X) wins by document order.
    ChildOverParentDocOrderMachine::startingAt('child_over_parent_doc_order.parent.child_without_handler')
        ->assertState('parent.child_without_handler')
        ->send('TRIGGER')
        ->assertState('parent_target_x');
});
