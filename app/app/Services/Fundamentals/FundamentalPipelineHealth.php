<?php

namespace App\Services\Fundamentals;

/** Pure response-mix classifier shared by the admin diagnostics and tests. */
final class FundamentalPipelineHealth
{
    /** @param list<array{run_id:int,outcomes:list<array<string,mixed>>}> $sessions */
    public function evaluate(array $sessions): array
    {
        $observed = [];
        foreach ($sessions as $session) {
            foreach ($session['outcomes'] as $outcome) {
                $observed[] = $outcome;
            }
        }

        $counts = ['data' => 0, 'no_data' => 0, 'retry_pending' => 0, 'exhausted' => 0];
        $symbols = [];
        $cadences = [];
        $categories = [];
        foreach ($observed as $item) {
            $symbols[(int) ($item['stock_id'] ?? 0)] = true;
            $itemCadences = $item['cadences'] ?? [];
            if (is_string($itemCadences)) $itemCadences = explode(',', $itemCadences);
            foreach (is_array($itemCadences) ? $itemCadences : [] as $cadence) if ($cadence !== '') $cadences[(string) $cadence] = true;
            $kind = (string) ($item['outcome'] ?? '');
            $details = is_array($item['details'] ?? null) ? $item['details'] : (json_decode((string) ($item['details'] ?? ''), true) ?: []);
            if ($kind === 'data' || ! empty($details['data_success'])) $counts['data']++;
            if ($kind === 'no_data' || ! empty($details['saw_no_data'])) $counts['no_data']++;
            if ($kind === 'provider_error') {
                if (($details['error_state'] ?? null) === 'retry_pending' || (! isset($details['error_state']) && ! empty($details['retry_pending']))) $counts['retry_pending']++;
                elseif (($details['error_state'] ?? null) === 'exhausted' || (! isset($details['error_state']) && empty($details['retry_pending']))) $counts['exhausted']++;
                $itemCategories = $details['categories'] ?? [$details['category'] ?? 'upstream'];
                foreach (array_unique($itemCategories) as $category) $categories[(string) $category] = ($categories[(string) $category] ?? 0) + 1;
            }
        }
        $sample = count($observed);
        $symbolCount = count($symbols);
        $recentSessions = array_slice($sessions, -3);
        $recoveryStreak = 0;
        foreach (array_reverse($recentSessions) as $session) {
            $hasData = false;
            $hasError = false;
            foreach ($session['outcomes'] as $item) {
                $details = is_array($item['details'] ?? null) ? $item['details'] : (json_decode((string) ($item['details'] ?? ''), true) ?: []);
                $hasData = $hasData || ($item['outcome'] ?? '') === 'data' || ! empty($details['data_success']);
                $hasError = $hasError || ($item['outcome'] ?? '') === 'provider_error';
            }
            if ($hasData && ! $hasError) $recoveryStreak++;
            else break;
        }
        $consecutiveFailedSessions = 0;
        $systemicCadences = [];
        foreach (array_reverse($recentSessions) as $session) {
            $outcomes = $session['outcomes'];
            $api = array_filter($outcomes, fn ($item) => in_array($item['outcome'] ?? '', ['data', 'provider_error', 'no_data'], true));
            $errors = array_filter($api, function ($item): bool {
                $details = is_array($item['details'] ?? null) ? $item['details'] : (json_decode((string) ($item['details'] ?? ''), true) ?: []);
                return ($item['outcome'] ?? '') === 'provider_error' && empty($details['data_success']) && ($details['error_state'] ?? null) !== 'resolved';
            });
            if (count($api) >= 3 && count($errors) / count($api) >= .8) {
                $consecutiveFailedSessions++;
                foreach ($errors as $item) {
                    $details = is_array($item['details'] ?? null) ? $item['details'] : (json_decode((string) ($item['details'] ?? ''), true) ?: []);
                    $errorCadences = $details['error_cadences'] ?? ($item['cadences'] ?? []);
                    if (is_string($errorCadences)) $errorCadences = explode(',', $errorCadences);
                    foreach (is_array($errorCadences) ? $errorCadences : [] as $cadence) if ($cadence !== '') $systemicCadences[(string) $cadence] = true;
                }
            } else break;
        }

        $sufficient = $sample >= 8 && $symbolCount >= 5;
        $allNoData = $sample > 0 && $counts['no_data'] === $sample && $counts['data'] === 0 && $counts['retry_pending'] === 0 && $counts['exhausted'] === 0;
        $systemic = $sufficient && count($systemicCadences) >= 2 && $consecutiveFailedSessions >= 2;
        $latestOutcomes = $sessions === [] ? [] : end($sessions)['outcomes'];
        $latestErrors = array_filter($latestOutcomes, function ($item): bool {
            $details = is_array($item['details'] ?? null) ? $item['details'] : (json_decode((string) ($item['details'] ?? ''), true) ?: []);
            return ($item['outcome'] ?? '') === 'provider_error' && ($details['error_state'] ?? null) !== 'resolved';
        });
        $latestData = array_filter($latestOutcomes, function ($item): bool {
            $details = is_array($item['details'] ?? null) ? $item['details'] : (json_decode((string) ($item['details'] ?? ''), true) ?: []);
            return ($item['outcome'] ?? '') === 'data' || ! empty($details['data_success']);
        });
        $latestExhausted = count(array_filter($latestErrors, function ($item): bool {
            $details = is_array($item['details'] ?? null) ? $item['details'] : (json_decode((string) ($item['details'] ?? ''), true) ?: []);
            return ($details['error_state'] ?? (empty($details['retry_pending']) ? 'exhausted' : 'retry_pending')) === 'exhausted';
        }));
        $status = match (true) {
            $sample === 0 => 'indeterminate_no_observations',
            $allNoData => 'indeterminate_no_data',
            $systemic => 'systemic_outage',
            $latestData !== [] && $latestExhausted > 0 => 'operational_with_exhausted_symbol_failures',
            $latestData !== [] && $latestErrors !== [] => 'operational_with_partial_failures',
            $latestData !== [] => 'operational',
            ! $sufficient && $counts['exhausted'] > 0 => 'degraded_exhausted_failures',
            ! $sufficient => 'indeterminate_insufficient_sample',
            $latestErrors !== [] && $counts['retry_pending'] > 0 => 'retry_pending',
            $counts['exhausted'] > 0 => 'degraded_exhausted_failures',
            default => 'indeterminate',
        };

        return [
            'status' => $status,
            'sample_sufficient' => $sufficient,
            'distinct_symbols' => $symbolCount,
            'distinct_cadences' => array_keys($cadences),
            'systemic_cadences' => array_keys($systemicCadences),
            'sessions_observed' => count($sessions),
            'consecutive_failed_sessions' => $consecutiveFailedSessions,
            'consecutive_recovery_sessions' => $recoveryStreak,
            'counts' => $counts,
            'error_categories' => $categories,
            'action_required' => $systemic || $latestExhausted > 0 || (! $sufficient && $counts['exhausted'] > 0),
            'policy' => 'At least 8 observed symbol outcomes across 5 symbols; systemic outage requires quarterly and annual observations plus 2 consecutive sessions with at least 3 outcomes each and at least 80% genuine errors.',
        ];
    }

