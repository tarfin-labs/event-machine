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
use Tarfinlabs\EventMachine\Exceptions\MachineValidationException;

/**
 * Abstract base class for typed data containers.
 *
 * Provides reflection-based from()/toArray(), 4-layer cast resolution
 * (casts > typeCasts > config > auto-detect), and native Laravel validation.
 *
 * Subclasses customize behavior via template methods:
 * - extractInputData() — how to read user data from input array
 * - buildArray() — how to wrap serialized properties for output
 * - isInfrastructureField() — which properties are not user data
 * - hydrateInfrastructure() — how to set non-user-data fields after construction
 */
abstract class TypedData implements \JsonSerializable, Arrayable
{
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

    /**
     * @return array<string, ReflectionProperty>
     */
    protected static function getUserProperties(): array
    {
        return array_filter(
            static::getCachedProperties(),
            fn (string $name): bool => !static::isInfrastructureField($name),
            ARRAY_FILTER_USE_KEY,
        );
    }

    public static function flushState(): void
    {
        self::$parameterCache = [];
        self::$propertyCache  = [];
    }

    // endregion

    // region Template Methods

    /**
     * Extract user data from the raw input array. Override for wrapped formats.
     */
    protected static function extractInputData(array $data): array
    {
        return $data;
    }

    /**
     * Wrap serialized user properties into the output format. Override for wrapped formats.
     */
    protected function buildArray(array $properties): array
    {
        return $properties;
    }

    /**
     * Is this property name an infrastructure field (not user data)?
     */
    protected static function isInfrastructureField(string $name): bool
    {
        return false;
    }

    /**
     * Set infrastructure fields on a freshly created instance.
     */
    protected static function hydrateInfrastructure(self $instance, array $data): void {}

    // endregion

    // region Cast/Transform (4-layer resolution)

    /**
     * Layer 1: Per-property cast overrides.
     */
    public static function casts(): array
    {
        return [];
    }

    /**
     * Layer 2: Per-type cast overrides (class-level).
     */
    public static function typeCasts(): array
    {
        return [];
    }

    /**
     * Layer 3: App-wide type casts from config/machine.php.
     */
    protected static function configCasts(): array
    {
        return config('machine.casts', []);
    }

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

        if ($param instanceof ReflectionParameter) {
            $typeClass = self::extractTypeClass($param);

            if ($typeClass !== null) {
                // Layer 2: typeCasts()
                $typeCasts = static::typeCasts();
                if (isset($typeCasts[$typeClass])) {
                    return static::applyCast($typeCasts[$typeClass], $value, 'deserialize');
                }

                // Layer 3: Config casts
                $configCasts = static::configCasts();
                if (isset($configCasts[$typeClass])) {
                    return static::applyCast($configCasts[$typeClass], $value, 'deserialize');
                }
            }
        }

        // Layer 4: Auto-detect
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

        // Layer 2: typeCasts()
        foreach (static::typeCasts() as $typeClass => $castClass) {
            if (is_string($typeClass) && $value instanceof $typeClass) {
                return static::applyCast($castClass, $value, 'serialize');
            }
        }

        // Layer 3: Config casts
        foreach (static::configCasts() as $typeClass => $castClass) {
            if (is_string($typeClass) && $value instanceof $typeClass) {
                return static::applyCast($castClass, $value, 'serialize');
            }
        }

        // Layer 4: Auto-detect
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

    public function selfValidate(): void
    {
        static::performValidation($this->toArray());
    }

    /**
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
            throw new MachineValidationException($validator);
        }
    }

    // endregion

    // region Factory

    /**
     * @param  array<string, mixed>  $data
     */
    public static function from(array $data): static
    {
        $inputData = static::extractInputData($data);
        $params    = static::getCachedParameters();
        $args      = [];

        foreach ($params as $name => $param) {
            if (static::isInfrastructureField($name)) {
                continue;
            }

            if (array_key_exists($name, $inputData)) {
                $args[$name] = static::deserializeValue($name, $inputData[$name], $param);
            } elseif ($param->isDefaultValueAvailable()) {
                $args[$name] = $param->getDefaultValue();
            }
        }

        $instance = new static(...$args);
        static::hydrateInfrastructure($instance, $data);

        return $instance;
    }

    // endregion

    // region Serialization

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $properties = [];

        foreach (static::getUserProperties() as $name => $prop) {
            $properties[$name] = static::serializeValue($name, $prop->getValue($this));
        }

        return $this->buildArray($properties);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    // endregion
}
