<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$migrations = glob($root.'/app/database/migrations/*.php') ?: [];
$errors = [];

foreach ($migrations as $path) {
    $source = (string) file_get_contents($path);
    $file = basename($path);

    if (preg_match_all('/\$table->(?:json|jsonb)\([^;]+?\)->default\s*\(/s', $source, $matches)) {
        $errors[] = "{$file}: MySQL does not reliably support a default on JSON columns; set the value in application code or a migration backfill.";
    }

    if (! preg_match("/Schema::create\(\s*'([^']+)'/", $source, $tableMatch)) {
        continue;
    }
    $table = $tableMatch[1];

    if (! preg_match_all('/\$table->(index|unique)\(\s*\[([^\]]+)\]\s*\)/s', $source, $indexes, PREG_SET_ORDER)) {
        continue;
    }
    foreach ($indexes as $index) {
        preg_match_all("/'([^']+)'/", $index[2], $columns);
        $suffix = $index[1] === 'unique' ? 'unique' : 'index';
        $name = $table.'_'.implode('_', $columns[1]).'_'.$suffix;
        if (strlen($name) > 64) {
            $errors[] = "{$file}: generated {$suffix} name '{$name}' is ".strlen($name)." characters; pass an explicit name of at most 64 characters.";
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, "MySQL migration portability check failed:\n- ".implode("\n- ", $errors)."\n");
    exit(1);
}

fwrite(STDOUT, "MySQL migration portability check passed for ".count($migrations)." migrations.\n");
