<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

use Carbon\Carbon;
use ReflectionClass;
use ReflectionProperty;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Contracts\Support\Arrayable;
use Tarfinlabs\EventMachine\Contracts\ContextCast;
use Tarfinlabs\EventMachine\Exceptions\MachineContextValidationException;

/**
 * Class ContextManager.
 *
 * Base class for typed context classes. Provides reflection-based from()/toArray(),
 * 3-layer cast resolution (explicit casts, global registry, auto-detect),
 * and native Laravel validation via rules().
 *
 * Bag mode (array-based context) is handled by the Context subclass.
 */
class ContextManager implements \JsonSerializable, Arrayable
{
    // region Global Cast Registry

    /** @var array<class-string, class-string<ContextCast>> */
    private static array $globalCasts = [];

    public static function registerCast(string $typeClass, string $castClass): void
    {
        self::$globalCasts[$typeClass] = $castClass;
    }

    public static function flushState(): void
    {
        self::$globalCasts    = [];
        self::$parameterCache = [];
        self::$propertyCache  = [];
    }

    // endregion

    // region Reflection Cache

    /** @var array<class-string, array<string, ReflectionParameter>> */
    private static array $parameterCache = [];

    /** @var array<class-string, array<string, ReflectionProperty>> */
    private static array $propertyCache = [];

    /**
     * @return array<string, ReflectionParameter>
     */
    protected static function getCachedParameters(): array
    {
        return self::$parameterCache[static::class] ??= collect(
            (new ReflectionClass(static::class))->getConstructor()?->getParameters() ?? []
        )->keyBy(fn (ReflectionParameter $p): string => $p->getName())->all();
    }

    /**
     * @return array<string, ReflectionProperty>
     */
    protected static function getCachedProperties(): array
    {
        return self::$propertyCache[static::class] ??= collect(
            (new ReflectionClass(static::class))->getProperties(ReflectionProperty::IS_PUBLIC)
        )->keyBy(fn (ReflectionProperty $p): string => $p->getName())->all();
    }

    // endregion

    // region Cast/Transform

    /**
     * @return array<string, class-string<ContextCast>|array<class-string>>
     */
    public static function casts(): array
    {
        return [];
    }

    // endregion

