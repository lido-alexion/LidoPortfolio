<?php

$symbol = $argv[1] ?? '';
$cadence = $argv[2] ?? '';

if ($symbol === 'FAIL.NS') {
    fwrite(STDERR, "provider unavailable\n");
    exit(7);
}

if ($symbol === 'MALFORMED.NS') {
    echo "not-json\n";
    exit(0);
}

if ($symbol === 'TIMEOUT.NS') {
    sleep(2);
    exit(0);
}

echo json_encode([
    'schema_version' => 1,
    'symbol' => $symbol,
    'cadence' => $cadence,
    'statements' => [
        'income_statement' => [[
            'period_end' => '2026-06-30',
            'facts' => ['Total Revenue' => 100, 'Net Income' => null],
        ]],
        'balance_sheet' => [],
        'cash_flow' => [],
    ],
], JSON_THROW_ON_ERROR);
