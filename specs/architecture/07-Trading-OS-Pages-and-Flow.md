# Trading OS Pages and Recommendation Flow

| Field | Value |
|-------|-------|
| **Document** | Trading OS Pages and Recommendation Flow |
| **Audience** | Product users and agents |
| **Status** | Active |
| **Related** | [05-Daily-Decision-Pipeline.md](05-Daily-Decision-Pipeline.md), in-app Documentation topic `trading-os-flow` |

---

## 1. What each page is for

These are the main Trading OS navigation pages. Strategy is **configuration only** — it does not list recommended stocks.

**Day-to-day loop (what you configure / act on):** Screener → Strategy → Recommendations → Pending Execution → Transactions → Review.

**Discovery and Evaluations** are still run by the decision pipeline (they produce candidates and factor facts), but you usually do **not** need to open those pages to use a Strategy-based workflow — treat them as inspection / diagnostics unless you are debugging.

| Page (route) | What it is about | What data you see |
|--------------|------------------|-------------------|
| **Screener** (`/screeners`) | Eligibility rules (condition trees). Admits stocks; does not score or size them. | Screener definitions, run history, hit lists, schedules, optional backtests. |
| **Strategy** (`/strategy`) | One editable policy per portfolio (default Minervini). Scoring weights, thresholds, portfolio/cash rules, exits, market gates. | Configuration forms only — not a stock recommendation list. |
| **Discovery** (`/candidates`) | Pipeline inventory of candidates (Screeners + pattern scans). Used by Evaluation; optional to visit. | Candidate symbols / runs. |
| **Evaluations** (`/evaluations`) | Factor **facts** for candidates (RS, trend, momentum, risk, …). Required as pipeline data; optional to visit. | Evaluation runs and per-stock factor scores / evidence. |
| **Recommendations** (`/recommendations`) | Final trade ideas after Strategy scoring + filters + allocation. | Open / Increase / Reduce / Exit (actionable) plus HOLD / WATCH insights; Approve / Reject / Defer. |
| **Pending Execution** (`/transactions/pending`) | Approved ideas waiting for a broker fill. | Queue of approved buys/sells; cash may be reserved. |
| **Transactions** (`/transactions`) | Ledger of recorded fills. | Buy/sell history (source of truth for holdings). |
| **Review** (`/review`) | Outcomes after decisions and fills. | Performance / outcome tables and recent review decisions. |
| **Cash** (`/cash`) | Cash balance, reservations, available investable cash. | Ledger deposits/withdrawals and reservation details. |
| **Dashboard** (`/`) | Portfolio + market snapshot (not the recommendation list). | Value, allocation, market analytics, alerts, patterns. |

Supporting pages (Holdings, Watchlist, Explorer, Notifications) sit around this loop but are not the core recommendation stages.

---

## 1b. Are Discovery and Evaluations “needed”?

| Lens | Answer |
|------|--------|
| **Do I configure recommendations there?** | No. Configure eligibility on **Screener**, policy on **Strategy**, act on **Recommendations**. |
| **Does the pipeline still run them?** | Yes. Full pipeline: Discovery → Evaluation → Recommendation. Evaluation facts are what Strategy scoring reads. Without a completed evaluation run, recommendation generation cannot run. |
| **Must I open those tabs daily?** | No. Opening them is optional (inspect candidates / factor facts). |

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
Evaluations (factor facts)       |
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
3. Pipeline refreshes Discovery → Evaluations → **Recommendations**.
4. Approve on Recommendations → **Pending Execution** → ledger fill → **Review**.

---

## 3. Mental model (short)

| Stage | Owns | Does **not** |
|-------|------|--------------|
| Screener | Who is eligible | Ranking, position size, Approve |
| Strategy | How to score / filter / size / exit | Listing final ideas for Approve |
| Discovery | Candidate inventory | Final Open/Exit labels |
| Evaluations | Measured factor facts | Weighted overall strategy score as policy |
| Recommendations | Final ideas + human review | Broker fills |
| Pending Execution / Transactions | Trade lifecycle / ledger | Scoring policy |
| Review | Learning from outcomes | Creating new eligibility rules |

---

## 4. In-app help

Same content is mirrored under Documentation → **Trading OS pages & flow** (`?q=trading-os-flow`), linked from Screener, Strategy, Discovery, Evaluations, Recommendations, and Review topics.