    // region Validation

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [];
    }

    /**
     * Validates the current instance against its own rules.
     *
     * selfValidate() calls toArray() → serialize (Model→id, Enum→value).
     * Therefore rules() must be written for the serialized form.
     */
    public function selfValidate(): void
    {
        static::performValidation($this->toArray());
    }

    /**
     * Validates the given payload and creates an instance from it.
     *
     * @param  array<mixed>|Arrayable<string, mixed>  $payload
     */
    public static function validateAndCreate(array|Arrayable $payload): static
    {
        $data = is_array($payload) ? $payload : $payload->toArray();
        static::performValidation($data);

        return static::from($data);
    }

    protected static function performValidation(array $data): void
    {
        $rules = static::rules();

        if ($rules === []) {
            return;
        }

        $validator = Validator::make($data, $rules, static::messages());

        if ($validator->fails()) {
            throw new MachineContextValidationException($validator);
        }
    }

    // endregion

    // region Factory

    /**
     * Create a new instance from an array of data.
     *
     * @param  array<string, mixed>  $data
     */
    public static function from(array $data): static
    {
        $params = static::getCachedParameters();
        $args   = [];

        foreach ($params as $name => $param) {
            if (array_key_exists($name, $data)) {
                $args[$name] = static::deserializeValue($name, $data[$name], $param);
            } elseif ($param->isDefaultValueAvailable()) {
                $args[$name] = $param->getDefaultValue();
            }
        }

        return new static(...$args);
    }

    // endregion

    // region Property Access (engine-internal, works on typed properties)

    /**
     * Get a value from the context by its key.
     * On typed contexts, accesses the public property directly.
     */
    public function get(string $key): mixed
    {
        return $this->{$key} ?? null;
    }

    /**
     * Set a value for a given key.
     * On typed contexts, sets the public property directly.
     */
    public function set(string $key, mixed $value): mixed
    {
        $this->{$key} = $value;

        return $value;
    }

    /**
     * Check if a key exists and optionally verify its type.
     * On typed contexts, checks property existence.
     */
    public function has(string $key, ?string $type = null): bool
    {
        $hasKey = property_exists($this, $key);

        if (!$hasKey || $type === null) {
            return $hasKey;
        }

        $value     = $this->get($key);
        $valueType = get_debug_type($value);

        return $valueType === $type;
    }

    /**
     * Remove a key from the context.
     * On typed contexts, sets the property to null.
     */
    public function remove(string $key): void
    {
        if (property_exists($this, $key)) {
            $this->{$key} = null;
        }
    }

    // endregion

    // region Serialization

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [];

        foreach (static::getCachedProperties() as $name => $prop) {
            $result[$name] = static::serializeValue($name, $prop->getValue($this));
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    // endregion

    // region Cast Resolution

    protected static function deserializeValue(string $name, mixed $value, ?ReflectionParameter $param = null): mixed
    {
        if ($value === null) {
            return null;
        }

        // Layer 1: Explicit casts()
        $casts = static::casts();
        if (isset($casts[$name])) {
            return static::applyCast($casts[$name], $value, 'deserialize');
        }

        // Layer 2: Global registry
        if ($param instanceof ReflectionParameter) {
            $typeClass = self::extractTypeClass($param);
            if ($typeClass !== null && isset(self::$globalCasts[$typeClass])) {
                return static::applyCast(self::$globalCasts[$typeClass], $value, 'deserialize');
            }
        }

        // Layer 3: Auto-detect
        if ($param instanceof ReflectionParameter) {
            return self::autoDeserialize($param, $value);
        }

        return $value;
    }

    protected static function serializeValue(string $name, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        // Layer 1: Explicit casts()
        $casts = static::casts();
        if (isset($casts[$name])) {
            return static::applyCast($casts[$name], $value, 'serialize');
        }

        // Layer 2: Global registry
        foreach (self::$globalCasts as $typeClass => $castClass) {
            if ($value instanceof $typeClass) {
                return static::applyCast($castClass, $value, 'serialize');
            }
        }

        // Layer 3: Auto-detect
        if ($value instanceof Model) {
            return $value->getKey();
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }

        if ($value instanceof Collection) {
            return $value->map(
                fn (mixed $item): mixed => $item instanceof Arrayable ? $item->toArray() : $item
            )->all();
        }

        if ($value instanceof Arrayable) {
            return $value->toArray();
        }

        return $value;
    }

    /**
     * @param  class-string<ContextCast>|array<class-string>  $cast
     */
    protected static function applyCast(string|array $cast, mixed $value, string $direction): mixed
    {
        // Array syntax: Collection<DTO>
        if (is_array($cast)) {
            $itemClass = $cast[0];

            return match ($direction) {
                'serialize' => collect($value)->map(
                    fn (mixed $item): mixed => $item instanceof Arrayable ? $item->toArray() : $item
                )->all(),
                'deserialize' => collect($value)->map(
                    fn (mixed $item): mixed => is_array($item) ? $itemClass::from($item) : $item
                ),
            };
        }

        // ContextCast interface
        if (is_subclass_of($cast, ContextCast::class)) {
            $caster = new $cast();

            return $direction === 'serialize'
                ? $caster->serialize($value)
                : $caster->deserialize($value);
        }

        throw new \InvalidArgumentException(
            "Cast [{$cast}] must implement ContextCast or be an array syntax [ClassName::class]. "
            .'Model/Enum/DateTime types are auto-detected — no cast needed.'
        );
    }

    private static function autoDeserialize(ReflectionParameter $param, mixed $value): mixed
    {
        $typeClass = self::extractTypeClass($param);

        if ($typeClass === null) {
            return $value;
        }

        if (is_subclass_of($typeClass, Model::class) && (is_int($value) || is_string($value))) {
            return $typeClass::find($value);
        }

        if (enum_exists($typeClass) && (is_int($value) || is_string($value))) {
            return $typeClass::from($value);
        }

        if (is_subclass_of($typeClass, \DateTimeInterface::class) && is_string($value)) {
            return Carbon::parse($value);
        }

        return $value;
    }

    /**
     * Extract the first non-builtin class name from a parameter's type hint.
     * For union types (e.g. ?Retailer), skips null and returns the first class.
     */
    private static function extractTypeClass(ReflectionParameter $param): ?string
    {
        $type = $param->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            return $type->getName();
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $unionType) {
                if ($unionType instanceof ReflectionNamedType && !$unionType->isBuiltin()) {
                    return $unionType->getName();
                }
            }
        }

        return null;
    }

    // endregion

    // region Machine Identity

    /** The machine's root_event_id — separate from context data to avoid polluting serialized state. */
    protected ?string $internalMachineId = null;

    /** The parent machine's root_event_id (if this is a child machine). */
    protected ?string $internalParentRootEventId = null;

    /** The parent machine's FQCN (if this is a child machine). */
    protected ?string $internalParentMachineClass = null;

    /**
     * Set machine identity properties.
     *
     * Called by the engine during create()/start() — not stored in the data array.
     */
    public function setMachineIdentity(string $machineId, ?string $parentRootEventId = null, ?string $parentMachineClass = null): void
    {
        $this->internalMachineId          = $machineId;
        $this->internalParentRootEventId  = $parentRootEventId;
        $this->internalParentMachineClass = $parentMachineClass;
    }

    public function machineId(): ?string
    {
        return $this->internalMachineId;
    }

    public function parentMachineId(): ?string
    {
        return $this->internalParentRootEventId;
    }

    public function parentMachineClass(): ?string
    {
        return $this->internalParentMachineClass;
    }

    public function isChildMachine(): bool
    {
        return $this->internalParentRootEventId !== null;
    }

    // endregion

    // region Computed Context

    /**
     * Define computed key-value pairs derived from context data.
     *
     * Override in subclasses to expose calculated values in API responses.
     * These are NOT persisted to the database — they are recomputed on every response.
     *
     * @return array<string, mixed>
     */
    protected function computedContext(): array
    {
        return [];
    }

    /**
     * Serialize context for API responses, including computed values.
     *
     * @return array<string, mixed>
     */
    public function toResponseArray(): array
    {
        return array_merge($this->toArray(), $this->computedContext());
    }

    // endregion

    // region Magic Setup

    /**
     * Set a value in the context by its name.
     *
     * @param  string  $name  The name of the value to set.
     * @param  mixed  $value  The value to set.
     */
    public function __set(string $name, mixed $value): void
    {
        $this->set($name, $value);
    }

    /**
     * Magic method to dynamically retrieve a value from the context by its key.
     *
     * @param  string  $name  The key of the value to retrieve.
     *
     * @return mixed The value associated with the given key, or null if the key does not exist.
     */
    public function __get(string $name): mixed
    {
        return $this->get($name);
    }

    /**
     * Checks if a property is set on the object.
     *
     * @param  string  $name  The name of the property to check.
     *
     * @return bool True if the property exists and is set, false otherwise.
     */
    public function __isset(string $name): bool
    {
        return $this->has($name);
    }

    // endregion
}
