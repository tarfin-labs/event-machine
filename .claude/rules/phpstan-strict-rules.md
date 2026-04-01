# PHPStan Strict Rules — Fix Patterns

EventMachine uses `phpstan/phpstan-strict-rules` at level 5. When PHPStan reports an error, fix the root cause — do NOT add `@phpstan-ignore` comments or ignoreErrors entries.

## Fix Patterns (instead of ignoring)

| Error | Fix | Example |
|-------|-----|---------|
| `staticClassAccess.privateProperty` | Change `private static` to `protected static` in traits | Fakeable trait props used by subclasses |
| `match.unhandled` | Add `default => throw new \LogicException('Unreachable')` | ContextManager match(true) expressions |
| `varTag.nativeType` | Use `@var class-string<T>` instead of `@var T` for class-name strings | `/** @var class-string<Machine> $machineClass */` |
| `attribute.target` | Remove misplaced attributes from non-promoted constructor params | `#[WithoutValidation]` only works on promoted properties |
| `return.phpDocType` | Update stale PHPDoc to match actual return type | `@return bool` → `@return State\|null` |
| `empty.notAllowed` | Replace `empty($x)` with `$x === []`, `$x === ''`, `$x === null` | Strict comparison for the specific type |
| `ternary.shortNotAllowed` | Replace `$x ?: $y` with `$x ?? $y` or explicit comparison | Null coalesce if applicable |
| `booleanNot.exprNotBoolean` | Replace `!$x` with `$x === null`, `$x === false`, `$x === ''` | Explicit boolean comparison |
| `if.condNotBoolean` | Replace `if ($x)` with `if ($x !== null)` | Strict condition check |
| `cast.useless` | Remove redundant casts like `(string)` on already-string values | Delete the cast |
| `nullsafe.neverNull` | Replace `?->` with `->` when PHPStan proves non-nullable | Trust PHPStan type inference |

## Accepted Ignores (framework limitations, path-scoped)

Only these are ignored, each scoped to the specific file:

| Identifier | File | Reason |
|------------|------|--------|
| `larastan.noEnvCallsOutsideOfConfig` | `config/` | Config files use env() by design |
| `return.type` | `Fakeable.php` | Mockery return types |
| `trait.unused` | `src/Testing/`, `src/Traits/` | Package traits used by consumers |
| `identical.alwaysFalse` | `StateDefinition.php`, `MachineConfigValidatorCommand.php` | Defensive guard clauses |
| `property.notFound` | `ArchiveStatusCommand.php`, `MachineLockTimeoutException.php` | Eloquent raw query dynamic properties |
| `method.notFound` | `InvokableBehavior.php`, `MachineCast.php` | Mockery internals, trait methods on Model |
| `method.childReturnType` | `Machine.php`, `MachineEvent.php` | Laravel covariant return types |

## Rule: No Global Ignores

Every ignoreErrors entry MUST have a `path:` scope. Global ignores (without path) hide real errors in new files. If a new file triggers an ignored error, fix the code — don't rely on a blanket suppression.

## Disabled Strict Rules

| Rule | Reason |
|------|--------|
| `dynamicCallOnStaticMethod: false` | Eloquent Builder pattern — framework limitation |
