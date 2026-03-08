<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Support;

class ArrayUtils
{
    /**
     * Merges two arrays recursively (deep merge).
     *
     * When both values for a key are arrays, they are merged recursively.
     * Otherwise, the value from $array2 overwrites the value from $array1.
     *
     * @param  array<string, mixed>  $array1
     * @param  array<string, mixed>  $array2
     *
     * @return array<string, mixed>
     */
    public static function recursiveMerge(array $array1, array $array2): array
    {
        $merged = $array1;

        foreach ($array2 as $key => $value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                $merged[$key] = self::recursiveMerge($merged[$key], $value);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * Compares two arrays recursively and returns the difference.
     *
     * @param  array<string, mixed>  $array1
     * @param  array<string, mixed>  $array2
     *
     * @return array<string, mixed>
     */
    public static function recursiveDiff(array $array1, array $array2): array
    {
        $difference = [];

        foreach ($array1 as $key => $value) {
            if (is_array($value)) {
                if (!isset($array2[$key]) || !is_array($array2[$key])) {
                    $difference[$key] = $value;
                } else {
                    $nestedDiff = self::recursiveDiff($value, $array2[$key]);
                    if ($nestedDiff !== []) {
                        $difference[$key] = $nestedDiff;
                    }
                }
            } elseif (!array_key_exists($key, $array2) || $array2[$key] !== $value) {
                $difference[$key] = $value;
            }
        }

        return $difference;
    }
}
