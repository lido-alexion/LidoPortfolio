<?php

namespace App\Support;

class StockValidationUserMessage
{
    /**
     * @param  array<int, string|array<int, string>>  $errors
     */
    public static function fromErrors(array $errors, string $symbol, string $exchange): string
    {
        $normalized = self::normalizeErrors($errors);
        $symbolLabel = strtoupper(trim($symbol));
        $exchangeLabel = strtoupper(trim($exchange) ?: 'NSE');

        if ($normalized === []) {
            return "Unable to verify symbol {$symbolLabel} ({$exchangeLabel}).";
        }

        if (self::looksLikeInvalidSymbol($normalized)) {
            return "Symbol {$symbolLabel} ({$exchangeLabel}) was not found. Choose a symbol from search or check the ticker spelling. You can still enter prices manually for RS.";
        }

        $reasons = self::summarizeReasons($normalized);

        if ($reasons === []) {
            return "Unable to verify symbol {$symbolLabel} ({$exchangeLabel}). Try again later or use manual RS input.";
        }

        return 'Could not verify '.$symbolLabel.' ('.$exchangeLabel.'): '
            .implode('; ', $reasons)
            .'. Try search autocomplete or manual RS input.';
    }

    /**
     * @param  array<int, string|array<int, string>>  $errors
     * @return array<int, string>
     */
    public static function normalizeErrors(array $errors): array
    {
        $flat = collect($errors)
            ->flatten()
            ->filter(fn ($error) => is_string($error) && trim($error) !== '')
            ->map(fn (string $error) => trim($error))
            ->unique()
            ->values()
            ->all();

        return $flat;
    }

    /**
     * @param  array<int, string>  $errors
     * @return array<int, string>
     */
    protected static function summarizeReasons(array $errors): array
    {
        $reasons = [];

        foreach ($errors as $error) {
            $friendly = self::friendlyReason($error);
            if ($friendly && ! in_array($friendly, $reasons, true)) {
                $reasons[] = $friendly;
            }
        }

        return $reasons;
    }

    protected static function friendlyReason(string $error): ?string
    {
        if (preg_match('/^NSE:\s*HTTP\s*403/i', $error)) {
            return 'NSE blocked automated access (403)';
        }

        if (preg_match('/^NSE:\s*HTTP\s*503/i', $error)) {
            return 'NSE temporarily unavailable (503)';
        }

        if (preg_match('/^NSE:/i', $error)) {
            return 'NSE quote lookup failed';
        }

        if (preg_match('/^Yahoo:\s*HTTP\s*404/i', $error)) {
            return 'no Yahoo quote for this symbol';
        }

        if (preg_match('/^Yahoo:/i', $error)) {
            return 'Yahoo quote lookup failed';
        }

        if (stripos($error, 'Alpha Vantage API key not configured') !== false) {
            return 'Alpha Vantage is not configured';
        }

        if (stripos($error, 'Alpha Vantage') !== false) {
            return 'Alpha Vantage quote lookup failed';
        }

        return $error;
    }

    /**
     * @param  array<int, string>  $errors
     */
    protected static function looksLikeInvalidSymbol(array $errors): bool
    {
        $hasYahooNotFound = collect($errors)->contains(
            fn (string $error) => stripos($error, 'Yahoo') !== false
                && (stripos($error, '404') !== false || stripos($error, 'no quote') !== false),
        );

        $hasNseNoData = collect($errors)->contains(
            fn (string $error) => stripos($error, 'NSE returned no company name') !== false,
        );

        return $hasYahooNotFound || $hasNseNoData;
    }
}
