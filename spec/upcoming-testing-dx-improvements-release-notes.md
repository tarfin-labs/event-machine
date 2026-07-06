# Release Notes Draft — Testing DX Improvements

> Draft for the release shipping `spec/upcoming-testing-dx-improvements.md`. Merge into the GitHub release body; delete this file after release.

## New Testing APIs

- **`YourContext::forTesting(array $overrides = [], ?string $machineId = 'test-machine-id')`** — build a typed context without `Optional` boilerplate; machine identity set automatically. Docs: https://eventmachine.dev/testing/isolated-testing#contextmanagerfortesting
- **`Machine::assertTransitions($table, context:, faking:)`** — table-driven state×event→target coverage, one fresh machine per row, guarded-row support. Docs: https://eventmachine.dev/testing/transitions-and-paths#table-driven-transition-testing
- **`Machine::testIsolated($context, $faking)`** — preset for `test()->fakingAllActions()`.
- **`TestMachine::spying([A::class, B::class])`** — batch spying (post-init; use `faking:` for boot-time behaviors).
- **`assertBehaviorRan()` accepts arrays** — mixed FQCNs and inline keys.
- **Fluent raised-event assertions** — `assertRaised(...)` now returns `RaisedEventAssertion`: `->withPayload([...])->withoutPayloadKey('x')->validated()`.
- **`runWithState()` accepts raw contexts** — `State|ContextManager|array` first parameter (name `$state` unchanged).

## Backward Compatibility — check before upgrading

1. **Method overrides (PHP fatals possible):**
   - Consumers overriding `InvokableBehavior::runWithState()` with a `State $state` parameter hit a contravariance fatal — widen your override to `State|ContextManager|array`.
   - Consumers overriding `InvokableBehavior::assertRaised(): void` hit a covariance fatal — the return type is now `RaisedEventAssertion`.
2. **New method names may collide** with consumer-defined methods on subclasses: `forTesting` (ContextManager subclasses), `testIsolated` / `assertTransitions` (Machine subclasses), `spying` (TestMachine extensions). Rename yours before upgrading if they exist.
3. **`simulateChildDone()` finalState is now validated** (intentionally stricter): tests passing a stale or typo'd `finalState` for machine-delegation children will start failing with an `AssertionFailedError` listing the child's real final states. These tests were asserting against nonexistent states — fix the test, not the validation. Job actors are unaffected.
4. **`fakingAllActions(except:)` after `testIsolated()` throws `LogicException`** — spies cannot be selectively undone; use the long form `test()->fakingAllActions(except: [...])`.
5. Callers of `assertRaised()` / `runWithState()` need no changes — return-type addition and parameter widening are call-site compatible.
