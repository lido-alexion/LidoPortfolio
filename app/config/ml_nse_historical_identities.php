<?php

// Reviewed evidence only. Changes require identity regression tests and a fresh preview.
$chains = json_decode(file_get_contents(__DIR__.'/nse_historical_identity_chains.json'), true, 512, JSON_THROW_ON_ERROR);

$legacy = [
    'version' => 'nse-historical-identities-1',
    'identities' => [
        0 => [
            'symbol' => 'NESTLEIND',
            'historical_isin' => 'INE239A01016',
            'canonical_isin' => 'INE239A01024',
            'valid_from' => '2022-11-04',
            'valid_until' => '2024-01-04',
            'change_effective_on' => '2024-01-05',
            'action' => 'subdivision',
            'evidence' => [
                0 => [
                    'role' => 'historical_observation',
                    'date' => '2022-11-04',
                    'url' => 'https://archives.nseindia.com/content/historical/EQUITIES/2022/NOV/cm04NOV2022bhav.csv.zip',
                    'sha256' => 'df02c39caf5bb6ffb97f43b6f76d67d4486d4483d38969d16e9b519483104d66',
                ],
                1 => [
                    'role' => 'effective_date',
                    'date' => '2024-01-02',
                    'url' => 'https://archives.nseindia.com/content/circulars/CML60084.pdf',
                    'sha256' => '4dda660aea17bdedd1e429b23c0d775a55ee5ee6f38a649904faabc37ef1ad0e',
                ],
                2 => [
                    'role' => 'isin_continuity',
                    'date' => '2024-06-15',
                    'url' => 'https://archives.nseindia.com/annual_reports/AR_24138_NESTLEIND_2023_2024_15062024233329.pdf',
                    'sha256' => 'a2bd86b483d58de5cb6fac36113ff1851d39d14d666c33f24c8cac382f180dfa',
                ],
            ],
        ],
    ],
];

return [
    'version' => 'nse-historical-identities-2',
    'transitions' => $chains['transitions'],
    'identities' => array_merge($legacy['identities'], $chains['identities']),
];
