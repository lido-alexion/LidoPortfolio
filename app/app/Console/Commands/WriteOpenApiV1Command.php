<?php

namespace App\Console\Commands;

use App\Support\OpenApi\V1DocumentBuilder;
use Illuminate\Console\Command;

class WriteOpenApiV1Command extends Command
{
    protected $signature = 'openapi:v1 {--check : Validate existing file matches generated spec (no write)}';

    protected $description = 'Write or check the canonical OpenAPI 3.0.3 document for /api/v1';

    public function handle(V1DocumentBuilder $builder): int
    {
        $path = $builder->canonicalPath();
        $generated = $builder->encode($builder->build());

        if ($this->option('check')) {
            if (! is_file($path)) {
                $this->error('Missing '.$path);

                return self::FAILURE;
            }
            $existing = file_get_contents($path);
            if ($existing !== $generated) {
                $this->error('OpenAPI document is stale. Run php artisan openapi:v1');

                return self::FAILURE;
            }
            try {
                $builder->assertValidDocument(json_decode($existing, true, 512, JSON_THROW_ON_ERROR));
            } catch (\Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $this->info('OpenAPI /api/v1 document is up to date ('.$this->countOps($builder).' operations).');

            return self::SUCCESS;
        }

        $builder->write($path);
        $this->info('Wrote '.$path.' ('.$this->countOps($builder).' operations).');

        return self::SUCCESS;
    }

    protected function countOps(V1DocumentBuilder $builder): int
    {
        return count($builder->laravelOperationKeys());
    }
}
