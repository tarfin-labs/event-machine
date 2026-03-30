# v10.0.0 Planned Removals

## Colon Syntax for Behavior Parameters

**Deprecated in:** v9.0.0
**Removal target:** v10.0.0

The `:arg1,arg2` colon syntax for passing positional string arguments to behaviors.

**Affected locations:**
- `TransitionDefinition::getFirstValidTransitionBranch()` — guard colon parsing
- `TransitionDefinition::runCalculators()` — calculator colon parsing
- `MachineDefinition::runAction()` — action colon parsing
- `Machine::resolveOutputBehavior()` — output colon parsing
- `MachineDefinition::transitionHasValidationGuard()` — colon stripping for detection
- `ExportXStateCommand::resolveBehaviorName()` — colon stripping for XState export

**Replacement:** Named params tuple `[[Class::class, 'param' => value]]`
