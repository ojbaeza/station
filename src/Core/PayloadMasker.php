<?php

declare(strict_types=1);

namespace Station\Core;

use ReflectionClass;
use Station\Contracts\ShouldMaskPayload;
use Throwable;

final class PayloadMasker
{
    /**
     * Mask sensitive fields in a serialized payload for display.
     *
     * @param array<int, string> $globalFields Fields to always mask
     */
    public static function mask(string $serializedPayload, array $globalFields = [], string $replacement = '[REDACTED]'): string
    {
        try {
            $job = unserialize($serializedPayload, ['allowed_classes' => true]);
        } catch (Throwable) {
            return '[encrypted]';
        }

        if (!\is_object($job)) {
            return '[encrypted]';
        }

        $fieldsToMask = $globalFields;

        if ($job instanceof ShouldMaskPayload) {
            $fieldsToMask = array_unique(array_merge($fieldsToMask, $job->maskedFields()));
        }

        $data = self::objectToArray($job);
        $data = self::maskFields($data, $fieldsToMask, $replacement);

        return json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * Convert an object to an associative array of its public/protected/private properties.
     *
     * @return array<string, mixed>
     */
    private static function objectToArray(object $object): array
    {
        $result = [];

        try {
            $reflection = new ReflectionClass($object);

            foreach ($reflection->getProperties() as $property) {
                $value = $property->getValue($object);
                $name = $property->getName();

                if (\is_object($value)) {
                    $result[$name] = self::objectToArray($value);
                } elseif (\is_array($value)) {
                    $result[$name] = self::arrayDeep($value);
                } else {
                    $result[$name] = $value;
                }
            }
        } catch (Throwable) {
            return ['class' => $object::class];
        }

        return $result;
    }

    /**
     * Recursively process arrays, converting nested objects.
     *
     * @return array<mixed>
     */
    private static function arrayDeep(array $array): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            if (\is_object($value)) {
                $result[$key] = self::objectToArray($value);
            } elseif (\is_array($value)) {
                $result[$key] = self::arrayDeep($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Mask fields in a nested array using dot-notation field names.
     *
     * @param array<string, mixed> $data
     * @param array<int, string> $fields
     * @return array<string, mixed>
     */
    private static function maskFields(array $data, array $fields, string $replacement): array
    {
        foreach ($fields as $field) {
            $data = self::maskDotPath($data, explode('.', $field), $replacement);
        }

        return $data;
    }

    /**
     * Mask a value at a dot-notation path in a nested array.
     *
     * @param array<string, mixed> $data
     * @param array<int, string> $segments
     * @return array<string, mixed>
     */
    private static function maskDotPath(array $data, array $segments, string $replacement): array
    {
        $key = array_shift($segments);

        if ($key === null) {
            return $data;
        }

        if (!\array_key_exists($key, $data)) {
            return $data;
        }

        if ($segments === []) {
            $data[$key] = $replacement;
        } elseif (\is_array($data[$key])) {
            $data[$key] = self::maskDotPath($data[$key], $segments, $replacement);
        }

        return $data;
    }
}
