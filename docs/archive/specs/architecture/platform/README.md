# Platform Architecture

**Document:** Platform Architecture (entry point)  
**Status:** Approved  
**Version:** 1.0  
**Owner:** Architecture  
**Last Updated:** 2026-08-06  
**Implementation Status:** Intent (foundational docs Draft/Approved mix; see individual files)  
**Depends On:** [../governance/ARCHITECTURE_REPOSITORY_GOVERNANCE.md](../governance/ARCHITECTURE_REPOSITORY_GOVERNANCE.md)  
**Related Specifications:** Documents listed below under this folder  

---

## 1. Purpose

This README is the **canonical entry point** for **Platform Architecture**.

Platform Architecture is the set of approved (and foundational Draft) specifications under `specs/architecture/platform/`. Together they define reusable, product-wide concepts for StoX.

There is **no** separate file named `01-Platform-Architecture.md`. Cite this entry point (`platform/README.md`) or the specific numbered platform document that owns a concept.

**Do not redefine** platform concepts in domain specs. Reference this suite instead ([ARCHITECTURE_REPOSITORY_GOVERNANCE.md](../governance/ARCHITECTURE_REPOSITORY_GOVERNANCE.md) §7).

---

## 2. Reading order (platform suite)

| Order | Document | Role |
|------:|----------|------|
| 1 | [01-Vision.md](./01-Vision.md) | Product vision and objectives |
| 2 | [02-Guiding-Principles.md](./02-Guiding-Principles.md) | Trust, explainability, determinism, human control |
| 3 | [03-Core-Concepts.md](./03-Core-Concepts.md) | **Canonical vocabulary** (glossary for the TOS) |
| 4 | [04-System-Architecture.md](./04-System-Architecture.md) | Business engines and collaboration |
| 5 | [05-Daily-Decision-Pipeline.md](./05-Daily-Decision-Pipeline.md) | Orchestration of the daily decision stages |
| 6 | [06-Engine-Overview.md](./06-Engine-Overview.md) | Engine ownership boundaries |
| 7 | [System-Domain-Model.md](./System-Domain-Model.md) | Shared entities and relationships |

Hub: [../README.md](../README.md)

---

## 3. Reusable platform concepts — coverage map

The following concepts are expected to have **one** canonical home. Today, coverage is spread across the platform suite (and a few specialized domain overviews). Use the **Canonical reference** column; do not invent a second definition.

| Concept | Coverage today | Canonical reference(s) | Notes |
|---------|----------------|------------------------|-------|
| **Configuration** | Named as cross-cutting | [04-System-Architecture.md](./04-System-Architecture.md) §7; Strategy/Screener config in domains | Dedicated Platform Configuration Framework spec **not yet authored** |
| **Registry** | Indicator + Artifact registries | [03-Core-Concepts.md](./03-Core-Concepts.md); [09-Indicator-Registry.md](../indicators/09-Indicator-Registry.md); [11-Trading-Artifact-Framework.md](../indicators/11-Trading-Artifact-Framework.md) | Specializations exist; shared Registry *pattern* may still be generalized in platform later |
| **Policy** | Implicit (gates, eligibility, market gates) | Strategy / Screener / Market Analysis domain specs | Dedicated Platform Policy Framework spec **not yet authored** |
| **Business Engine** | Fully described | [04-System-Architecture.md](./04-System-Architecture.md); [06-Engine-Overview.md](./06-Engine-Overview.md) | Primary platform pattern for capability ownership |
| **Orchestration Engine / Pipeline** | Daily stages | [05-Daily-Decision-Pipeline.md](./05-Daily-Decision-Pipeline.md) | Pipeline is the orchestration expression of the daily decision loop |
| **Run** | Appears in ScreenerRun and similar domain entities | Domain specs (e.g. Screener); System Domain Model additions | Shared **Run Framework** (platform) **not yet authored** |
| **State Machine** | Lifecycles described per concept | [03-Core-Concepts.md](./03-Core-Concepts.md); [System-Domain-Model.md](./System-Domain-Model.md) §5 | Generic State Machine framework **not yet authored** |
| **Artifact** | Trading Artifact Framework | [03-Core-Concepts.md](./03-Core-Concepts.md); [11-Trading-Artifact-Framework.md](../indicators/11-Trading-Artifact-Framework.md) | Canonical reusable definition pattern |
| **Connector** | Implied by data providers / brokers / notifications | Integrations placeholder; Data Engine; Live Trading | Platform **Connector** abstraction **not yet authored** — prefer [../integrations/](../integrations/) for provider specs |
| **Event** | Event flow sketched | [System-Domain-Model.md](./System-Domain-Model.md) §7 | Platform Event model **not yet fully specified** |
| **Operational Control** | Human control / stages | [02-Guiding-Principles.md](./02-Guiding-Principles.md); pipeline stages; Live Trading controls | Dedicated Operational Control framework **not yet authored** |
| **Security** | Entity “Security” (instrument) vs app security | Domain model = instrument; Live Trading has auth/security specs | Distinguish **Security (instrument)** vs **application security** — do not conflate terms |
| **Audit** | Cross-cutting auditing concern | [04-System-Architecture.md](./04-System-Architecture.md) §7; [../audit/](../audit/) (evidence pack) | Platform Audit *capability* intent vs freeze *audit pack* folder — different roles |
| **Monitoring** | Not yet a first-class platform concept | — | Platform Monitoring / observability spec **not yet authored** |

When authoring a new platform-wide concept, add or extend a document under this folder and update this table. Domain specs then **reference** it.

---

## 4. How other domains use Platform Architecture

1. Search this entry point and Core Concepts before defining a term.  
2. If the concept is multi-domain → extend **Platform** (this folder).  
3. If the concept is owned by one domain → write under that domain and link back to platform vocabulary.  
4. Prefer: *“implements / specializes …”* over restating lifecycle prose.

---

## 5. Indexing

New platform documents must be listed in this README, [../README.md](../README.md), and [../../../DOCS.md](../../../DOCS.md) (via repository map updates) in the same change set.
