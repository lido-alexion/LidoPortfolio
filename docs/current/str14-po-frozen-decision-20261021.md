# STR-14 frozen PO decision — 21 October 2026

Authority: PO accepted assistant recommendation with “Sure, so let's freeze this and proceed”. Supersedes conflicting STR-14 last-enabled/count-only wording and unresolved obligation-matrix hold; no other held scope is released.

Disable means pause. Disable is permitted anytime and stops new recommendations/future strategy runs. Existing owned holdings, orders, execution and settlement remain visible and manageable through existing workflows. Configuration stays editable; enabling again resumes use subject to existing readiness rules. Do not automatically sell, cancel orders or release funds.

Archive means retire. Archive is allowed only when no outstanding owned holdings, actionable recommendations, pending executions/orders, reserved funds or unsettled obligations remain. Blocked archive must explain exact blockers and link to existing resolution views. Archived configuration is read-only; history remains preserved and attributable. Restore transitions to Disabled, never directly Enabled; explicit enable then uses existing readiness checks.

Zero enabled strategies is allowed. No last-enabled-strategy/count-only block. All history retained; no forced liquidation, cancellation, financial write, reassignment or automatic fund release as lifecycle side effect.

Implementation must map categories to actual authoritative existing records/statuses and account/profile/strategy-version ownership; do not guess broad unrelated blockers. Backend enforces eligibility; UI mirrors explainable results. Races and ownership protection must be tested. Root owns review/merge/release; no live account lifecycle action is authorized by development approval.
