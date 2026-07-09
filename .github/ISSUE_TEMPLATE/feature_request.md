---
name: Feature request
about: Suggest a capability, adapter, or improvement
title: "[feat] "
labels: enhancement
---

**The need**
What problem would this solve, for a merchant or an agent?

**Proposal**
What you'd like to see.

**Scope check**
Fondouk v1 is **read-only, public-only, catalog** (UCP + MCP). Requests that touch cart/checkout, customer data, or non-public prices are out of v1 scope by design (see `docs/TECHNICAL-SPEC.md`). Does this fit v1, or is it v2 / a new adapter?

**For a new protocol adapter**
See `docs/blueprint/adapter-contract.md` — an adapter must consume the Capability Graph and honor the 6 obligations (read-only, public filter, versioning, minor units, no internal fields, honest manifest).
