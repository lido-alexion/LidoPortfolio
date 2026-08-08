# Specification Header Template

**Document:** Governance — Specification Header Template  
**Status:** Approved  
**Version:** 1.0  
**Owner:** Architecture  
**Last Updated:** 2026-08-06  
**Implementation Status:** N/A (template only)  
**Depends On:** [ARCHITECTURE_REPOSITORY_GOVERNANCE.md](./ARCHITECTURE_REPOSITORY_GOVERNANCE.md)  
**Referenced By:** Future specifications (authoring standard)  
**Related Specifications:** [ARCHITECTURE_REPOSITORY_BASELINE_V1.md](./ARCHITECTURE_REPOSITORY_BASELINE_V1.md)  

---

## Purpose

This is the **standard metadata header** for new StoX architecture specifications.

- Use this template for **new** documents.  
- **Do not** bulk-rewrite existing specifications solely to adopt the header.  
- Existing docs may adopt it opportunistically when next edited for content.

Authoring rules: [ARCHITECTURE_REPOSITORY_GOVERNANCE.md](./ARCHITECTURE_REPOSITORY_GOVERNANCE.md) §7.

---

## Template (copy into new specs)

```markdown
# <Title>

| Field | Value |
|-------|-------|
| **Title** | <Full document title> |
| **Status** | Draft \| Review \| Approved \| Frozen \| Deprecated \| Archived |
| **Version** | <e.g. 0.1, 1.0> |
| **Owner** | <Architecture \| Product Specification \| …> |
| **Last Updated** | <YYYY-MM-DD> |
| **Implementation Status** | Not started \| Partial \| Implemented \| N/A (intent only) |
| **Depends On** | <links to prerequisite specs> |
| **Referenced By** | <known dependents, or “TBD”> |
| **Related Specifications** | <sibling / specialization links> |

---
```

---

## Field guidance

| Field | Guidance |
|-------|----------|
| **Title** | Human-readable name; match filename intent |
| **Status** | Lifecycle from [ARCHITECTURE_REPOSITORY_GOVERNANCE.md](./ARCHITECTURE_REPOSITORY_GOVERNANCE.md) §4 |
| **Version** | Spec version, not app release version |
| **Owner** | Architectural owner (domain), not a person name unless required |
| **Last Updated** | Date of last material content change |
| **Implementation Status** | How far code matches this intent (orthogonal to Status) |
| **Depends On** | Prerequisites the reader should open first |
| **Referenced By** | Reverse links when known; update when dependents are added |
| **Related Specifications** | Non-prerequisite related docs (specializations, migrate guides) |

---

## Conventions

1. Prefer Markdown tables or definition lists — keep fields visible near the top.  
2. Use relative links for Depends On / Related / Referenced By.  
3. Status **Approved** does not imply Implementation Status **Implemented**.  
4. Domain ownership remains exactly one folder under `specs/architecture/` (see governance §7.4).
