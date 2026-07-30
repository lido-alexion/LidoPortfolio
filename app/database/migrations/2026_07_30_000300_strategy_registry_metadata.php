<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Strategy Artifact Registry: metadata on existing strategies (SD-034).
 * Does not change Recommendation scoring; one active strategy per portfolio remains.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_tos_strategies', function (Blueprint $table) {
            if (! Schema::hasColumn('portfolio_tos_strategies', 'slug')) {
                $table->string('slug', 120)->nullable()->after('name');
            }
            if (! Schema::hasColumn('portfolio_tos_strategies', 'definition_hash')) {
                $table->string('definition_hash', 80)->nullable()->after('slug');
            }
            if (! Schema::hasColumn('portfolio_tos_strategies', 'intent')) {
                $table->string('intent', 500)->nullable()->after('description');
            }
            if (! Schema::hasColumn('portfolio_tos_strategies', 'summary')) {
                $table->text('summary')->nullable()->after('intent');
            }
            if (! Schema::hasColumn('portfolio_tos_strategies', 'tags_json')) {
                $table->json('tags_json')->nullable()->after('summary');
            }
        });

        try {
            Schema::table('portfolio_tos_strategies', function (Blueprint $table) {
                $table->unique(['profile_id', 'slug'], 'portfolio_tos_strategies_profile_slug_uq');
            });
        } catch (\Throwable) {
        }

        Schema::table('portfolio_tos_strategy_versions', function (Blueprint $table) {
            if (! Schema::hasColumn('portfolio_tos_strategy_versions', 'definition_hash')) {
                $table->string('definition_hash', 80)->nullable()->after('config_json');
            }
            if (! Schema::hasColumn('portfolio_tos_strategy_versions', 'version_label')) {
                // May already exist from factory protection migration
            }
        });

        // Backfill slugs + hashes for existing strategies (Minervini → momentum_strategy)
        $rows = DB::table('portfolio_tos_strategies')->select('id', 'profile_id', 'name', 'factory_key', 'slug')->get();
        foreach ($rows as $row) {
            $slug = $row->slug;
            if ($slug === null || $slug === '') {
                if ($row->factory_key === 'momentum_factory') {
                    $slug = 'momentum_strategy';
                } elseif ($row->factory_key) {
                    $slug = $this->slugify((string) $row->factory_key);
                } else {
                    $slug = $this->slugify((string) $row->name, (int) $row->id);
                }
                $base = $slug;
                $n = 2;
                while (DB::table('portfolio_tos_strategies')
                    ->where('profile_id', $row->profile_id)
                    ->where('slug', $slug)
                    ->where('id', '!=', $row->id)
                    ->exists()) {
                    $slug = $base.'_'.$n;
                    $n++;
                }
            }

            $version = DB::table('portfolio_tos_strategy_versions')
                ->where('strategy_id', $row->id)
                ->orderByDesc('id')
                ->first();
            $hash = null;
            if ($version && $version->config_json) {
                $config = json_decode((string) $version->config_json, true);
                if (is_array($config)) {
                    $portable = $this->portableConfig($config);
                    $hash = 'sha256:'.hash('sha256', json_encode($this->sortKeys($portable), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                    if (Schema::hasColumn('portfolio_tos_strategy_versions', 'definition_hash')) {
                        DB::table('portfolio_tos_strategy_versions')->where('id', $version->id)->update([
                            'definition_hash' => $hash,
                        ]);
                    }
                }
            }

            $tags = null;
            if ($row->factory_key === 'momentum_factory') {
                $tags = json_encode(['momentum', 'minervini', 'factory']);
            }

            DB::table('portfolio_tos_strategies')->where('id', $row->id)->update([
                'slug' => $slug,
                'definition_hash' => $hash,
                'tags_json' => $tags,
                'intent' => $row->factory_key === 'momentum_factory'
                    ? 'Trade stage-2 trend names with momentum-weighted scoring and explicit exits.'
                    : null,
                'summary' => $row->factory_key === 'momentum_factory'
                    ? 'Eligibility via Minervini Trend Template Screener reference. Scoring uses Registry composites.'
                    : null,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('portfolio_tos_strategy_versions', function (Blueprint $table) {
            if (Schema::hasColumn('portfolio_tos_strategy_versions', 'definition_hash')) {
                $table->dropColumn('definition_hash');
            }
        });
        Schema::table('portfolio_tos_strategies', function (Blueprint $table) {
            try {
                $table->dropUnique('portfolio_tos_strategies_profile_slug_uq');
            } catch (\Throwable) {
            }
            foreach (['slug', 'definition_hash', 'intent', 'summary', 'tags_json'] as $col) {
                if (Schema::hasColumn('portfolio_tos_strategies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    private function slugify(string $name, ?int $id = null): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?: '';
        $slug = trim($slug, '_');
        if ($slug === '') {
            $slug = 'strategy'.($id ? '_'.$id : '');
        }

        return substr($slug, 0, 100);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function portableConfig(array $config): array
    {
        if (isset($config['eligibility_sources']) && is_array($config['eligibility_sources'])) {
            $config['eligibility_sources'] = array_map(function ($src) {
                if (! is_array($src)) {
                    return $src;
                }
                unset($src['screener_id'], $src['screener_name']);
                if (! isset($src['screener_factory_key']) && isset($src['factory_key'])) {
                    $src['screener_factory_key'] = $src['factory_key'];
                }
                if (! isset($src['screener_slug']) && isset($src['screener_factory_key'])) {
                    $src['screener_slug'] = $src['screener_factory_key'];
                }

                return $src;
            }, $config['eligibility_sources']);
        }

        return $config;
    }

    private function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn ($v) => $this->sortKeys($v), $value);
        }
        ksort($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = $this->sortKeys($v);
        }

        return $out;
    }
};
