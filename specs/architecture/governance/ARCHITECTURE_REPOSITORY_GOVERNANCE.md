# Architecture Repository Governance

**Document:** Governance — Architecture Repository Governance  
**Version:** 1.2  
**Status:** Approved  
**Effective:** 2026-08-06  
**Architecture Repository Baseline:** **Version 1.0 (Frozen)** — see [ARCHITECTURE_REPOSITORY_BASELINE_V1.md](./ARCHITECTURE_REPOSITORY_BASELINE_V1.md)  
**Role:** Constitution for the StoX / Lido Portfolio architecture specification repository  

**Related:** [DOCUMENT_PRECEDENCE.md](./DOCUMENT_PRECEDENCE.md) · [SPECIFICATION_DECISIONS.md](./SPECIFICATION_DECISIONS.md) · [SPECIFICATION_HEADER_TEMPLATE.md](./SPECIFICATION_HEADER_TEMPLATE.md) · [ARCHITECTURE_REPOSITORY_BASELINE_V1.md](./ARCHITECTURE_REPOSITORY_BASELINE_V1.md) · [../README.md](../README.md) · [../platform/README.md](../platform/README.md) (Platform Architecture entry point)

---

## 1. Repository Philosophy

The architecture specification repository under `specs/architecture/` is the **authoritative architectural source** for StoX.

1. **Implementation must follow the specifications.** Code, schemas, APIs, and UI behaviour are expected to realise approved architectural intent.
2. **Specifications must not be rewritten to match implementation.** Drift between intent and code is recorded as accepted deviations (SD-xxx) in [SPECIFICATION_DECISIONS.md](./SPECIFICATION_DECISIONS.md), not by silently editing historical intent.
3. **Implementation should evolve to match approved specifications.** When specs are Approved (or Frozen for a release baseline), product work closes the gap toward that intent unless governance explicitly defers or accepts a deviation.
4. **Governance bridges intent and shipped scope.** Version baselines, MVP scope, and backlog decide what ships when; they do not erase long-term architecture.

This repository is organized for **long-term architectural clarity**, not for mirroring the order in which features were built.

---

## 2. Repository Organization

Folders represent **architectural domains**, not implementation phases, sprints, or release history.

