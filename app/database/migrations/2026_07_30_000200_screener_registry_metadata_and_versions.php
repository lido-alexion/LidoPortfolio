<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Screener Artifact Registry: metadata + version history on existing screeners (SD-034).
 * Does not change Screener execution; definition_json remains the eligibility core.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_screeners', function (Blueprint $table) {
            if (! Schema::hasColumn('portfolio_screeners', 'slug')) {
                $table->string('slug', 120)->nullable()->after('name');
            }
            if (! Schema::hasColumn('portfolio_screeners', 'artifact_version')) {
                $table->unsignedInteger('artifact_version')->default(1)->after('slug');
            }
            if (! Schema::hasColumn('portfolio_screeners', 'definition_hash')) {
                $table->string('definition_hash', 80)->nullable()->after('artifact_version');
            }
            if (! Schema::hasColumn('portfolio_screeners', 'intent')) {
                $table->string('intent', 500)->nullable()->after('description');
            }
            if (! Schema::hasColumn('portfolio_screeners', 'summary')) {
                $table->text('summary')->nullable()->after('intent');
            }
            if (! Schema::hasColumn('portfolio_screeners', 'tags_json')) {
                $table->json('tags_json')->nullable()->after('summary');
            }
            if (! Schema::hasColumn('portfolio_screeners', 'artifact_status')) {
                $table->string('artifact_status', 32)->default('active')->after('tags_json');
            }
        });

        try {
            Schema::table('portfolio_screeners', function (Blueprint $table) {
                $table->unique(['profile_id', 'slug'], 'portfolio_screeners_profile_slug_uq');
            });
        } catch (\Throwable) {
            // Index may already exist on re-run
        }

        if (! Schema::hasTable('portfolio_screener_versions')) {
            Schema::create('portfolio_screener_versions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('screener_id');
                $table->unsignedInteger('version');
                $table->json('definition_json');
                $table->json('metadata_json')->nullable();
                $table->string('definition_hash', 80)->nullable();
                $table->string('change_notes', 1000)->nullable();
                $table->timestamps();

                $table->unique(['screener_id', 'version'], 'portfolio_screener_versions_screener_ver_uq');
                $table->foreign('screener_id')->references('id')->on('portfolio_screeners')->cascadeOnDelete();
            });
        }

        // Backfill slug + hash + initial version row for existing screeners
        $rows = DB::table('portfolio_screeners')->select('id', 'profile_id', 'name', 'factory_key', 'definition_json', 'slug')->get();
        foreach ($rows as $row) {
            $slug = $row->slug;
            if ($slug === null || $slug === '') {
                $slug = $row->factory_key ?: $this->slugify((string) $row->name, (int) $row->id);
                // Ensure uniqueness within profile
                $base = $slug;
                $n = 2;
                while (DB::table('portfolio_screeners')
                    ->where('profile_id', $row->profile_id)
                    ->where('slug', $slug)
                    ->where('id', '!=', $row->id)
                    ->exists()) {
                    $slug = $base.'_'.$n;
                    $n++;
                }
            }
            $definition = json_decode((string) $row->definition_json, true);
            if (! is_array($definition)) {
                $definition = ['root' => ['type' => 'group', 'op' => 'AND', 'children' => []]];
            }
            $hash = 'sha256:'.hash('sha256', json_encode($this->sortKeys($definition), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            DB::table('portfolio_screeners')->where('id', $row->id)->update([
                'slug' => $slug,
                'artifact_version' => 1,
                'definition_hash' => $hash,
                'artifact_status' => 'active',
            ]);

            $exists = DB::table('portfolio_screener_versions')
                ->where('screener_id', $row->id)
                ->where('version', 1)
                ->exists();
            if (! $exists) {
                DB::table('portfolio_screener_versions')->insert([
                    'screener_id' => $row->id,
                    'version' => 1,
                    'definition_json' => json_encode($definition),
                    'metadata_json' => json_encode(['backfill' => true]),
                    'definition_hash' => $hash,
                    'change_notes' => 'Initial version (Screener Registry backfill)',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_screener_versions');
        Schema::table('portfolio_screeners', function (Blueprint $table) {
            try {
                $table->dropUnique('portfolio_screeners_profile_slug_uq');
            } catch (\Throwable) {
            }
            foreach (['slug', 'artifact_version', 'definition_hash', 'intent', 'summary', 'tags_json', 'artifact_status'] as $col) {
                if (Schema::hasColumn('portfolio_screeners', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    private function slugify(string $name, int $id): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?: '';
        $slug = trim($slug, '_');
        if ($slug === '') {
            $slug = 'screener_'.$id;
        }

        return substr($slug, 0, 100);
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
