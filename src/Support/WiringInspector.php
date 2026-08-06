<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Support;

use ReflectionMethod;
use ReflectionException;
use ReflectionNamedType;
use ReflectionUnionType;
use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\InvokableBehavior;

/**
 * Static wiring checks for a behavior against the context a machine declares.
 *
 * The engine does not compute a verdict we could ask it for: parameter resolution
 * hands back the machine's context unconditionally, and the failure only happens when
 * the resolved arguments are passed to the behavior. So these checks re-derive the
 * rule from the parameter's declared type, and the test table is what keeps the
 * derivation honest.
 */
final class WiringInspector
{
    /**
     * Report the context parameter a behavior cannot accept from a machine.
     *
     * Returns null when the behavior is compatible, has no context parameter, or has
     * nothing to reflect. Returns the declared context types otherwise, so the caller
     * can name both sides of the mismatch.
     *
     * @param  class-string<InvokableBehavior>  $behaviorClass
     * @param  class-string<ContextManager>  $contextClass
     *
     * @return list<string>|null The declared context types, when incompatible.
     */
    public static function incompatibleContextTypes(string $behaviorClass, string $contextClass): ?array
    {
        $invoke = self::invokeMethod($behaviorClass);

        if (!$invoke instanceof ReflectionMethod) {
            return null;
        }

        foreach ($invoke->getParameters() as $parameter) {
            $declared = self::declaredTypeNames($parameter->getType());

            if ($declared === []) {
                continue;
            }

            // The engine reduces a union to its FIRST member and selects what to inject
            // from that name alone. A parameter is only the context parameter when that
            // reduced name is a context type; otherwise the engine injects something
            // else entirely and this parameter is none of our business.
            if (!self::isContextType($declared[0])) {
                continue;
            }

            // PHP then type-checks the argument against the FULL declared type, which is
            // why a union whose matching member is not first still works today.
            foreach ($declared as $name) {
                if (self::isContextType($name) && is_a($contextClass, $name, allow_string: true)) {
                    return null;
                }
            }

            return array_values(array_filter($declared, self::isContextType(...)));
        }

        return null;
    }

    /**
     * `$requiredContext` keys a typed context class cannot supply.
     *
     * The engine satisfies these against the live context instance — presence in its
     * data and the runtime type of the current value — so agreement with it is not
     * decidable here. This is the decidable approximation over the declared class, and
     * it errs toward silence: a key that might be satisfiable is not reported, because
     * a false failure in a CI gate is worse than a missed one.
     *
     * @param  class-string<InvokableBehavior>  $behaviorClass
     * @param  class-string<ContextManager>  $contextClass
     *
     * @return list<string>
     */
    public static function unsatisfiableRequiredContextKeys(string $behaviorClass, string $contextClass): array
    {
        // A base ContextManager takes arbitrary keys through set(), so nothing about it
        // is decidable and every key is left alone.
        if ($contextClass === ContextManager::class || !is_subclass_of($contextClass, ContextManager::class)) {
            return [];
        }

        $unsatisfiable = [];

        foreach ($behaviorClass::$requiredContext as $key => $type) {
            // The list form declares a type with no key to verify.
            if (!is_string($key)) {
                continue;
            }

            // Only the first dot-delimited segment names a property; deeper segments
            // address into that value and are not statically resolvable.
            $root = explode('.', $key, limit: 2)[0];

            if (!property_exists($contextClass, $root)) {
                $unsatisfiable[] = $key;
            }
        }

        return $unsatisfiable;
    }

    /**
     * Event classes that derive the same event type as another class in the same set.
     *
     * The comparison is on the DERIVED type, never the class name: getType() strips a
     * trailing `Event` before snake-casing and is overridable, so two differently-named
     * classes can collide and two similarly-named ones may not.
     *
     * Each returned group names the type, every class deriving it, and which class
     * currently owns it in the machine's registry — ownership is last-writer-wins over
     * the definition traversal, so a consumer cannot work it out by reading config.
     *
     * @param  list<class-string<EventBehavior>>  $eventClasses
     * @param  array<string, class-string<EventBehavior>>  $registry
     *
     * @return list<array{type: string, classes: list<class-string<EventBehavior>>, owner: class-string<EventBehavior>|null}>
     */
    public static function eventTypeCollisions(array $eventClasses, array $registry = []): array
    {
        $byType = [];

        foreach (array_unique($eventClasses) as $class) {
            $byType[$class::getType()][] = $class;
        }

        $collisions = [];

        foreach ($byType as $type => $classes) {
            if (count($classes) < 2) {
                continue;
            }

            sort($classes);

            $collisions[] = [
                'type'    => $type,
                'classes' => $classes,
                // A colliding class that never reaches the registry has no owner at all;
                // saying so is more useful than naming an arbitrary one.
                'owner' => $registry[$type] ?? null,
            ];
        }

        return $collisions;
    }

    /**
     * @param  class-string  $behaviorClass
     */
    private static function invokeMethod(string $behaviorClass): ?ReflectionMethod
    {
        // InvokableBehavior declares no abstract __invoke, so a collected class may
        // legitimately have none. Reflecting it would raise, and the caller would turn
        // that into a whole-machine failure — a false CI red.
        if (!method_exists($behaviorClass, '__invoke')) {
            return null;
        }

        try {
            return new ReflectionMethod($behaviorClass, '__invoke');
        } catch (ReflectionException) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    private static function declaredTypeNames(?\ReflectionType $type): array
    {
        return match (true) {
            $type instanceof ReflectionUnionType => array_values(array_map(
                static fn (\ReflectionType $member): string => $member instanceof ReflectionNamedType
                    ? $member->getName()
                    : (string) $member,
                $type->getTypes(),
            )),
            $type instanceof ReflectionNamedType => [$type->getName()],
            default                              => [],
        };
    }

    private static function isContextType(string $name): bool
    {
        return $name === ContextManager::class || is_subclass_of($name, ContextManager::class);
    }

    /**
     * The injection categories the engine recognises besides context.
     *
     * Kept beside isContextType() so the two stay in step: a name that resolves to one
     * of these is injected by another arm of the engine's match and is not a context
     * parameter, however the union is spelled.
     */
    public static function isFrameworkType(string $name): bool
    {
        return $name === EventBehavior::class
            || is_subclass_of($name, EventBehavior::class)
            || $name === State::class
            || is_subclass_of($name, State::class);
    }
}
