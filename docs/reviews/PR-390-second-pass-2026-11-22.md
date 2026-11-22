# PR #390 Engineering Second Pass — 2026-11-22

## Changes made

- Restored the include-today / exclude-today reconciliation choice. The frozen product decision requires the replacement behavior to be validated before removing this control. The backend again accepts only a boolean selector, keeps the old previous-calendar-day comparison for exclude-today, and uses the new settlement-aware comparison for include-today.
- Added regression coverage for direct `submitOne()` calls. That shared boundary now establishes the session baseline itself, so direct callers and retry paths cannot bypass the baseline established by batch entry points.
- Serialized order preflight and submission per profile. Concurrent recommendations in one profile cannot simultaneously pass the buying-power check against the same available funds.
- Marked Kite production funds evidence as `available_equity_buying_power`, not comparable settlement cash. Reconciliation retains the amount/difference for evidence but reports cash status as `unknown`; it cannot pass the session baseline based on a coincidental numeric match. The UI shows this distinction and keeps new execution blocked.
- Added regression coverage for duplicate manual transactions (review without a second import), partially reflected broker holdings (no adjustment), unsettled sells (exact negative quantity adjustment), and unknown cash evidence (baseline remains blocked).
- Updated the current execution, reconciliation and journey documentation to state the remaining cash-source and validation limits.

## Review findings retained as release blockers

1. **Comparable cash source is missing.** Production reads Kite `/user/margins/equity` and only has available buying power. It cannot prove account settlement cash against the StoX cash ledger. The implementation now fails closed, which means a production session baseline cannot currently become `reconciled`; live broker order submission will remain blocked until a validated comparable cash source is integrated. No automatic cash adjustment or order was performed.
2. **Settlement proof is incomplete.** Existing plus added tests cover exact T+1 buy and sell deltas, weekend settlement date calculation, partial broker reflection, and manual duplicate review. The required matrix still needs direct proof for offsetting same-day buy/sell activity, preexisting unsettled imports across multiple trading sessions and holidays, `used_quantity` behavior against actual Kite holdings evidence, repeated reconnect/catch-up with unavailable history, and mixed broker reflection where only some fills are reflected. Keep the date selector until those cases pass against controlled evidence.
3. **Manual multi-row matching remains conservative.** A unique exact aggregate manual transaction can link multiple fills. Duplicate or split manual candidates stay in `manual_match_review`; they are not silently merged or re-imported as accepted matches. Product review workflow for resolving these review rows remains necessary.
4. **Hosted CI remains required.** The local checkout has no `app/vendor/autoload.php`, so PHPUnit could not start. CI-parity tests were not run here. No migrations were changed in this second pass. Do not treat PHP syntax checks as a substitute for the required backend and frontend CI gates.

## Verification performed

- `php -l` passed for all changed PHP service, controller, broker gateway and feature-test files on the final tree.
- `git diff --check` passed on the final tree.
- Focused PHPUnit invocation was attempted and stopped before test discovery because `app/vendor/autoload.php` is absent.
- No real Kite orders, production data/config/cache/scheduler/worker changes, PR operations, or deployment actions were performed.

## ACTION REQUIRED FROM YOU

- Confirm the supported source for settlement-comparable broker cash (for example, a validated broker ledger/statement source) before live order baselines can pass. This blocks live execution under the corrected safety gate.
- Keep PR #390 draft / unmerged until CI passes and the remaining settlement matrix is tested; the include/exclude selector remains until then.
