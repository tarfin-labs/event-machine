<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Transformers;

use Spatie\LaravelData\Support\DataProperty;
use Spatie\LaravelData\Transformers\Transformer;

class ModelTransformer implements Transformer
{
    public function transform(DataProperty $property, mixed $value): mixed
    {
        return $value->id;
    }
}
