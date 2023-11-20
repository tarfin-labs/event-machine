<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Enums\SourceType;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Attributes\WithoutValidation;
use Tarfinlabs\EventMachine\Exceptions\MachineEventValidationException;

/**
 * Class EventBehavior.
 *
 * Represents the behavior of an event.
 */
abstract class EventBehavior extends Data
{
    /** Actor performing the event. */
    private mixed $actor = null;

    public bool $isTransactional = true;

    /**
     * Creates a new instance of the class.
     *
     * @param  null|string|Optional  $type The type of the object. Default is null.
     * @param  null|array|Optional  $payload The payload to be associated with the object. Default is null.
     * @param  mixed  $actor Actor performing the event. Default is null.
     * @param  int|Optional  $version The version number of the object. Default is 1.
     * @param  SourceType  $source The source type of the object. Default is SourceType::EXTERNAL.
     *
     * @return void
     */
    public function __construct(
        public null|string|Optional $type = null,
        public null|array|Optional $payload = null,
        #[WithoutValidation]
        bool $isTransactional = null,
        #[WithoutValidation]
        mixed $actor = null,
        public int|Optional $version = 1,

        #[WithoutValidation]
        public SourceType $source = SourceType::EXTERNAL,
    ) {
        if ($this->type === null) {
            $this->type = static::getType();
        }

        if ($isTransactional !== null) {
            $this->isTransactional = $isTransactional;
        }

        $this->actor = $actor;
    }

    /**
     * Gets the type of the object.
     *
     * @return string The type of the object.
     */
    abstract public static function getType(): string;

    /**
     * Validates the object by calling the static validate() method and handles any validation exceptions.
     *
     * @throws MachineEventValidationException If the object fails validation.
     */
    public function selfValidate(): void
    {
        try {
            static::validate($this);
        } catch (ValidationException $e) {
            throw new MachineEventValidationException($e->validator);
        }
    }

    public function actor(ContextManager $context): mixed
    {
        return $this->actor;
    }

    /**
     * Retrieves the scenario value from the payload.
     *
     * @return string|null The scenario value if available, otherwise null.
     */
    public function getScenario(): string|null
    {
        return $this->payload['scenario'] ?? null;
    }
}