| Domain folder | Role |
|---------------|------|
| **platform/** | **Platform Architecture** — entry point [../platform/README.md](../platform/README.md); vision, principles, concepts, system architecture, pipeline overview, system domain model |
| **portfolio/** | Portfolio workspaces and portfolio-facing analytics (dashboard, cash, discovery, holdings, watchlist, …) |
| **indicators/** | Indicator and trading-artifact architecture (registry, lifecycle, diagrams, framework overviews) |
| **data/** | Persistence and data-plane architecture (schema, data engine) |
| **domains/** | Cross-cutting business and pipeline contracts (engine specs, REST, strategy/screener/artifact detail, roadmap) |
| **ui/** | Presentation and navigation architecture |
| **live-trading/** | Live trading and order-execution subsystem |
| **integrations/** | External adapters (brokers, exchanges, notifications, AI providers) |
| **governance/** | Authority, scope, decisions, backlog, and this constitution |
| **audit/** | Point-in-time freeze evidence (read-mostly historical record) |
| **Future domains** | New top-level folders under `specs/architecture/` when a distinct architectural concern appears (prefer add over reshuffle) |

Hub indexes:

- Architecture hub: [../README.md](../README.md)
- Specs hub: [../../README.md](../../README.md)
- Repository docs map: [../../../DOCS.md](../../../DOCS.md)

Do not place documents by “when we built it” or “which epic number.” Place them by **which domain owns the concept**.

**Note:** “Platform Architecture” means the documents under [../platform/](../platform/), with canonical entry point [../platform/README.md](../platform/README.md). There is no file named `01-Platform-Architecture.md`.

---

## 2a. Architecture Repository Version 1.0 (Frozen)

The architecture repository structure and governance model are declared **Architecture Repository Version 1.0** and are **Frozen** as of [ARCHITECTURE_REPOSITORY_BASELINE_V1.md](./ARCHITECTURE_REPOSITORY_BASELINE_V1.md).

Frozen for repository process (extend, do not reshuffle):

- Repository organization  
- Domain organization  
- Naming conventions  
- Specification authoring rules (§7 + Golden Rule)  
- Cross-reference strategy  
- Repository governance  

**Future work should extend the repository rather than reorganize it.** Restructuring after V1.0 is extremely rare and requires architectural justification (§3, §11).

New specifications SHOULD use [SPECIFICATION_HEADER_TEMPLATE.md](./SPECIFICATION_HEADER_TEMPLATE.md).

---

## 3. Repository Stability

The repository **structure is considered stable**.

1. **Prefer adding** new specifications in the correct domain folder.
2. **Do not relocate** existing specifications unless there is a significant architectural reason (e.g. a concept is clearly misclassified and misleads readers).
3. **Avoid unnecessary file renaming.** Filenames are part of the cross-reference surface.
4. **Avoid unnecessary numbering changes.** Existing numbered architecture docs (`01`–`15`, live-trading `00`–`03`) retain their numbers; gaps (e.g. missing `12`) are allowed and must not be “fixed” by renumbering.
5. Large reorganizations require architectural review (see §11). Record outcomes in [MIGRATION-SUMMARY.md](../MIGRATION-SUMMARY.md) when structure changes.

Stability protects links, citation, audit packs, and agent ingest paths.

---

## 4. Specification Lifecycle

Every specification should declare a lifecycle **Status** in its header (or equivalent metadata).

| State | Meaning | Typical use |
|-------|---------|-------------|
| **Draft** | Work in progress; may change freely | New domains, early design, exploratory subsystems |
| **Review** | Content-complete enough for architectural review | Circulating for feedback before approval |
| **Approved** | Accepted as current architectural intent | Active reference for implementation and further specs |
| **Frozen** | Locked for a named baseline or release | Paired with [VERSION_1_BASELINE.md](./VERSION_1_BASELINE.md) or similar; change only via explicit governance |
| **Deprecated** | Superseded or no longer recommended; retained for history | Point readers to the replacement document |
| **Archived** | Historical only; not used for new design | Completed migrations, obsolete analyses kept for audit |

Rules:

- **Draft / Review** may iterate without SD-xxx entries.
- **Approved** changes that alter intent should be reviewed; material deviations in code need SD-xxx or backlog items.
- **Frozen** documents are not casually edited; amend via governance process.
- **Deprecated / Archived** must remain linkable; prefer status + pointer over deletion.

---

## 5. Naming Conventions

### 5.1 Folder naming

- Use lowercase `kebab-case` or single lowercase tokens: `live-trading`, `platform`, `domains`.
- One folder = one architectural domain.
- Do not encode release names or dates in folder names.

### 5.2 Specification naming

- Prefer descriptive `Title-Case-With-Hyphens.md` for domain contracts (e.g. `REST-API-Specification.md`).
- Platform foundational docs may keep the established numeric prefix: `NN-Short-Name.md`.
- Subsystem series (e.g. live trading) may use `NN-topic.md` within that folder only.
- Do not rename files solely for cosmetic consistency.

### 5.3 Numbering rules

- Numbers establish **reading order within a series**, not global uniqueness across the repository.
- Never renumber existing published documents to close gaps.
- New docs in a numbered series: append the next free number; do not insert by shifting others.
- Unnumbered contracts are acceptable in `domains/`, `portfolio/`, `data/`, etc.

### 5.4 Cross-reference conventions

- Use relative Markdown links between specs.
- Prefer paths from the linking file (e.g. `../platform/03-Core-Concepts.md`).
- When citing from outside `specs/`, use repo-root paths (`specs/architecture/...`).
- Keep link text accurate; after moves, update href and visible path text together.

### 5.5 README conventions

- Every domain folder should have a `README.md` when it is more than a single file, or when it is a placeholder domain (`integrations/`).
- Domain READMEs state purpose, contents, and reading tips; they do not redefine platform concepts.
- The architecture hub README ([../README.md](../README.md)) remains the primary folder index and reading-order guide.

---

## 6. Architectural Principles

Specifications and designs under this repository shall honour:

| Principle | Meaning |
|-----------|---------|
| **Configuration over code** | Prefer declarative config, registries, and versioned artifacts over hard-coded behaviour when the domain allows |
| **Reuse rather than duplication** | Shared concepts live once (platform / domain model / artifact framework); other specs reference them |
| **Single source of truth** | Each concept has one authoritative definition; inventories and audits cite it |
| **Domain-driven organization** | Folder and document placement follow architectural ownership |
| **Deterministic behaviour** | Same inputs and configuration yield explainable, repeatable outcomes where the domain requires it |
| **Explainability** | Recommendations, gates, and scores must be justifiable from declared rules and evidence |
| **Auditability** | Decisions, executions, and material changes leave a reconstructable record |
| **Extensibility** | New strategies, indicators, integrations, and brokers should plug in without rewriting core platform intent |

These principles align with Platform Architecture; domain specs refine them for their scope without contradicting them.

---

## 7. Specification Authoring Principles

**Mandatory.** Every future StoX architecture specification SHALL comply with this section. Reviewers SHALL reject Draft/Review specs that violate these principles without remediation.

**Normative summary (always true):**

1. Every specification belongs to **exactly one** architectural domain.  
2. Every reusable concept has **exactly one** canonical specification.  
3. Specifications **shall reference** canonical concepts instead of redefining them.  
4. Architecture shall describe **abstractions** rather than implementation technologies (unless explicitly as-built / migrate).  
5. Platform concepts belong in **Platform Architecture** ([../platform/README.md](../platform/README.md)).  
6. Repository restructuring shall be **extremely rare** and require architectural justification.

### 7.1 Single Source of Truth

Every architectural concept shall have **exactly one** authoritative specification.

Examples of concepts that require a single canonical home (when introduced):

- Run  
- State Machine  
- Registry  
- Policy  
- Artifact  
- Connector  
- Event  
- Operational Control  
- Security  
- Audit  

That specification becomes the **canonical source of truth**. Other documents may specialize behaviour for a domain, but they must not become a second definition of the same concept.

### 7.2 No Concept Duplication

A specification **SHALL NOT** redefine a concept that already has an approved specification.

Instead it **SHALL** reference the canonical specification.

| Incorrect | Correct |
|-----------|---------|
| “The Risk Engine defines what a Run is…” | “The Risk Engine creates Risk Runs as defined by the Platform Architecture.” |

This applies to **all** reusable concepts, not only Runs.

### 7.3 Reference Before Rewriting

When writing a new specification:

1. **Search** the architecture repository (`specs/architecture/` and indexes in [../README.md](../README.md) / [../../../DOCS.md](../../../DOCS.md)).
2. **Determine** whether the concept already exists (Approved, Frozen, or clearly owned Draft).
3. **If it exists:** reference it. **Do not rewrite it.**

Only create a new canonical definition when no suitable owner exists, and place it in the correct domain (prefer Platform when cross-cutting — see §7.5).

### 7.4 Domain Ownership

Every specification belongs to **exactly one** architectural domain.

Examples: Platform, Portfolio, Indicators, Live Trading, Data, UI, Integrations, Governance, Audit, and future domains.

Specifications should never exist outside a domain folder without architectural justification recorded in governance.

### 7.5 Platform First

Whenever a concept is applicable across **multiple** domains, it belongs in **Platform Architecture** (`platform/`) rather than a feature-specific specification.

Examples of platform-first concepts:

- Run Framework  
- State Machines  
- Events  
- Operational Controls  
- Registries (shared patterns)  
- Policies  
- Artifacts (shared envelope patterns)  
- Connectors  
- Naming Conventions  

Domain specs then **implement or specialize** those platform concepts for their scope.

### 7.6 Reuse Over Duplication

Specifications should build upon previously defined concepts rather than restating them.

Preferred pattern:

> “This subsystem implements the Platform Run Framework.”

instead of repeating the Run lifecycle.

### 7.7 Consistent Terminology

Every specification **SHALL** use terminology defined in the canonical glossary / core concepts (see §9).

- Do not introduce alternate names for existing concepts.  
- One concept shall have **one preferred term**.

### 7.8 Architecture Before Implementation

Specifications describe the **desired architecture**.

- Implementation follows the specification.  
- Specifications should **not** be rewritten merely because the implementation differs.  
- Implementation should evolve toward the approved architecture (record SD-xxx when a temporary or permanent deviation is accepted).

### 7.9 Stable Repository Structure

The architecture repository structure is considered **stable**.

- New specifications should be **added**.  
- Existing specifications should **not** be reorganized unless there is a significant architectural reason (see §3 and §11).

### 7.10 Future Platform Growth

When introducing a new architectural concept, first determine whether it is:

- **Platform-wide**, or  
- **Domain-specific**

Platform concepts belong in Platform Architecture. Domain concepts belong in the owning domain.

**Avoid creating duplicate concepts in multiple domains.**

---

## 8. Writing Guidelines

Authors of new specifications shall apply §7 in full, and specifically:

1. **Identify the target architectural domain** before writing.
2. **Reuse existing Platform Architecture concepts** (vision, principles, core concepts, system architecture, system domain model, pipeline overview).
3. **Reference existing specifications** instead of redefining Strategy, Screener, Indicator, Recommendation, Holding, Run, Registry, Policy, Artifact, etc.
4. **Avoid duplication** of prose that already exists in an authoritative doc; summarize and link.
5. **Maintain terminology consistency** with §9.
6. **Remain implementation-independent** in intent docs: describe behaviour and boundaries, not framework trivia, unless the document is explicitly an as-built analysis or migrate guide. Architecture describes **abstractions**, not languages, frameworks, or vendors.  
7. **Use the standard header** from [SPECIFICATION_HEADER_TEMPLATE.md](./SPECIFICATION_HEADER_TEMPLATE.md) for new documents.  
8. **Index new documents** in [../../../DOCS.md](../../../DOCS.md), [../../README.md](../../README.md), and [../README.md](../README.md) (and this governance pack when appropriate) in the same change set.

---

## 9. Terminology

1. **Canonical platform terminology** starts from [../platform/03-Core-Concepts.md](../platform/03-Core-Concepts.md) and [../platform/System-Domain-Model.md](../platform/System-Domain-Model.md).
2. **Subsystem glossaries** (e.g. [../live-trading/00-glossary.md](../live-trading/00-glossary.md)) define terms local to that subsystem. They must not redefine platform terms with conflicting meanings; extend or specialize only with clear naming.
3. All specifications **shall use glossary and core-concept terminology consistently**.
4. When a new term is required, define it once in the owning domain’s glossary or core concepts, then reference it elsewhere.
5. Prefer the StoX / product names already established in Approved specs over inventing synonyms.

---

## 10. Cross References

1. **Prefer Markdown links** to the authoritative document.
2. **Avoid copying content** between specs; duplication causes drift.
3. **Reference Platform Architecture** whenever a concept is platform-wide — start at [../platform/README.md](../platform/README.md), then the owning platform document (system architecture, pipeline, engine overview, domain model, core concepts).
4. Cross-domain dependencies should be explicit in a short “Depends On” / “Related” header where helpful.
5. Governance documents ([DOCUMENT_PRECEDENCE.md](./DOCUMENT_PRECEDENCE.md)) resolve conflicts when specs disagree; do not silently overwrite older intent.
6. New specs SHALL cite this constitution’s **Specification Authoring Principles** (§7) when introducing reusable concepts.

---

## 11. Change Management

| Change type | Expectation |
|-------------|-------------|
| **Minor addition** (new Draft spec in the correct folder, README index updates) | Must still obey §7; no structural review required beyond normal authoring quality |
| **Approved intent change** within an existing domain | Architectural review; update dependents and indexes |
| **Platform concept change** (core concepts, domain model, system architecture) | Not casual — review required; assess impact across domains |
| **Large repository reorganization** (renames, mass moves, folder splits) | Architectural review required; preserve Git history (`git mv`); update all references; record in [MIGRATION-SUMMARY.md](../MIGRATION-SUMMARY.md) |
| **Accepted code vs spec deviation** | Record as SD-xxx in [SPECIFICATION_DECISIONS.md](./SPECIFICATION_DECISIONS.md); do not rewrite the intent doc to hide the gap |
| **Concept duplication / second definition** | Rejected as a governance violation of §7; consolidate to one canonical owner |

Authority ordering for conflicts remains [DOCUMENT_PRECEDENCE.md](./DOCUMENT_PRECEDENCE.md). Authoring conflicts (duplicate concepts, wrong domain, missing references) are resolved by this constitution (§7 / Golden Rule).

---

## 12. Future Evolution

1. **Introduce new architectural domains** by adding a new folder under `specs/architecture/` with a `README.md` that states purpose and boundaries.
2. **Prefer adding** new domain folders over reorganizing existing content.
3. **Evolve organization only when justified** by architectural growth (new subsystem, clear ownership split), not by preference or temporary project structure.
4. New domains must declare how they relate to **platform**, **data**, and any overlapping domains (e.g. live-trading vs execution engine specs).
5. Placeholder domains (such as [../integrations/](../integrations/)) may exist before specs are written; they still follow this constitution once documents appear.
6. New platform-wide concepts are added under [../platform/](../platform/) first; domains then reference them (Platform First, §7.5).

---

## 13. Golden Rule

> **A specification shall never duplicate a concept that already has an approved specification. It shall reference the canonical specification instead.**

This principle is **mandatory** for all future architecture documents. It supersedes local drafting preference whenever conflict arises.

---

## Document control

| Field | Value |
|-------|-------|
| Owner | Architecture / Product Specification |
| Review | Architectural review for material amendments |
| Supersedes | None (complements DOCUMENT_PRECEDENCE; v1.2 freezes Architecture Repository V1.0 baseline + header template) |
