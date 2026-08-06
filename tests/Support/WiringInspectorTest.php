<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Support;

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Support\WiringInspector;
use Tarfinlabs\EventMachine\Behavior\EventCollection;

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

class ShapeContext extends ContextManager {}
class ShapeChildContext extends ShapeContext {}
class ShapeOtherContext extends ContextManager {}

class ExactContextAction extends ActionBehavior
{
    public function __invoke(ShapeContext $context): void {}
}

class SupertypeContextAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void {}
}

class UnionMatchNotFirstAction extends ActionBehavior
{
    public function __invoke(ShapeOtherContext|ShapeContext $context): void {}
}

class UnionNoMatchAction extends ActionBehavior
{
    public function __invoke(ShapeOtherContext|ShapeChildContext $context): void {}
}

class UnionFirstNotContextAction extends ActionBehavior
{
    public function __invoke(State|ShapeOtherContext $state): void {}
}

class UnionWithNullAction extends ActionBehavior
{
    public function __invoke(?ShapeContext $context): void {}
}

class NoContextParamAction extends ActionBehavior
{
    public function __invoke(EventBehavior $event): void {}
}

class UntypedParamAction extends ActionBehavior
{
    public function __invoke($anything): void {}
}

class NonContextClassAction extends ActionBehavior
{
    public function __invoke(EventCollection $history): void {}
}

class DefaultValueAction extends ActionBehavior
{
    public function __invoke(?ShapeContext $context = null): void {}
}

class NoInvokeAction extends ActionBehavior {}

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

class DeclaredKeyAction extends ActionBehavior
{
    public static array $requiredContext = ['known' => 'string'];

    public function __invoke(): void {}
}

class MissingKeyAction extends ActionBehavior
{
    public static array $requiredContext = ['absent' => 'string'];

    public function __invoke(): void {}
}

class ListFormAction extends ActionBehavior
{
    public static array $requiredContext = ['string', 'int'];

    public function __invoke(): void {}
}

class DottedKeyAction extends ActionBehavior
{
    public static array $requiredContext = ['known.nested.deep' => 'string'];

    public function __invoke(): void {}
}

class DottedMissingRootAction extends ActionBehavior
{
    public static array $requiredContext = ['absent.nested' => 'string'];

    public function __invoke(): void {}
}

class KeyedContext extends ContextManager
{
    public ?string $known = null;
}

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

class SubmitEvent extends EventBehavior {}

class Submit extends EventBehavior {}

class ApproveEvent extends EventBehavior {}

class RenamedEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'SOMETHING_ELSE';
    }
}

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
