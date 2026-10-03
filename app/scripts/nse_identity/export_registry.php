<?php

use App\Services\ML\NseHistoricalIdentityEvidence;

// Offline only: load static config and the resolver, never bootstrap Laravel or a DB.
require __DIR__.'/../../app/Services/ML/NseHistoricalIdentityEvidence.php';
$document = require __DIR__.'/../../config/ml_nse_historical_identities.php';
$resolver = new class($document) extends NseHistoricalIdentityEvidence
{
    public function __construct(private array $registry) {}

    public function document(): array
    {
        return $this->registry;
    }
};
foreach ($document['identities'] as $identity) {
    $member = ['symbol' => $identity['symbol'], 'isin' => $identity['historical_isin']];
    foreach (['valid_from', 'valid_until'] as $bound) {
        if ($resolver->canonicalIsin($member, $identity[$bound]) !== $identity['canonical_isin']) {
            fwrite(STDERR, "Registry validation failed.\n");
            exit(1);
        }
    }
}
echo json_encode(['sha256' => $resolver->hash(), 'identities' => $document['identities']], JSON_THROW_ON_ERROR);
