<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Commands;

use ReflectionClass;
use Illuminate\Console\Command;
use Spatie\LaravelData\Optional;
use Illuminate\Support\Facades\File;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Enums\BehaviorType;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Enums\StateDefinitionType;
use Tarfinlabs\EventMachine\Behavior\InvokableBehavior;
use Tarfinlabs\EventMachine\Definition\StateDefinition;
use Tarfinlabs\EventMachine\Definition\TransitionBranch;
use Spatie\LaravelData\Support\Validation\ValidationPath;
use Tarfinlabs\EventMachine\Definition\TransitionDefinition;

class ExportXStateCommand extends Command
{
    protected $signature = 'machine:xstate
        {machine : The Machine class path}
        {--output= : Output file path (default: auto-generated)}
        {--stdout : Print JSON to stdout instead of writing to file}
        {--format=json : Output format: json or js}';
    protected $description = 'Export machine definition to XState v5 JSON format for Stately Studio';

    public function handle(): int
    {
        $machinePath = $this->argument('machine');

        // Resolve file path to FQCN if a file path was given
        if (str_ends_with($machinePath, '.php') || str_contains($machinePath, DIRECTORY_SEPARATOR)) {
            $machinePath = $this->resolveClassFromFile($machinePath);

            if ($machinePath === null) {
                $this->error('Could not resolve a Machine class from the given file path.');

                return self::FAILURE;
            }
        }

        if (!class_exists($machinePath)) {
            $this->error("Machine class not found: {$machinePath}");

            return self::FAILURE;
        }

        $machine = $machinePath::create();

        $xstate = $this->buildMachineNode($machine->definition);

        $xstate = $this->convertEmptyArraysToObjects($xstate);
        $json   = json_encode($xstate, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($this->option('stdout')) {
            $this->line($json);

            return self::SUCCESS;
        }

        $output  = $this->resolveOutputPath($machinePath, $machine->definition->root->key);
        $content = $this->wrapOutput($json, $machine->definition->id, $machine->definition);

        File::put($output, $content);

        $this->info("XState definition exported to: {$output}");
        $this->line('');
        $this->line('Import into Stately Studio:');
        $this->line('  1. Open https://stately.ai/editor');
        $this->line('  2. Click "Import from code" in the Code panel');
        $this->line("  3. Paste the contents of {$output}");

        return self::SUCCESS;
    }

    private function buildMachineNode($definition): array
    {
        $node = [
            'id' => $definition->id,
        ];

        if ($definition->version !== null) {
            $node['description'] = "Version: {$definition->version}";
        }

        $context = $this->extractContext($definition);
        if ($context !== []) {
            $node['context'] = $context;
        }

        $rootState = $this->buildStateNode($definition->root);

        // Merge root state properties into machine node
        if (isset($rootState['initial'])) {
            $node['initial'] = $rootState['initial'];
        }

        if (isset($rootState['type'])) {
            $node['type'] = $rootState['type'];
        }

        if (isset($rootState['states'])) {
            $node['states'] = $rootState['states'];
        }

        if (isset($rootState['on'])) {
            $node['on'] = $rootState['on'];
        }

        if (isset($rootState['always'])) {
            $node['always'] = $rootState['always'];
        }

        // Add behavior catalog as meta for documentation
        $behaviorCatalog = $this->buildBehaviorCatalog($definition);
        if ($behaviorCatalog !== []) {
            $node['meta'] = ['eventMachine' => $behaviorCatalog];
        }

        return $node;
    }

    private function buildStateNode(StateDefinition $stateDefinition): array
    {
        $node = [];

        // Description
        if ($stateDefinition->description !== null) {
            $node['description'] = $stateDefinition->description;
        }

        // Type
        $node = match ($stateDefinition->type) {
            StateDefinitionType::FINAL    => array_merge($node, ['type' => 'final']),
            StateDefinitionType::PARALLEL => array_merge($node, ['type' => 'parallel']),
            default                       => $node,
        };

        // Entry actions
        if ($stateDefinition->entry !== null && $stateDefinition->entry !== []) {
            $node['entry'] = array_map(
                $this->resolveBehaviorName(...),
                $stateDefinition->entry
            );
        }

        // Exit actions
        if ($stateDefinition->exit !== null && $stateDefinition->exit !== []) {
            $node['exit'] = array_map(
                $this->resolveBehaviorName(...),
                $stateDefinition->exit
            );
        }

        // Meta
        if ($stateDefinition->meta !== null) {
            $node['meta'] = $stateDefinition->meta;
        }

        // Child states
        if ($stateDefinition->stateDefinitions !== null) {
            // Initial state (not for parallel)
            if ($stateDefinition->type !== StateDefinitionType::PARALLEL) {
                $initialKey = $stateDefinition->config['initial']
                    ?? array_key_first($stateDefinition->stateDefinitions);
                if ($initialKey !== null) {
                    $node['initial'] = $initialKey;
                }
            }

            $node['states'] = [];
            foreach ($stateDefinition->stateDefinitions as $key => $childState) {
                $node['states'][$key] = $this->buildStateNode($childState);
            }
        }

        // Transitions
        $this->addTransitions($node, $stateDefinition);

        // @done transition → onDone
        if ($stateDefinition->onDoneTransition instanceof TransitionDefinition) {
            $node['onDone'] = $this->buildTransitionConfig($stateDefinition->onDoneTransition, $stateDefinition);
        }

        // @fail transition → meta (no XState equivalent)
        if ($stateDefinition->onFailTransition instanceof TransitionDefinition) {
            $failConfig   = $this->buildTransitionConfig($stateDefinition->onFailTransition, $stateDefinition);
            $node['meta'] = array_merge($node['meta'] ?? [], [
                'eventMachine' => ['onFail' => $failConfig],
            ]);
        }

        return $node;
    }

    private function addTransitions(array &$node, StateDefinition $stateDefinition): void
    {
        if ($stateDefinition->transitionDefinitions === null) {
            return;
        }

        foreach ($stateDefinition->transitionDefinitions as $event => $transitionDefinition) {
            if ($transitionDefinition->isAlways) {
                $node['always'] = $this->buildTransitionConfig($transitionDefinition, $stateDefinition);

                continue;
            }

            $eventName = str_replace('@', '', $event);
            $node['on'] ??= [];
            $node['on'][$eventName] = $this->buildTransitionConfig($transitionDefinition, $stateDefinition);
        }
    }

    private function buildTransitionConfig(TransitionDefinition $transitionDefinition, StateDefinition $source): array|string
    {
        if ($transitionDefinition->branches === null || $transitionDefinition->branches === []) {
            return [];
        }

        $branches = array_map(
            fn (TransitionBranch $branch): array => $this->buildBranchConfig($branch, $source),
            $transitionDefinition->branches
        );

        // Single branch without guard: simplify
        if (count($branches) === 1) {
            $branch = $branches[0];

            // Simplest form: just a target string
            if (
                count($branch) === 1
                && isset($branch['target'])
            ) {
                return $branch['target'];
            }

            return $branch;
        }

        // Multiple branches (guarded transition)
        return $branches;
    }

    private function buildBranchConfig(TransitionBranch $branch, StateDefinition $source): array
    {
        $config = [];

        // Target
        if ($branch->target instanceof StateDefinition) {
            $config['target'] = $this->resolveTargetPath($branch->target, $source);
        }

        // Guards
        if ($branch->guards !== null && $branch->guards !== []) {
            $guardNames = array_map(
                $this->resolveBehaviorName(...),
                $branch->guards
            );

            $config['guard'] = count($guardNames) === 1
                ? $guardNames[0]
                : ['type' => 'and', 'guards' => $guardNames];
        }

        // Actions
        if ($branch->actions !== null && $branch->actions !== []) {
            $config['actions'] = array_map(
                $this->resolveBehaviorName(...),
                $branch->actions
            );
        }

        // Calculators → included as prefixed actions in meta
        if ($branch->calculators !== null && $branch->calculators !== []) {
            $config['meta'] = [
                'eventMachine' => [
                    'calculators' => array_map(
                        $this->resolveBehaviorName(...),
                        $branch->calculators
                    ),
                ],
            ];
        }

        // Description
        if ($branch->description !== null) {
            $config['description'] = $branch->description;
        }

        return $config;
    }

    /**
     * Resolve the target state path relative to the source for XState format.
     *
     * XState uses sibling names directly (e.g., "processing") for same-level targets,
     * and dot notation with # prefix for absolute paths (e.g., "#machine.parent.child").
     */
    private function resolveTargetPath(StateDefinition $target, StateDefinition $source): string
    {
        // If target is a sibling (same parent), just use the key
        if ($target->parent === $source->parent && $target->key !== null) {
            return $target->key;
        }

        // If target's parent is the source (child target), use dot prefix
        if ($target->parent === $source && $target->key !== null) {
            return '.'.$target->key;
        }

        // For cross-hierarchy targets, use absolute path with # prefix
        return '#'.$target->id;
    }

    /**
     * Resolve a behavior name from a class FQCN, inline string, or closure.
     *
     * Produces a short, human-readable name for the XState JSON output:
     * - FQCN class: extracts getType() if available, or uses class basename
     * - String with colon (params): extracts base name
     * - Plain string: used as-is
     */
    private function resolveBehaviorName(mixed $behavior): string
    {
        if (!is_string($behavior)) {
            return 'inlineBehavior';
        }

        // Strip parameters (e.g., "guardName:param1,param2" → "guardName")
        $baseName = str_contains($behavior, ':')
            ? explode(':', $behavior, 2)[0]
            : $behavior;

        // If it's a class, try to get its type
        if (class_exists($baseName)) {
            if (is_subclass_of($baseName, InvokableBehavior::class)) {
                return $baseName::getType();
            }

            if (is_subclass_of($baseName, EventBehavior::class)) {
                return $baseName::getType();
            }

            // Fallback: use class basename
            return class_basename($baseName);
        }

        return $baseName;
    }

    /**
     * Extract context schema from the machine definition.
     *
     * Handles both array-based context and typed ContextManager subclasses.
     */
    private function extractContext($definition): array
    {
        // Typed ContextManager class is moved to behavior['context'] during setupContextManager()
        $contextClass = $definition->behavior[BehaviorType::Context->value] ?? null;
        if (is_string($contextClass) && is_subclass_of($contextClass, ContextManager::class)) {
            return $this->extractTypedContext($contextClass);
        }

        // Array context: use directly
        $contextConfig = $definition->config['context'] ?? null;
        if (is_array($contextConfig) && $contextConfig !== []) {
            return $contextConfig;
        }

        return [];
    }

    /**
     * Extract properties and their default values from a typed ContextManager subclass.
     */
    private function extractTypedContext(string $contextClass): array
    {
        $reflection = new ReflectionClass($contextClass);
        $context    = [];

        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $param) {
            if ($param->getName() === 'data') {
                continue; // Skip parent's data property
            }

            $name = $param->getName();
            $type = $param->getType();

            // Try to get default value
            if ($param->isDefaultValueAvailable()) {
                $context[$name] = $param->getDefaultValue();
            } else {
                // Use type-appropriate null/default
                $context[$name] = $this->getDefaultForType($type);
            }
        }

        return $context;
    }

