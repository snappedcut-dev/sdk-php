<?php

declare(strict_types=1);

namespace SnappedCut;

/**
 * Narrowing for decoded JSON.
 *
 * `json_decode` returns `mixed`, and the server is across a network, so every
 * field is a claim rather than a fact. Casting `mixed` straight to `int` hides
 * a malformed or hostile response behind a plausible-looking 0; these helpers
 * check the type and fall back to a stated default instead.
 *
 * @internal
 */
final class Decode
{
    /** @param array<string, mixed> $raw */
    public static function str(array $raw, string $key, string $fallback = ''): string
    {
        $value = $raw[$key] ?? null;

        return is_string($value) ? $value : $fallback;
    }

    /** @param array<string, mixed> $raw */
    public static function int(array $raw, string $key, int $fallback = 0): int
    {
        $value = $raw[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }

        // A JSON number may arrive as a float (1.0) or, from some proxies, as a
        // numeric string. Anything else is not a number.
        return is_float($value) || (is_string($value) && is_numeric($value))
            ? (int) $value
            : $fallback;
    }

    /** @param array<string, mixed> $raw */
    public static function float(array $raw, string $key, float $fallback = 0.0): float
    {
        $value = $raw[$key] ?? null;
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return is_string($value) && is_numeric($value) ? (float) $value : $fallback;
    }

    /**
     * @param  array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public static function map(array $raw, string $key): array
    {
        $value = $raw[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param  array<string, mixed>      $raw
     * @return list<array<string, mixed>>
     */
    public static function rows(array $raw, string $key): array
    {
        $value = $raw[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }

        $rows = [];
        foreach ($value as $one) {
            if (is_array($one)) {
                /** @var array<string, mixed> $one */
                $rows[] = $one;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed> $raw
     * @return array<string, int>
     */
    public static function ints(array $raw, string $key): array
    {
        $prices = [];
        foreach (self::map($raw, $key) as $name => $value) {
            if (is_int($value)) {
                $prices[(string) $name] = $value;
            }
        }

        return $prices;
    }

    /** @param array<string, mixed> $raw */
    public static function nullableStr(array $raw, string $key): ?string
    {
        $value = $raw[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
