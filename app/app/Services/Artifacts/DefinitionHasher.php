<?php

namespace App\Services\Artifacts;

/**
 * Canonical JSON hashing for artifact definitions (SD-034 JSON spec).
 */
final class DefinitionHasher
{
    /**
     * @param  array<string, mixed>  $definition
     */
    public static function hash(array $definition): string
    {
        return 'sha256:'.hash('sha256', self::canonicalize($definition));
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $value
     */
    public static function canonicalize(mixed $value): string
    {
        return json_encode(self::sortKeys($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $isList = array_is_list($value);
        if ($isList) {
            return array_map(fn ($v) => self::sortKeys($v), $value);
        }
        ksort($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::sortKeys($v);
        }

        return $out;
    }
}
