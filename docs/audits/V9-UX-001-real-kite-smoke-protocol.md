# V9-UX-001 controlled real-Kite read-only smoke

This protocol supplies the separate real integration boundary required by the frozen epic. Deterministic CI is not live acceptance evidence. No command here places, changes or cancels orders, moves money, starts reconciliation, changes execution mode or alters services.

## Preconditions and execution

1. Verify the normal GitHub deployment and its exact `/api/build-info` commit SHA and runtime health.
2. The operator reconnects the intended existing Kite account through Settings → Account on the provider's own page. Credentials/OTP remain there; do not put them in chat or shell arguments.
3. Use the existing authorized StoX user ID owning that connection. From the deployed application root, run `php artisan stox:kite-read-only-smoke USER_ID` once. Replace `USER_ID` with that numeric ID. Never run backend verification or migrations on production.
4. Retain the small JSON result with the deployed SHA and UTC timestamp in the epic audit. A live result must have `evidence_kind=real_kite_read_only`, `passed=true`, `stage=complete`, `read_requests=3`, and the expected deployed `commit_sha`.

The command requires production environment and valid deployed build metadata for live use. It reads existing connection readiness and stops before HTTP for a missing/expired session or nonofficial provider configuration. Only GET requests to `https://api.kite.trade/portfolio/holdings`, `/portfolio/positions`, and `/user/margins/equity` can pass the transmission guard. Redirects are disabled. All three responses must report successful provider status; failed positions are not accepted as an empty portfolio.

The command calls the existing `KiteBrokerGateway::portfolioSnapshot` reader without persistence/reconciliation. It prints no tokens, headers, provider bodies, account identity, symbols, balances or raw exceptions. A failure reports a fixed stage; correct the actual prerequisite/provider issue and repeat only after authorization/session readiness exists.

## Deterministic verification and current status

`KiteReadOnlySmokeTest` exercises three boundary reads, absence of database writes, expired-session refusal, nonofficial-host refusal, failed-position refusal and prevention of an unexpected order POST before transmission. Its results are explicitly labelled `deterministic_contract`; they do not qualify as real-Kite acceptance.

The production inspection on 2026-10-11 found the existing session expired at 2026-10-06 00:30 UTC. No live smoke was executed with that session. This protocol and any green CI result leave real-Kite acceptance blocked until the operator establishes a current authorized connection and the deployed command passes. No ML campaign/source/training acceptance is inferred from broker smoke.
