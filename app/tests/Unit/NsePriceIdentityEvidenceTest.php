<?php

namespace Tests\Unit;

use App\Services\Bhavcopy\NsePriceIdentityEvidence;
use Tests\TestCase;

class NsePriceIdentityEvidenceTest extends TestCase
{
    public function test_reviewed_catalogue_is_bounded_and_provenance_is_required(): void
    {
        $resolver = app(NsePriceIdentityEvidence::class);
        $original = $resolver->document();
        $this->assertCount(3, $original['identities']);
        foreach ($original['identities'] as $identity) {
            $member = ['symbol' => $identity['symbol'], 'isin' => $identity['historical_isin']];
            foreach (['valid_from', 'valid_until'] as $boundary) {
                $this->assertSame($identity['canonical_isin'], $resolver->canonicalIsin($member, $identity[$boundary]));
            }
            $this->assertNull($resolver->canonicalIsin($member, (new \DateTimeImmutable($identity['valid_from']))->modify('-1 day')->format('Y-m-d')));
            $this->assertNull($resolver->canonicalIsin($member, (new \DateTimeImmutable($identity['valid_until']))->modify('+1 day')->format('Y-m-d')));
        }
        foreach (['missing_observation', 'missing_old_proof', 'wrong_new_isin', 'overlap'] as $fault) {
            $document = $original;
            $event = $document['identities'][0]['transition_ids'][0];
            switch ($fault) {
                case 'missing_observation': $document['identities'][0]['evidence'] = [];
                    break;
                case 'missing_old_proof': array_shift($document['transitions'][$event]['evidence']);
                    break;
                case 'wrong_new_isin': $document['transitions'][$event]['evidence'][1]['isin'] = 'INE999A01010';
                    break;
                case 'overlap': $document['identities'][] = $document['identities'][0];
                    break;
            }
            config(['universe_nse_price_identities' => $document]);
            $this->assertNull($resolver->canonicalIsin(['symbol' => 'AZADIND', 'isin' => 'INE566M01017'], '2026-10-22'), $fault);
        }
    }
}
