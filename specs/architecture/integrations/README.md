# Integrations

**Parent:** [../README.md](../README.md) (architecture hub)  
**Status:** Placeholder domain (no integration specs yet)

---

## Purpose

This folder holds **external integration** architecture specifications — how StoX / Lido Portfolio connects to third-party systems for market data, brokerage, notifications, and AI assistance.

Integrations are **adapters at the boundary** of the platform. They must not own core trading decisions, portfolio truth, or recommendation logic. Those remain in platform, portfolio, indicators, domains, data, and live-trading specs.

---

## Planned contents

Specifications in this domain will eventually cover providers such as:

| Category | Examples |
|----------|----------|
| Brokers | Zerodha, future brokers |
| Exchanges / market data | NSE, BSE, future market data providers |
| Notifications | Telegram, Email, future notification providers |
| AI services | OpenAI, Gemini, future model providers |

Each integration spec should define:

- Capabilities exposed to the platform (and what is out of scope)
- Auth / secrets / credential handling expectations
- Failure modes, retries, and degraded behaviour
- Audit / logging requirements
- How the integration maps to internal domain concepts (without redefining those concepts)

---

## Reading guidance

1. Read [platform/](../platform/) and [governance/DOCUMENT_PRECEDENCE.md](../governance/DOCUMENT_PRECEDENCE.md) before authoring an integration spec.
2. Prefer thin adapters: translate external APIs into internal contracts defined elsewhere (e.g. data engine, notification engine, live trading execution).
3. Do not duplicate System Domain Model or engine SHALLs here — link to them.

---

## Current state

No integration specification files yet. Add new docs under this folder as integrations are designed; index them in [../README.md](../README.md) and [../../../DOCS.md](../../../DOCS.md).
