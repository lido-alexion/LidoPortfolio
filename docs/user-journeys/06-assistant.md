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
