<?php

namespace App\Services\Fundamentals;

use App\Models\Stock;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class YahooFundamentalDataProvider implements FundamentalDataProvider
{
    public function fetch(Stock $stock, string $cadence): array
    {
        $symbol = $stock->yahoo_symbol ?: $stock->symbol.($stock->exchange === 'BSE' ? '.BO' : '.NS');
        $modules = $cadence === FundamentalDataService::CADENCE_ANNUAL
            ? 'incomeStatementHistory,balanceSheetHistory,cashflowStatementHistory'
            : 'incomeStatementHistoryQuarterly,balanceSheetHistoryQuarterly,cashflowStatementHistoryQuarterly';

        $response = Http::timeout(20)
            ->retry(1, 500)
            ->get("https://query2.finance.yahoo.com/v10/finance/quoteSummary/{$symbol}", [
                'modules' => $modules,
                'corsDomain' => 'finance.yahoo.com',
                'formatted' => 'false',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Yahoo fundamentals request failed with HTTP '.$response->status());
        }

        $payload = $response->json();
        $result = $payload['quoteSummary']['result'][0] ?? null;
        if (! is_array($result)) {
            $error = $payload['quoteSummary']['error']['description'] ?? 'Yahoo returned no fundamental data.';
            throw new RuntimeException((string) $error);
        }

        return (new YahooFundamentalNormalizer)->normalize($result, $cadence);
    }
}
