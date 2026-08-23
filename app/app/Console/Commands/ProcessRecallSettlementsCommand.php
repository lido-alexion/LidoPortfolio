<?php

namespace App\Console\Commands;

use App\Services\Lending\GoodFaithBridgeRepaymentService;
use App\Services\Lending\GoodFaithCapitalLoanRepaymentService;
use App\Services\Lending\RecallFulfilmentService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * V3 WS4 — release Proceeds from Stock Sale, continue liquidation, good-faith repayments.
 * Idempotent: safe to re-run after synchronous fulfilment.
 */
class ProcessRecallSettlementsCommand extends Command
{
    protected $signature = 'portfolio:process-recall-settlements {--date= : YYYY-MM-DD treated as as-of for settlement availability}';

    protected $description = 'Process pending sale proceeds, recall settlements, and good-faith repayments';

    public function handle(
        RecallFulfilmentService $fulfilment,
        GoodFaithBridgeRepaymentService $goodFaithBridge,
        GoodFaithCapitalLoanRepaymentService $goodFaithLoans,
    ): int {
        $dateOption = $this->option('date');
        $asOf = is_string($dateOption) && $dateOption !== ''
            ? Carbon::parse($dateOption)->endOfDay()
            : now();

        $result = $fulfilment->processSettlements($asOf);
        $bridgeResult = $goodFaithBridge->repayAllProfiles($asOf);
        $loanResult = $goodFaithLoans->repayAllProfiles($asOf);

        $this->info(sprintf(
            'Proceeds applied: %d row(s); recall=%.2f bridge=%.2f. Good-faith bridge repay=%.2f (%d profiles); normal-loan repay=%.2f (%d profiles).',
            $result['proceeds']['processed'],
            $result['proceeds']['applied_to_recall'],
            $result['proceeds']['applied_to_bridge'],
            $bridgeResult['repaid_total'],
            $bridgeResult['profiles'],
            $loanResult['repaid_total'],
            $loanResult['profiles'],
        ));

        return self::SUCCESS;
    }
}
