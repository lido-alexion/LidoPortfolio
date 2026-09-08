<?php

namespace App\Services\Artifacts;

final class ArtifactStructuralDiffService
{
    /** @return list<array{path:string,change:string,before:mixed,after:mixed}> */
    public function diff(array $before, array $after): array
    {
        $changes = [];
        $this->compare($before, $after, '', $changes);

        return $changes;
    }

    /** @param list<array{path:string,change:string,before:mixed,after:mixed}> $changes */
    private function compare(mixed $before, mixed $after, string $path, array &$changes): void
    {
        if (is_array($before) && is_array($after)) {
            $keys = array_values(array_unique([...array_keys($before), ...array_keys($after)]));
            foreach ($keys as $key) {
                $childPath = $path.'/'.$this->escape((string) $key);
                $hasBefore = array_key_exists($key, $before);
                $hasAfter = array_key_exists($key, $after);
                if (! $hasBefore) {
                    $changes[] = ['path' => $childPath, 'change' => 'added', 'before' => null, 'after' => $after[$key]];
                } elseif (! $hasAfter) {
                    $changes[] = ['path' => $childPath, 'change' => 'removed', 'before' => $before[$key], 'after' => null];
                } else {
                    $this->compare($before[$key], $after[$key], $childPath, $changes);
                }
            }

            return;
        }
        if ($before !== $after) {
            $changes[] = ['path' => $path === '' ? '/' : $path, 'change' => 'changed', 'before' => $before, 'after' => $after];
        }
    }

    private function escape(string $segment): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $segment);
    }
}
