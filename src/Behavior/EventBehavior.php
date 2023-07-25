<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Behavior;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Illuminate\Validation\ValidationException;
use Tarfinlabs\EventMachine\Definition\SourceType;
use Spatie\LaravelData\Attributes\WithoutValidation;
use Tarfinlabs\EventMachine\Exceptions\MachineEventValidationException;

abstract class EventBehavior extends Data
{
    public function __construct(
        public null|string|Optional $type = null,
        public null|array|Optional $payload = null,
        public int|Optional $version = 1,

        #[WithoutValidation]
        public SourceType $source = SourceType::EXTERNAL,
    ) {
        if ($this->type === null) {
            $this->type = static::getType();
        }
    }

    abstract public static function getType(): string;

    /**
     * Validates the current event behavior.
     *
     * @throws MachineEventValidationException if validation fails.
     */
    public function selfValidate(): void
    {
        try {
            static::validate($this);
        } catch (ValidationException $e) {
            throw new MachineEventValidationException($e->validator);
        }
    }
}
