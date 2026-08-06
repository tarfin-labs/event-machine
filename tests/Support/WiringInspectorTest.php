<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Support;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Support\WiringInspector;
use Tarfinlabs\EventMachine\Tests\Stubs\WiringShapes\Submit;
use Tarfinlabs\EventMachine\Tests\Stubs\WiringShapes\SubmitEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\WiringShapes\ApproveEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\WiringShapes\KeyedContext;
use Tarfinlabs\EventMachine\Tests\Stubs\WiringShapes\RenamedEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\WiringShapes\ShapeContext;
use Tarfinlabs\EventMachine\Tests\Stubs\WiringShapes\ListFormAction;
use Tarfinlabs\EventMachine\Tests\Stubs\WiringShapes\NoInvokeAction;
use Tarfinlabs\EventMachine\Tests\Stubs\WiringShapes\DottedKeyAction;
use Tarfinlabs\EventMachine\Tests\Stubs\WiringShapes\MissingKeyAction;
use Tarfinlabs\EventMachine\Tests\Stubs\WiringShapes\DeclaredKeyAction;
use Tarfinlabs\EventMachine\Tests\Stubs\WiringShapes\ShapeChildContext;
use Tarfinlabs\EventMachine\Tests\Stubs\WiringShapes\ShapeOtherContext;
use Tarfinlabs\EventMachine\Tests\Stubs\WiringShapes\DefaultValueAction;
use Tarfinlabs\EventMachine\Tests\Stubs\WiringShapes\ExactContextAction;
use Tarfinlabs\EventMachine\Tests\Stubs\WiringShapes\UnionNoMatchAction;
use Tarfinlabs\EventMachine\Tests\Stubs\WiringShapes\UntypedParamAction;
use Tarfinlabs\EventMachine\Tests\Stubs\WiringShapes\UnionWithNullAction;
use Tarfinlabs\EventMachine\Tests\Stubs\WiringShapes\NoContextParamAction;
use Tarfinlabs\EventMachine\Tests\Stubs\WiringShapes\NonContextClassAction;
use Tarfinlabs\EventMachine\Tests\Stubs\WiringShapes\SupertypeContextAction;
use Tarfinlabs\EventMachine\Tests\Stubs\WiringShapes\DottedMissingRootAction;
use Tarfinlabs\EventMachine\Tests\Stubs\WiringShapes\UnionMatchNotFirstAction;
use Tarfinlabs\EventMachine\Tests\Stubs\WiringShapes\UnionFirstNotContextAction;

/*
|--------------------------------------------------------------------------
| Shape table
|--------------------------------------------------------------------------
|
| Every parameter shape the engine treats distinctly, each with its expected
| verdict. The verdicts are the point: a check that never reports anything would
| satisfy a containment-only table, so incompatible rows are asserted as loudly
| as compatible ones.
|
*/

dataset('context shapes', [
    'exact context type'              => [ExactContextAction::class, ShapeContext::class, null],
    'a supertype'                     => [SupertypeContextAction::class, ShapeContext::class, null],
    'union whose match is not first'  => [UnionMatchNotFirstAction::class, ShapeContext::class, null],
    'union with no matching member'   => [UnionNoMatchAction::class, ShapeContext::class, 'incompatible'],
    'union first member not context'  => [UnionFirstNotContextAction::class, ShapeContext::class, null],
    'union including null'            => [UnionWithNullAction::class, ShapeContext::class, null],
    'no context parameter'            => [NoContextParamAction::class, ShapeContext::class, null],
    'untyped parameter'               => [UntypedParamAction::class, ShapeContext::class, null],
    'typed with a non-context class'  => [NonContextClassAction::class, ShapeContext::class, null],
    'parameter with a default value'  => [DefaultValueAction::class, ShapeContext::class, null],
    'no __invoke at all'              => [NoInvokeAction::class, ShapeContext::class, null],
    'unrelated context'               => [ExactContextAction::class, ShapeOtherContext::class, 'incompatible'],
    'a subclass satisfies the parent' => [ExactContextAction::class, ShapeChildContext::class, null],
]);

