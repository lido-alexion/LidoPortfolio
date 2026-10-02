# StoX User Journeys — Assistant

## AI-01 — Ask a grounded product question

1. Sign in and choose **Ask StoX** in the global header.
2. Ask a product question such as “How do I create a screener?”
3. Read the answer and its non-numeric grounding state.
4. Expand **Sources used** for titles, sections, links and supporting snippets.
5. Use a source link to navigate to maintained documentation. Existing authorization still applies to application pages.

Expected result: Documentation help quotes supporting documentation. This mode cannot change data, execute trades, fetch hidden account data or provide investment advice. If documentation is insufficient, it says so and offers deterministic **How do I?** help.

## AI-02 — Follow up, copy and give feedback

1. Ask a follow-up or select an explanation prompt below an answer.
2. Choose **Copy response** to copy the answer and source links.
3. Choose **Helpful**, or expand **Not helpful**, optionally enter a comment and send feedback.
4. Choose **Clear conversation** to remove the current conversation and cancel a pending answer.

Expected result: context exists only in the current mounted session; refresh or sign-out clears it. Feedback is linked to the answer's internal audit evidence. Provider and model details are not shown.

## AI-03 — Recover when AI is unavailable

1. Open the assistant while the runtime is unavailable or its budget is exhausted.
2. Ask a question and observe the temporary-unavailability message.
3. Use **How do I?** links or **Browse documentation**.
4. Close the drawer with **Close assistant** or Escape. Focus returns to **Ask StoX**.

Expected result: normal StoX functionality and deterministic help remain available. Mobile users can scroll the drawer without leaving their current page.


## AI-04 — Investigate account evidence

1. Open **Ask StoX** and select **Account investigation and actions**.
2. Ask an account question, such as “How concentrated are my holdings?”
3. Read the answer and any missing-evidence disclosure.
4. Expand **Investigation trace** to see which tools supplied evidence.

Expected result: the assistant uses authorized account reads and StoX calculations. Missing prices or unsupported analytics remain unavailable. Broker trading is unavailable.

## AI-05 — Preview and approve changes

1. In account mode, request a supported change, such as creating a watchlist.
2. Review **Proposed changes**, affected objects, warnings and **Review field changes**.
3. Choose **Approve changes** or **Reject plan**. Deletions also require the explicit deletion checkbox.
4. Read the verified action results. If state changed or approval expired, choose **Build a fresh plan** and review the new preview.

Expected result: no business change occurs before explicit approval. A plan expires after five minutes. Library draft creation does not publish, bind or activate it. Execution stops on an unexpected failure and reports completed, failed and unattempted actions.

## AI-06 — Inspect action history and recover

1. Open **Run history** in the existing assistant drawer.
2. Review a run's original objective, portfolio, preview, trace and action results.
3. For a failed, partial, expired or stale run, choose **Build a fresh plan**.
4. Review and approve any new mutations separately.

Expected result: history persists safe action records. Retrying never replays an old approval. **Clear conversation** clears the current drawer session; it does not delete action history.

## AI-07 — Open a stock insight

1. Choose the AI puzzle icon beside a stock in Holdings, either Dashboard stock context, Watchlist rows, the selected Watchlist stock, or either Stock Explorer result card.
2. Read the structured AI Insights and **Based on / Data used** dates. Missing evidence is disclosed.
3. Held stocks show **Personalized with your active portfolio holding**. Other portfolios and private watchlist notes are excluded.
4. Choose **Copy insight**, **Refresh insight**, or **Open stock details**.
5. Close the section, pane or modal to continue using the page.

Expected result: single-stock contexts expand inline. Dense contexts use a right pane on extra-large screens and a near-full-page modal otherwise. Generation starts only when requested. A matching saved insight is reused; changing providers alone does not invalidate it. Stock insights are analytical, without trading recommendations or target prices.

## AI-08 — Recover an embedded insight

1. Open an insight or choose **Refresh insight** while managed AI is unavailable.
2. If a matching saved result exists, continue reading it with the degraded status.
3. Choose **Copy AI Prompt** to use the existing manual prompt workflow.

Expected result: failed refreshes never replace a valid saved result with partial output. With no valid saved result, the section explains unavailability. Normal non-AI pages remain usable.

## AI-09 — Design and explicitly create a draft strategy

1. Open **AI Strategy Designer** on Strategy and choose the structured design inputs.
2. Choose **Generate strategy**. Read the summary, entry/exit logic, sizing, risk controls, assumptions, caveats and artifact compatibility notes.
3. Use **Copy result** or **Regenerate** as needed. Returning to matching inputs restores the account's saved result; changed inputs hide mismatched results.
4. Optionally choose **Create draft strategy**. Review the proposed changes and choose **Approve changes** or **Reject plan**.
5. Read the verified result and inspect the run in assistant history. Stale or expired plans require a fresh preview and approval.

Expected result: generation is advisory and never mutates a strategy. Creation uses the existing governed action lifecycle and creates only a Library draft. Invalid artifact envelopes cannot be created. Managed failure exposes **Copy AI Prompt**; prompts and provider identities are hidden during normal success.
