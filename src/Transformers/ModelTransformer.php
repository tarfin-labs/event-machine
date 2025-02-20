<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Transformers;

use Spatie\LaravelData\Support\DataProperty;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Transformers\Transformer;

/**
 * Class ModelTransformer.
 *
 * This class implements the Transformer interface.
 * It is responsible for transforming a given value by returning its 'id' property.
 */
class ModelTransformer implements Transformer
{
    /**
     * Transforms the value of a DataProperty object.
     *
     * @param  DataProperty  $property  The DataProperty object.
     * @param  mixed  $value  The value to be transformed.
     *
     * @return mixed The transformed value.
     */
    public function transform(DataProperty $property, mixed $value, TransformationContext $context): mixed
    {
        return $value->id;
    }
}