it('reports the expected verdict for every parameter shape', function (
    string $behavior,
    string $context,
    ?string $expected,
): void {
    $result = WiringInspector::incompatibleContextTypes($behavior, $context);

    if ($expected === null) {
        expect($result)->toBeNull();

        return;
    }

    expect($result)->toBeArray()->not->toBeEmpty();
})->with('context shapes');

it('names the declared context types when incompatible', function (): void {
    expect(WiringInspector::incompatibleContextTypes(ExactContextAction::class, ShapeOtherContext::class))
        ->toEqual([ShapeContext::class]);
});

it('names every context member of an unsatisfiable union', function (): void {
    expect(WiringInspector::incompatibleContextTypes(UnionNoMatchAction::class, ShapeContext::class))
        ->toEqual([ShapeOtherContext::class, ShapeChildContext::class]);
});

it('reports the same behavior differently against two machines', function (): void {
    // The motivating case: one shared behavior, two machines, opposite verdicts.
    expect(WiringInspector::incompatibleContextTypes(ExactContextAction::class, ShapeContext::class))->toBeNull()
        ->and(WiringInspector::incompatibleContextTypes(ExactContextAction::class, ShapeOtherContext::class))->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| $requiredContext
|--------------------------------------------------------------------------
*/

it('reports a key a typed context class cannot supply', function (): void {
    // The positive verdict: without this row a never-reporting implementation passes.
    expect(WiringInspector::unsatisfiableRequiredContextKeys(MissingKeyAction::class, KeyedContext::class))
        ->toEqual(['absent']);
});

it('stays silent for a key the context declares', function (): void {
    expect(WiringInspector::unsatisfiableRequiredContextKeys(DeclaredKeyAction::class, KeyedContext::class))
        ->toBeEmpty();
});

it('skips the list form, which declares a type with no key to verify', function (): void {
    expect(WiringInspector::unsatisfiableRequiredContextKeys(ListFormAction::class, KeyedContext::class))
        ->toBeEmpty();
});

it('checks only the first segment of a dotted key', function (): void {
    // `known.nested.deep` addresses into a declared property; deeper segments are not
    // statically resolvable, so only the root is checked.
    expect(WiringInspector::unsatisfiableRequiredContextKeys(DottedKeyAction::class, KeyedContext::class))
        ->toBeEmpty()
        ->and(WiringInspector::unsatisfiableRequiredContextKeys(DottedMissingRootAction::class, KeyedContext::class))
        ->toEqual(['absent.nested']);
});

it('skips a machine whose declared context is the base ContextManager', function (): void {
    // The base class takes arbitrary keys through set(), so nothing here is decidable
    // and reporting would be a false failure.
    expect(WiringInspector::unsatisfiableRequiredContextKeys(MissingKeyAction::class, ContextManager::class))
        ->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Event type collisions
|--------------------------------------------------------------------------
*/

it('reports two differently-named classes that derive the same type', function (): void {
    // getType() takes everything before the LAST "Event", so these two different
    // basenames both derive SUBMIT. The old "same basename" framing missed this.
    expect(SubmitEvent::getType())->toBe(Submit::getType());

    $collisions = WiringInspector::eventTypeCollisions([SubmitEvent::class, Submit::class]);

    expect($collisions)->toHaveCount(1)
        ->and($collisions[0]['type'])->toBe('SUBMIT')
        ->and($collisions[0]['classes'])->toEqualCanonicalizing([SubmitEvent::class, Submit::class]);
});

it('compares a class that overrides the derivation on its overridden value', function (): void {
    expect(WiringInspector::eventTypeCollisions([RenamedEvent::class, ApproveEvent::class]))->toBeEmpty();
});

it('does not report the same class referenced repeatedly', function (): void {
    expect(WiringInspector::eventTypeCollisions([SubmitEvent::class, SubmitEvent::class, SubmitEvent::class]))
        ->toBeEmpty();
});

it('names the class that currently owns a colliding type', function (): void {
    $collisions = WiringInspector::eventTypeCollisions(
        [SubmitEvent::class, Submit::class],
        ['SUBMIT' => Submit::class],
    );

    expect($collisions[0]['owner'])->toBe(Submit::class);
});

it('says so when a colliding type has no owner in the registry', function (): void {
    $collisions = WiringInspector::eventTypeCollisions([SubmitEvent::class, Submit::class]);

    expect($collisions[0]['owner'])->toBeNull();
});
