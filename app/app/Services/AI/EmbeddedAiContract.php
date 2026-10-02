<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Validator;

/** Versioned, provider-independent contract shared by cache and inference. */
class EmbeddedAiContract
{
    public const VERSION = 1;

    public const CAPABILITIES = ['stock_analysis_insight', 'strategy_designer'];

    public static function schema(string $capability): array
    {
        if (! in_array($capability, self::CAPABILITIES, true)) {
            throw new \InvalidArgumentException('Unknown embedded capability');
        }

        return json_decode(file_get_contents(base_path('../docs/architecture/ai-schemas/'.$capability.'.v1.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function normalize(mixed $value): mixed
    {
        if (is_string($value)) {
            return trim(str_replace(["\r\n", "\r"], "\n", $value));
        }
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::normalize(...), $value);
    }

    public static function hash(mixed $value): string
    {
        return hash('sha256', json_encode(self::normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    public static function validate(string $capability, array $response): array
    {
        $schema = self::schema($capability);
        if (array_diff(array_keys($response), $schema['required'])) {
            throw new \InvalidArgumentException('Unexpected response fields');
        }
        $rules = [];
        foreach ($schema['properties'] as $key => $type) {
            $rules[$key] = $type['type'] === 'string' ? ['required', 'string', 'max:6000'] : ['required', 'array'];
        }
        $valid = Validator::make($response, $rules)->validate();
        if ($capability === 'strategy_designer') {
            Validator::make($valid, ['draft_envelope.schema_version' => 'required|in:1.0', 'draft_envelope.artifact_type' => 'required|in:strategy', 'draft_envelope.slug' => 'required|string|max:120', 'draft_envelope.name' => 'required|string|max:120', 'draft_envelope.metadata' => 'present|array', 'draft_envelope.definition' => 'present|array'])->validate();
            if (array_diff(array_keys($valid['draft_envelope']), array_keys($schema['properties']['draft_envelope']['properties']))) {
                throw new \InvalidArgumentException('Unexpected draft fields');
            }
        } else {
            // Defense in depth; prompts also prohibit prescriptive output.
            $text = implode("\n", $valid);
            $imperative = '/(?:^|[.!?:;]\s*|\n\s*)(?:buy|sell|hold|accumulate|add|reduce|exit|trim)\b|\b(?:consider\s+)?(?:adding|reducing|exiting|increasing|trimming)\s+(?:your|the|this)\s+(?:position|holding|exposure)\b|\b(?:buy|sell|hold)\s+(?:this stock|the stock|your shares|shares of|now)\b/i';
            if (preg_match($imperative, $text) || preg_match('/\b(?:recommend(?:ation|ed)?\s*(?:is|:)?\s*(?:buy|sell|hold)|(?:buy|sell|hold)\s+recommendation|target\s+price|price\s+target|you\s+should\s+(?:buy|sell|hold|add|reduce|exit)|(?:add|reduce|exit)\s+your\s+position)\b/i', $text)) {
                throw new \InvalidArgumentException('Prescriptive output');
            }
        }

        return $valid;
    }
}
