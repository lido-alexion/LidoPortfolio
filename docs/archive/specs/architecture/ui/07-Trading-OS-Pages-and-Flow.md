# Trading OS Pages and Recommendation Flow

| Field | Value |
|-------|-------|
| **Document** | Trading OS Pages and Recommendation Flow |
| **Audience** | Product users and agents |
| **Status** | Active |
| **Related** | [05-Daily-Decision-Pipeline.md](../platform/05-Daily-Decision-Pipeline.md), in-app Documentation topic `trading-os-flow` |

---

## 1. What each page is for

These are the main Trading OS navigation pages. Strategy is **configuration only** — it does not list recommended stocks.

**Day-to-day loop (what you configure / act on):** Screener → Strategy → Recommendations → Pending Execution → Transactions → Review.

**Discovery** still runs inside the decision pipeline (candidates + long-focused evaluation facts on one page). You usually do **not** need to open it unless inspecting or debugging. The former Evaluations page is merged into Discovery (`/evaluations` redirects to `/candidates`).

| Page (route) | What it is about | What data you see |
|--------------|------------------|-------------------|
| **Screener** (`/screeners`) | Eligibility rules (condition trees). Admits stocks; does not score or size them. | Screener definitions, run history, hit lists, schedules, optional backtests. |
| **Strategy** (`/strategy`) | One editable policy per portfolio (default Minervini). Scoring weights, thresholds, portfolio/cash rules, exits, market gates. | Configuration forms only — not a stock recommendation list. |
| **Discovery** (`/candidates`) | Candidate inventory (Screeners + pattern scans) **plus** Evaluation factor facts (score, confidence, explanation). Long-focused. | Candidates ranked with discovery reason and evaluation columns. |
| **Recommendations** (`/recommendations`) | Final trade ideas after Strategy scoring + filters + allocation. | Open / Increase / Reduce / Exit (actionable) plus HOLD / WATCH insights; Approve / Reject / Defer. |
| **Pending Execution** (`/transactions/pending`) | Approved ideas waiting for a broker fill. | Queue of approved buys/sells; cash may be reserved. |
| **Transactions** (`/transactions`) | Ledger of recorded fills. | Buy/sell history (source of truth for holdings). |
| **Review** (`/review`) | Outcomes after decisions and fills. | Performance / outcome tables and recent review decisions. |
| **Cash** (`/cash`) | Cash balance, reservations, available investable cash. | Ledger deposits/withdrawals and reservation details. |
| **Dashboard** (`/`) | Portfolio + market snapshot (not the recommendation list). | Value, allocation, market analytics, alerts, patterns. |

Supporting pages (Holdings, Watchlist, Explorer, Notifications) sit around this loop but are not the core recommendation stages.

---

## 1b. Is Discovery “needed”?

| Lens | Answer |
|------|--------|
| **Do I configure recommendations there?** | No. Configure eligibility on **Screener**, policy on **Strategy**, act on **Recommendations**. |
| **Does the pipeline still run Discovery → Evaluation?** | Yes. Evaluation facts are what Strategy scoring reads. Without a completed evaluation run, recommendation generation cannot run. |
| **Must I open Discovery daily?** | No. Opening it is optional (inspect candidates / factor facts). |
| **Where did Evaluations go?** | Merged into Discovery. Same engines and tables; one UI. |

---

## 1c. Discovery ↔ Evaluation (long-focused)

- **Discovery** builds candidates from screener hits, patterns, or holdings/watchlist fallback.
- **Evaluation** measures the same long-leaning factor facts for every candidate (trend, momentum, RS, volume, risk, pattern bonus). It does **not** flip to a sell viewpoint based on which screener produced the hit.
- Screeners have no bullish/bearish flag. Wire **entry** screeners under Strategy Eligibility Sources; wire **exit** screeners under Strategy → Screener Exit (holdings only).
- Link: `EvaluationRun.discovery_run_id` and `EvaluationResult.candidate_id`.

---

## 2. How a stock becomes a recommendation

High-level sequence (also run by the daily decision pipeline or **Run decision pipeline** on Recommendations):

```text
OHLCV / market data
        |
        v
Screener hits  ------------------+
        |                        |
        v                        |
Discovery candidates             |  (eligibility)
        |                        |
        v                        |
Evaluation (factor facts)        |  (shown on Discovery page)
        |                        |
        +------------------------+
        |
        v
Strategy config applied
  (score → thresholds → exits on holdings
   → portfolio rules → market gates
   → capital allocation + cash)
        |
        v
Recommendations page   ← you see surviving ideas HERE
        |
        |  Approve (actionable)
        v
Pending Execution  →  record fill on Transactions
        |
        v
Holdings updated  →  Review outcomes later
```

**Configure once, then generate:**

1. Build / run **Screeners** (e.g. Minervini Trend Template).
2. Set **Strategy** (eligibility sources, scoring, thresholds, exits, cash/gates).
3. Pipeline refreshes Discovery → Evaluation → **Recommendations**.
4. Approve on Recommendations → **Pending Execution** → ledger fill → **Review**.

---

## 3. Mental model (short)

| Stage | Owns | Does **not** |
|-------|------|--------------|
| Screener | Who is eligible | Ranking, position size, Approve |
| Strategy | How to score / filter / size / exit | Listing final ideas for Approve |
| Discovery (+ Evaluation UI) | Candidate inventory + long-focused factor facts | Final Open/Exit labels; sell-flipped scores |
| Recommendations | Final ideas + human review | Broker fills |
| Pending Execution / Transactions | Trade lifecycle / ledger | Scoring policy |
| Review | Learning from outcomes | Creating new eligibility rules |

---

## 4. In-app help

Same content is mirrored under Documentation → **Trading OS pages & flow** (`?q=trading-os-flow`) and **Discovery** (`?q=discovery`, aliases include `evaluations`), linked from Screener, Strategy, Discovery, Recommendations, and Review topics.
