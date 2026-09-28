<?php

namespace App\Services\ML;

use App\Models\V7\MlModelVersion;

/** Maps model-native contributions to the pinned feature registry metadata. */
class MlExplainabilityService
{
    /** @param list<array<string,mixed>> $contributions @return array<string,mixed> */
    public function explain(MlModelVersion $model, array $contributions): array
    {
        $audit = is_array($model->audit_metadata) ? $model->audit_metadata : [];
        $profile = is_array($audit['feature_profile'] ?? null) ? $audit['feature_profile'] : [];
        $effective = array_values($audit['effective_feature_set'] ?? $model->feature_set ?? []);
        $metadata = is_array($profile['feature_metadata'] ?? null) ? $profile['feature_metadata'] : [];
        $rows = [];
        foreach ($contributions as $row) {
            $key = (string) ($row['feature'] ?? '');
            if ($key === '' || ! in_array($key, $effective, true)) continue;
            $meta = is_array($metadata[$key] ?? null) ? $metadata[$key] : [];
            $rows[] = [
                'feature_key' => $key,
                'label' => (string) ($meta['label'] ?? $meta['display_name'] ?? $key),
                'feature_version' => $meta['formula_version'] ?? null,
                'horizon' => $model->horizon,
                'value' => $row['value'] ?? null,
                'contribution' => is_numeric($row['contribution'] ?? null) ? (float) $row['contribution'] : null,
                'direction' => $row['direction'] ?? ((float) ($row['contribution'] ?? 0) >= 0 ? 'positive' : 'negative'),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => abs((float) ($b['contribution'] ?? 0)) <=> abs((float) ($a['contribution'] ?? 0)));

        return [
            'model_id' => $model->id,
            'model_version' => $model->version,
            'horizon' => $model->horizon,
            'feature_set_version' => $profile['feature_set_version'] ?? null,
            'drivers' => $rows,
            'top_positive' => array_values(array_filter($rows, static fn (array $row): bool => ($row['contribution'] ?? 0) > 0)),
            'top_negative' => array_values(array_filter($rows, static fn (array $row): bool => ($row['contribution'] ?? 0) < 0)),
        ];
    }
}
