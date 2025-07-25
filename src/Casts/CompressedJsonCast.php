<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Casts;

use Illuminate\Database\Eloquent\Model;
use Tarfinlabs\EventMachine\Support\CompressionManager;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

class CompressedJsonCast implements CastsAttributes
{
    public function __construct(
        protected string $field
    ) {}

    /**
     * Cast the given value.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        return CompressionManager::decompress($value);
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        return CompressionManager::compress($value, $this->field);
    }
}