    /** @return array<string,mixed> */
    public function recent(): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('stox_yahoo_fundamental_observations')) {
            return $this->evaluate([]);
        }
        $rows = \Illuminate\Support\Facades\DB::table('stox_yahoo_fundamental_observations')
            ->whereNotNull('run_id')
            ->whereIn('outcome', ['data', 'no_data', 'provider_error'])
            ->where('observed_at', '>=', now()->subDays(30))
            ->orderBy('run_id')->get(['run_id', 'stock_id', 'outcome', 'cadences', 'details']);
        $runIds = $rows->pluck('run_id')->unique()->all();
        $jobStates = [];
        if ($runIds !== []) {
            foreach (\Illuminate\Support\Facades\DB::table('stox_fundamental_update_jobs')->whereIn('run_id', $runIds)->get(['run_id', 'stock_id', 'cadence', 'status']) as $job) {
                $jobStates[$job->run_id.':'.$job->stock_id.':'.$job->cadence] = $job->status;
            }
        }
        $grouped = [];
        foreach ($rows as $row) {
            $details = json_decode((string) $row->details, true) ?: [];
            if ($row->outcome === 'provider_error' && ! empty($details['error_cadences'])) {
                $states = array_map(fn ($cadence) => $jobStates[$row->run_id.':'.$row->stock_id.':'.$cadence] ?? null, $details['error_cadences']);
                if (in_array('failed', $states, true)) $details['error_state'] = 'exhausted';
                elseif (in_array('retry', $states, true) || in_array('running', $states, true)) $details['error_state'] = 'retry_pending';
                elseif (count(array_filter($states, fn ($state) => in_array($state, ['completed', 'no_data'], true))) === count($states)) $details['error_state'] = 'resolved';
            }
            $grouped[(int) $row->run_id][] = ['stock_id' => (int) $row->stock_id, 'outcome' => $row->outcome, 'cadences' => $row->cadences, 'details' => $details];
        }
        $sessions = [];
        foreach (array_slice($grouped, -10, null, true) as $runId => $outcomes) $sessions[] = ['run_id' => $runId, 'outcomes' => $outcomes];
        return $this->evaluate($sessions);
    }
}
