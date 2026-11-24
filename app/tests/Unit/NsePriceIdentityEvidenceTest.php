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
        $this->assertCount(4, $original['identities']);
        foreach ($original['identities'] as $identity) {
            $member = ['symbol' => $identity['symbol'], 'isin' => $identity['historical_isin']];
            foreach (['valid_from', 'valid_until'] as $boundary) {
                $this->assertSame($identity['canonical_isin'], $resolver->canonicalIsin($member, $identity[$boundary]));
            }
            $this->assertNull($resolver->canonicalIsin($member, (new \DateTimeImmutable($identity['valid_from']))->modify('-1 day')->format('Y-m-d')));
            $this->assertNull($resolver->canonicalIsin($member, (new \DateTimeImmutable($identity['valid_until']))->modify('+1 day')->format('Y-m-d')));
        }
        $azadIndex = array_key_first(array_filter($original['identities'], fn ($identity) => $identity['symbol'] === 'AZADIND'));
        foreach (['missing_observation', 'missing_old_proof', 'wrong_new_isin', 'overlap'] as $fault) {
            $document = $original;
            $event = $document['identities'][$azadIndex]['transition_ids'][0];
            switch ($fault) {
                case 'missing_observation': $document['identities'][$azadIndex]['evidence'] = [];
                    break;
                case 'missing_old_proof': array_shift($document['transitions'][$event]['evidence']);
                    break;
                case 'wrong_new_isin': $document['transitions'][$event]['evidence'][1]['isin'] = 'INE999A01010';
                    break;
                case 'overlap': $document['identities'][] = $document['identities'][$azadIndex];
                    break;
            }
            config(['universe_nse_price_identities' => $document]);
            $this->assertNull($resolver->canonicalIsin(['symbol' => 'AZADIND', 'isin' => 'INE566M01017'], '2026-10-22'), $fault);
        }
    }

    public function test_bnagrochem_old_isin_is_acknowledged_only_for_the_verified_trade_interval(): void
    {
        $resolver = app(NsePriceIdentityEvidence::class);
        $member = ['symbol' => 'BNAGROCHEM', 'isin' => 'INE00HZ01011'];

        $this->assertSame('INE00HZ01029', $resolver->canonicalIsin($member, '2026-11-13'));
        $this->assertSame('INE00HZ01029', $resolver->canonicalIsin($member, '2026-11-15'));
        foreach ([
            ['BNAGROCHEM', 'INE00HZ01011', '2026-11-12'],
            ['BNAGROCHEM', 'INE00HZ01011', '2026-11-16'],
            ['OTHER', 'INE00HZ01011', '2026-11-13'],
            ['BNAGROCHEM', 'INE00HZ01029', '2026-11-13'],
        ] as [$symbol, $isin, $date]) {
            $this->assertNull($resolver->canonicalIsin(['symbol' => $symbol, 'isin' => $isin], $date));
        }

        $document = $resolver->document();
        $identityIndex = array_key_first(array_filter($document['identities'], fn ($identity) => $identity['symbol'] === 'BNAGROCHEM'));
        $document['identities'][$identityIndex]['evidence'][0]['symbol'] = 'OTHER';
        config(['universe_nse_price_identities' => $document]);
        $this->assertNull($resolver->canonicalIsin($member, '2026-11-13'));
    }
}
