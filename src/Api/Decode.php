<?php

declare(strict_types=1);

namespace Elevate\Api;

use Elevate\Exception\UnexpectedResponseException;

/**
 * Reads the fields of a decoded JSON response for the generated models.
 *
 * @internal
 */
final class Decode
{
    private const string PREFIX = 'The response field "';

    public static function string(mixed $value, string $path): string
    {
        return \is_string($value) ? $value : throw self::unexpected($path, 'a string');
    }

    public static function int(mixed $value, string $path): int
    {
        return \is_int($value) ? $value : throw self::unexpected($path, 'an integer');
    }

    public static function float(mixed $value, string $path): float
    {
        return \is_int($value) || \is_float($value) ? (float) $value : throw self::unexpected($path, 'a number');
    }

    public static function bool(mixed $value, string $path): bool
    {
        return \is_bool($value) ? $value : throw self::unexpected($path, 'a boolean');
    }

    /**
     * @return list<mixed>
     */
    public static function list(mixed $value, string $path): array
    {
        return \is_array($value) && array_is_list($value) ? $value : throw self::unexpected($path, 'an array');
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(mixed $value, string $path): array
    {
        if (!\is_array($value) || ([] !== $value && array_is_list($value))) {
            throw self::unexpected($path, 'an object');
        }

        // Accepts a numeric key, which PHP holds as an integer: a JSON object can have one.
        $map = [];
        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T
     */
    public static function enum(string $enum, mixed $value, string $path): \BackedEnum
    {
        $case = \is_string($value) ? $enum::tryFrom($value) : null;

        return $case ?? throw self::unexpected($path, 'a value that this version of the SDK knows');
    }

    /**
     * @template T of Model
     *
     * @param class-string<T> $model
     *
     * @return T
     */
    public static function model(string $model, mixed $value, string $path): Model
    {
        $map = self::map($value, $path);

        try {
            return $model::fromArray($map);
        } catch (UnexpectedResponseException $exception) {
            // Reports the full path: the model names its fields without knowing where it sits in the response.
            throw new UnexpectedResponseException(self::PREFIX.$path.'.'.substr($exception->getMessage(), \strlen(self::PREFIX)));
        }
    }

    /**
     * @template T of Model
     *
     * @param array<string, class-string<T>> $models the model by value of the discriminator
     *
     * @return T
     */
    public static function union(array $models, string $discriminator, mixed $value, string $path): Model
    {
        $kind = self::map($value, $path)[$discriminator] ?? null;
        if (!\is_string($kind) || !isset($models[$kind])) {
            throw self::unexpected($path.'.'.$discriminator, 'a value that this version of the SDK knows');
        }

        return self::model($models[$kind], $value, $path);
    }

    private static function unexpected(string $path, string $expected): UnexpectedResponseException
    {
        return new UnexpectedResponseException(\sprintf('%s%s" is not %s.', self::PREFIX, $path, $expected));
    }
}