    private function getDefaultForType(?\ReflectionType $type): mixed
    {
        if (!$type instanceof \ReflectionType) {
            return null;
        }

        // For union types (e.g., int|Optional), find the first non-Optional named type
        if ($type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $unionType) {
                if ($unionType instanceof \ReflectionNamedType && $unionType->getName() !== Optional::class) {
                    return $this->getDefaultForType($unionType);
                }
            }

            return null;
        }

        $typeName = $type instanceof \ReflectionNamedType
            ? $type->getName()
            : 'mixed';

        return match ($typeName) {
            'int', 'float' => 0,
            'string' => '',
            'bool'   => false,
            'array'  => [],
            default  => null,
        };
    }

    /**
     * Build a behavior catalog documenting all registered behaviors.
     *
     * This is included as meta information for documentation purposes,
     * since XState only references behaviors by name.
     */
    private function buildBehaviorCatalog($definition): array
    {
        $catalog = [];

        foreach ([BehaviorType::Guard, BehaviorType::Action, BehaviorType::Calculator, BehaviorType::Event] as $type) {
            $behaviors = $definition->behavior[$type->value] ?? [];
            if ($behaviors === []) {
                continue;
            }

            $items = [];
            foreach ($behaviors as $key => $behavior) {
                if (is_string($behavior) && class_exists($behavior)) {
                    $items[$key] = [
                        'class' => $behavior,
                        'type'  => $type->value,
                    ];

                    if (is_subclass_of($behavior, InvokableBehavior::class) && $behavior::$requiredContext !== []) {
                        $items[$key]['requiredContext'] = $behavior::$requiredContext;
                    }

                    // Extract event payload schema from validation rules
                    if ($type === BehaviorType::Event && is_subclass_of($behavior, EventBehavior::class)) {
                        $payloadSchema = $this->extractEventPayloadSchema($behavior);
                        if ($payloadSchema !== []) {
                            $items[$key]['payload'] = $payloadSchema;
                        }
                    }
                } else {
                    $items[$key] = [
                        'type'   => $type->value,
                        'inline' => true,
                    ];
                }
            }

            $catalog[$type->value] = $items;
        }

        return $catalog;
    }

    /**
     * Extract payload schema from an EventBehavior class by inspecting its validation rules.
     *
     * Maps Laravel validation rules to TypeScript-compatible type descriptors.
     *
     * @return array<string, array{type: string, required: bool}> Payload field names mapped to type descriptors
     */
    private function extractEventPayloadSchema(string $eventClass): array
    {
        $schema = [];

        // Try to get validation rules
        try {
            $reflection = new ReflectionClass($eventClass);
            if (!$reflection->hasMethod('rules')) {
                return $schema;
            }

            $rulesMethod = $reflection->getMethod('rules');

            // rules() may require a ValidationContext parameter
            $params = $rulesMethod->getParameters();
            if ($params !== [] && !$params[0]->isDefaultValueAvailable()) {
                // Try calling with a mock ValidationContext
                $contextClass = $params[0]->getType() instanceof \ReflectionNamedType
                    ? $params[0]->getType()->getName()
                    : null;

                if ($contextClass !== null && class_exists($contextClass)) {
                    $rules = $rulesMethod->invoke(null, new $contextClass([], [], ValidationPath::create()));
                } else {
                    return $schema;
                }
            } else {
                $rules = $rulesMethod->invoke(null);
            }
        } catch (\Throwable) {
            return $schema;
        }

        foreach ($rules as $fieldPath => $fieldRules) {
            // Strip 'payload.' prefix to get the field name
            $fieldName = str_starts_with((string) $fieldPath, 'payload.')
                ? substr((string) $fieldPath, 8)
                : $fieldPath;

            $ruleList = is_array($fieldRules) ? $fieldRules : explode('|', (string) $fieldRules);

            $schema[$fieldName] = [
                'type'     => $this->inferTypeFromRules($ruleList),
                'required' => in_array('required', $ruleList, true),
            ];
        }

        return $schema;
    }

    /**
     * Infer a TypeScript-compatible type string from Laravel validation rules.
     */
    private function inferTypeFromRules(array $rules): string
    {
        foreach ($rules as $rule) {
            if (!is_string($rule)) {
                continue;
            }

            if (in_array($rule, ['integer', 'numeric', 'min', 'max'], true) || str_starts_with($rule, 'min:') || str_starts_with($rule, 'max:')) {
                return 'number';
            }

            if (in_array($rule, ['string', 'email', 'url', 'uuid'], true)) {
                return 'string';
            }

            if (in_array($rule, ['boolean', 'accepted'], true)) {
                return 'boolean';
            }

            if ($rule === 'array') {
                return 'array';
            }

            if (str_starts_with($rule, 'date') || $rule === 'date') {
                return 'string'; // dates are strings in JSON
            }
        }

        return 'unknown';
    }

    private function resolveOutputPath(string $machinePath, string $machineKey): string
    {
        if ($this->option('output') !== null) {
            return $this->option('output');
        }

        $extension = $this->option('format') === 'js' ? 'js' : 'json';
        $filePath  = str_replace('\\', DIRECTORY_SEPARATOR, $machinePath);

        return base_path(dirname($filePath).'/'.$machineKey.'-machine.'.$extension);
    }

    private function wrapOutput(string $json, string $machineId, $definition = null): string
    {
        if ($this->option('format') === 'js') {
            $typesBlock = $this->buildEventTypesBlock($definition);

            if ($typesBlock !== '') {
                return <<<JS
                    import { setup } from 'xstate';

                    export const {$machineId}Machine = setup({
                      types: {
                        events: {$typesBlock},
                      },
                    }).createMachine({$json});

                    JS;
            }

            return <<<JS
                import { createMachine } from 'xstate';

                export const {$machineId}Machine = createMachine({$json});

                JS;
        }

        return $json;
    }

    /**
     * Build a TypeScript union type literal for event types with their payload schemas.
     *
     * Produces: {} as | { type: "EVENT_A"; field: number } | { type: "EVENT_B" }
     */
    private function buildEventTypesBlock($definition): string
    {
        if ($definition === null) {
            return '';
        }

        $eventBehaviors = $definition->behavior[BehaviorType::Event->value] ?? [];
        if ($eventBehaviors === []) {
            return '';
        }

        $unionParts = [];
        foreach ($eventBehaviors as $eventClass) {
            if (!is_string($eventClass)) {
                continue;
            }
            if (!class_exists($eventClass)) {
                continue;
            }
            if (!is_subclass_of($eventClass, EventBehavior::class)) {
                continue;
            }
            $eventType     = $eventClass::getType();
            $payloadSchema = $this->extractEventPayloadSchema($eventClass);

            $fields = "type: \"{$eventType}\"";
            foreach ($payloadSchema as $fieldName => $fieldInfo) {
                $tsType   = $this->mapToTypeScriptType($fieldInfo['type']);
                $optional = $fieldInfo['required'] ? '' : '?';
                $fields .= "; {$fieldName}{$optional}: {$tsType}";
            }

            $unionParts[] = "{ {$fields} }";
        }

        if ($unionParts === []) {
            return '';
        }

        return '{} as | '.implode(' | ', $unionParts);
    }

    private function mapToTypeScriptType(string $type): string
    {
        return match ($type) {
            'number'  => 'number',
            'string'  => 'string',
            'boolean' => 'boolean',
            'array'   => 'unknown[]',
            default   => 'unknown',
        };
    }

    /**
     * Recursively convert empty arrays to stdClass objects so they encode as {} in JSON.
     *
     * State nodes like `"processed": {}` should be empty objects, not empty arrays.
     */
    private function convertEmptyArraysToObjects(array $data): array|\stdClass
    {
        if ($data === []) {
            return new \stdClass();
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->convertEmptyArraysToObjects($value);
            }
        }

        return $data;
    }

    /**
     * Resolve a FQCN from a PHP file path by extracting namespace and class name.
     */
    private function resolveClassFromFile(string $filePath): ?string
    {
        if (!file_exists($filePath)) {
            // Try relative to base_path
            $filePath = base_path($filePath);
            if (!file_exists($filePath)) {
                return null;
            }
        }

        $contents  = file_get_contents($filePath);
        $namespace = null;
        $class     = null;

        if (preg_match('/namespace\s+([^;]+);/', $contents, $matches)) {
            $namespace = trim($matches[1]);
        }

        if (preg_match('/class\s+(\w+)\s+extends/', $contents, $matches)) {
            $class = $matches[1];
        }

        if ($class === null) {
            return null;
        }

        $fqcn = $namespace !== null ? $namespace.'\\'.$class : $class;

        // Ensure the class is autoloaded
        if (!class_exists($fqcn)) {
            require_once $filePath;
        }

        return class_exists($fqcn) ? $fqcn : null;
    }
}
