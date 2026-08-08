# Architecture Repository Baseline — Version 1.0

**Document:** Governance — Architecture Repository Baseline V1.0  
**Status:** Frozen  
**Version:** 1.0  
**Owner:** Architecture  
**Last Updated:** 2026-08-06  
**Implementation Status:** N/A (repository governance)  
**Depends On:** [ARCHITECTURE_REPOSITORY_GOVERNANCE.md](./ARCHITECTURE_REPOSITORY_GOVERNANCE.md)  
**Related Specifications:** [SPECIFICATION_HEADER_TEMPLATE.md](./SPECIFICATION_HEADER_TEMPLATE.md) · [DOCUMENT_PRECEDENCE.md](./DOCUMENT_PRECEDENCE.md) · [../platform/README.md](../platform/README.md) · [../README.md](../README.md)  

---

## 1. Declaration

**Architecture Repository Version 1.0** is hereby declared the **stable architectural baseline** for StoX specification authoring.

As of this baseline, the following are **Frozen** for repository *structure and process* (not a claim that every product SHALL is fully implemented in code):

| Stable element | Meaning |
|----------------|---------|
| Repository organization | Domain folders under `specs/architecture/` |
| Domain organization | platform, domains, portfolio, indicators, data, ui, live-trading, integrations, governance, audit |
| Naming conventions | Per constitution §5 |
| Specification authoring rules | Constitution §7 + Golden Rule |
| Cross-reference strategy | Prefer links; no concept duplication |
| Repository governance | This pack + DOCUMENT_PRECEDENCE |

**Future work shall extend the repository rather than reorganize it.**

Structural change after V1.0 requires architectural justification per constitution §3 / §11 and an update to [../MIGRATION-SUMMARY.md](../MIGRATION-SUMMARY.md).

---

## 2. Canonical entry points

| Concern | Entry point |
|---------|-------------|
| Architecture hub | [../README.md](../README.md) |
| Platform Architecture | [../platform/README.md](../platform/README.md) |
| Repository constitution | [ARCHITECTURE_REPOSITORY_GOVERNANCE.md](./ARCHITECTURE_REPOSITORY_GOVERNANCE.md) |
| Spec header template | [SPECIFICATION_HEADER_TEMPLATE.md](./SPECIFICATION_HEADER_TEMPLATE.md) |
| Conflict resolution | [DOCUMENT_PRECEDENCE.md](./DOCUMENT_PRECEDENCE.md) |
| Docs map | [../../../DOCS.md](../../../DOCS.md) |

---

## 3. Future authoring rules (normative summary)

1. Every specification belongs to **exactly one** architectural domain.  
2. Every reusable concept has **exactly one** canonical specification.  
3. Specifications **shall reference** canonical concepts instead of redefining them.  
4. Architecture shall describe **abstractions**, not implementation technologies (unless the doc is explicitly as-built / migrate).  
5. Platform concepts belong in **Platform Architecture** (`platform/`).  
6. Repository restructuring shall be **extremely rare** and require architectural justification.

Full text: [ARCHITECTURE_REPOSITORY_GOVERNANCE.md](./ARCHITECTURE_REPOSITORY_GOVERNANCE.md).

---

## 4. Repository completion report (baseline freeze)

### 4.1 Repository health assessment

| Area | Assessment |
|------|------------|
| Domain folder layout | Healthy — stable V1.0 |
| Governance / authoring rules | Healthy — constitution + Golden Rule + header template |
| Platform entry point | Healthy — `platform/README.md` |
| Indexes (DOCS, specs README, architecture README) | Healthy |
| Internal markdown links (spot + full scan at freeze) | **572 checked, 0 broken** (specs + DOCS.md + implementation.md; deploy-relative HTML excluded) |
| Terminology | Core Concepts + subsystem glossaries in place; some platform abstractions still TBD |

**Overall:** Suitable as the **stable architectural knowledge-base baseline** for continuing specification authoring and implementation *against* Approved intent.

### 4.2 Remaining documentation issues

| Issue | Severity | Notes |
|-------|----------|-------|
| Mixed Status on older platform docs (many still “Draft”) | Low | Content is foundational; promote Status opportunistically — do not mass-edit |
| Heterogeneous header formats on legacy specs | Low | Header template is for **new** docs; no bulk rewrite |
| `Security` term overload (instrument vs app security) | Medium (terminology) | Called out in Platform Architecture map; future specs must disambiguate |
| StoX AI guide / public docs paths | Watch | Generator must keep writing to `domains/` |

### 4.3 Missing architectural documents (known gaps)

These are **future authoring** targets under Platform (or Integrations), not restructuring work:

- Platform **Run Framework**  
- Platform **State Machine** pattern  
- Platform **Configuration** framework  
- Platform **Policy** framework  
- Platform **Connector** abstraction  
- Platform **Event** model (beyond domain-model sketch)  
- Platform **Operational Control** framework  
- Platform **Monitoring** / observability  
- Integration specs under `integrations/` (Zerodha, NSE/BSE, Telegram, Email, AI providers, …)  
- Continued Live Trading series beyond current Draft suite  

### 4.4 Recommendations for future specifications

1. Start from [../platform/README.md](../platform/README.md) and Core Concepts.  
2. Apply [SPECIFICATION_HEADER_TEMPLATE.md](./SPECIFICATION_HEADER_TEMPLATE.md).  
3. Obey §7 authoring principles before drafting.  
4. Prefer adding docs; do not move/rename/renumber existing files.  
5. Record SD-xxx when implementation intentionally differs from Approved intent.

### 4.5 Baseline confirmation

**Yes — the architecture repository can be considered the stable architectural baseline (Architecture Repository Version 1.0) for StoX.**

Product implementation baselines (e.g. TOS-V1.0 code freeze) remain governed separately by [VERSION_1_BASELINE.md](./VERSION_1_BASELINE.md) and are not replaced by this declaration.
