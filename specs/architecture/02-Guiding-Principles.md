# 02 -- Guiding Principles

  Field            Value
  ---------------- --------------------------
  **Document**     02 -- Guiding Principles
  **Version**      0.1
  **Status**       Draft
  **Owner**        Architecture
  **Depends On**   01 -- Vision

------------------------------------------------------------------------

# 1. Purpose

This document defines the architectural principles that govern every
future design decision. When multiple approaches are possible, these
principles take precedence over convenience or implementation
simplicity.

------------------------------------------------------------------------

# 2. Principle 1 --- Trust Over Automation

Automation is not the objective. User trust is.

No feature shall reduce the user's confidence in the system merely to
increase automation.

------------------------------------------------------------------------

# 3. Principle 2 --- Explainability First

Every recommendation must be explainable.

At a minimum, every recommendation shall answer:

-   Why was it generated?
-   Which rules passed?
-   Which rules failed?
-   What evidence contributed?
-   Why is it ranked above or below alternatives?

No recommendation may exist without supporting evidence.

------------------------------------------------------------------------

# 4. Principle 3 --- Deterministic Behaviour

Identical inputs must always produce identical outputs.

The system shall avoid probabilistic, AI-driven or non-reproducible
decision making.

------------------------------------------------------------------------

# 5. Principle 4 --- Human Remains in Control

The system augments decision making.

The user always retains the ability to review, override or reject
recommendations and executions.

------------------------------------------------------------------------

# 6. Principle 5 --- Business Before Technology

Architecture is defined in terms of business capabilities, not
implementation technologies.

Core documents shall avoid technology-specific decisions.

------------------------------------------------------------------------

# 7. Principle 6 --- Engine-Oriented Design

Business responsibilities belong to engines rather than UI pages.

Pages consume engines; engines do not depend on pages.

------------------------------------------------------------------------

# 8. Principle 7 --- Single Source of Truth

Every business concept shall have exactly one authoritative owner.

Duplicate calculations and duplicated business rules should be avoided.

------------------------------------------------------------------------

# 9. Principle 8 --- Evidence Over Opinion

Every decision produced by the platform should be traceable back to
objective market data, deterministic calculations and configured rules.

------------------------------------------------------------------------

# 10. Principle 9 --- Simplicity

Prefer the simplest architecture that satisfies the business
requirement.

Avoid abstractions introduced solely for hypothetical future needs.

------------------------------------------------------------------------

# 11. Principle 10 --- Incremental Evolution

The platform shall evolve through clearly defined stages:

1.  Decision Support
2.  Assisted Execution
3.  Trusted Automation

No stage shall compromise transparency established by previous stages.

------------------------------------------------------------------------

# 12. Architecture Decision Checklist

Every future feature should answer "Yes" to most of these questions:

-   Does it improve trading decisions?
-   Does it reduce repetitive work?
-   Is it deterministic?
-   Is it explainable?
-   Does it fit an existing engine?
-   Can it be tested independently?
-   Does it preserve user trust?
-   Is it simple?

If the answer is "No" to multiple questions, reconsider the design.

------------------------------------------------------------------------

# 13. Summary

These principles are the constitution of the trading operating system.
Future specifications and implementations must comply with them. Where
conflicts arise, these principles take precedence unless an Architecture
Decision Record explicitly supersedes them.
