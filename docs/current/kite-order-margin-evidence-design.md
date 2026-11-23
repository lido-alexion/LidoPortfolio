# Kite per-order margin evidence — issue #385 offline shadow contract

Status: offline shadow-only evidence contract. It is not connected to an adapter or live execution. It does not activate trading, grant trading permission, change live execution/configuration, or make production/live Kite calls.

## Source-backed API surface

Kite Connect v3 documents authenticated `GET /user/profile` with a permanent `user_id`, authenticated `GET /user/margins/:segment` with separate `available.cash`, `available.live_balance`, `available.collateral`, and `net`, `GET /orders` for the current day's open/pending/executed order book, and `POST /margins/orders` for per-order margin calculations. Kite says order margin calculations consider existing positions and open orders and returns margin components, total, and charges.

These documents describe endpoint shape only. They do not establish deployment-specific response behavior, latency/freshness guarantees, the exact semantics needed to map returned `cash`/`total` to available cash versus collateral, or whether consecutive candidate calculations reserve the first hypothetical candidate. The documented order-margin response has no provider-generated response timestamp. A local request start/completion time is useful operational metadata, but cannot be labeled as a provider timestamp or establish provider data age.

Kite's order documentation supports MARKET protection percentages and automatic protection, but does not make a MARKET price a hard, pre-authorized maximum exposure. This implementation must keep buys blocked for MARKET orders. The future bounded candidate is a LIMIT buy only, with a fresh, independently timestamped quote, an explicit maximum quote deviation and exposure ceiling, and a limit price no greater than that ceiling. There is no approved order conversion policy in the current product decision.

## Required future capture and validation

1. Use the current encrypted `BrokerConnection` token only through `KiteBrokerGateway`; obtain `/user/profile` on that same authenticated session and require its `user_id` to equal the connection's `broker_user_id` captured for this request. Recheck the connection/account identity before any later use. Never persist credentials or raw provider error bodies.
2. Capture provider timestamps only if Kite supplies a documented timestamp with validated semantics. Keep local `request_started_at` and `response_received_at` separately. Until provider time is available and validated, the evidence is stale/unprovable and must block.
3. Fetch order margin for the exact candidate order and current equity funds in the same short capture window. Validate every response shape/value, instrument, exchange, side, product, variety, order type, quantity and limit price against the submitted candidate. Any missing, non-finite, inconsistent, or ambiguous field blocks.
4. Establish through Kite documentation and controlled non-live validation how `cash`, `total`, charges, available cash, collateral and utilization relate for CNC equity buys. Do not treat `net` as cash. A conservative policy may compare verified required cash plus charges against verified available cash while retaining collateral separately; do not credit collateral until its usability is proven for this exact order class.
5. Fetch `/orders` in the same capture if required to establish current pending orders, and validate statuses/remaining quantities. Kite documents `/margins/orders` as considering open orders; after validating that behavior, use its resulting candidate requirement once and do not subtract StoX/Kite pending-order reservations a second time. If margin response is incremental or excludes any reservation, define the exact deduplication key and calculation before activation. Multiple pending orders, partial fills, modifications, cancellations and unknown statuses must be covered.
6. Bind the margin response to a canonical hash of the exact candidate and the account ID; enforce a short provider timestamp TTL and invalidate on disconnect, reconnect, account switch, order modification, quote change, or material order-book change. Idempotent retries must reuse the existing submission claim and must not recapture and submit as a second invocation.
7. Preserve the existing order claim, kill switch, session baseline, funds refresh, risk, reservation, and broker reconciliation controls. Do not reserve or debit StoX ledger cash from this evidence, and do not run a second local funds reservation if the broker margin result already accounts for broker open orders.

`KiteOrderMarginEvidencePolicy` and its unit tests define an isolated contract for future typed evidence, including account binding, distinct provider/local times, cash/collateral separation, a fingerprint of the exact symbol/exchange/side/product/variety/type/quantity/limit-price candidate, pending-order context, and a bounded LIMIT price. The fingerprint detects candidate drift; it does not authenticate a provider response or prove that provider calculations include open orders. The policy is not injected into live execution. The current #398 gate remains disabled by default; its MARKET call remains blocked, and its scalar funds response cannot pass this contract.

The policy is a pure, deterministic evaluator for a future evidence shape, not an adapter or authorization mechanism. Its positive fixture only demonstrates arithmetic under asserted proof markers. Production freshness remains unprovable: Kite's published order-margin response has no authenticated provider timestamp, and a caller-supplied verification boolean cannot create one. Local request start/receive times are operational metadata only. Similarly, asserted order/position inclusion markers and matching pending-order counts are not proof of broker semantics; they require independently validated capture provenance before any future use.

## Activation blockers

- Kite's published `/margins/orders` schema has no provider timestamp, so provider freshness cannot currently be proven from documented output.
- The required cash-versus-collateral interpretation and the exact treatment of charges for this order type have not been validated against authoritative broker behavior.
- Multiple pending order and hypothetical-candidate reservation semantics need validation before claiming the API avoids double reservation.
- Quote timestamp semantics and an approved maximum price/exposure policy are not established. Market protection is not an unbounded-market exposure proof.
- The pure policy has no connection/session identity or disconnect/reconnect invalidation mechanism, no submission claim, no quote feed, and no broker order-book capture. Its asserted evidence fields must never be populated from local receive time, inferred buying power, or unvalidated booleans.
- Independent safety review and a separate explicit release decision remain required before enabling the existing OFF flag.

## References

- [Kite Connect v3 margin calculation](https://kite.trade/docs/connect/v3/margins/)
- [Kite Connect v3 orders, order book and market protection](https://kite.trade/docs/connect/v3/orders/)
- [Kite Connect v3 profile and funds/margins](https://kite.trade/docs/connect/v3/user/)
