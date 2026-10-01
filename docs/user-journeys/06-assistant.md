# StoX User Journeys — Documentation assistant

## AI-01 — Ask a grounded product question

1. Sign in and choose **Ask StoX** in the global header.
2. Ask a product question such as “How do I create a screener?”
3. Read the answer and its non-numeric grounding state.
4. Expand **Sources used** for titles, sections, links and supporting snippets.
5. Use a source link to navigate to maintained documentation. Existing authorization still applies to application pages.

Expected result: the assistant quotes supporting documentation. It cannot change data, execute trades, fetch hidden account data or provide investment advice. If documentation is insufficient, it says so and offers deterministic **How do I?** help.

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
