# Architecture — making any e-commerce platform readable by agents

> The entry point to the **Fondouk Blueprint**. This is not a spec: it's a **playbook for implementing** the open standards ([UCP](https://ucp.dev), [MCP](https://modelcontextprotocol.io)) — how to open a store to AI agents without inventing a protocol. The PrestaShop module is reference implementation #1.
>
## The idea, in one picture

```
        AI agents (Claude, Gemini, ChatGPT, browser agents)
                              │  discover /.well-known/ucp, then query
                              ▼
   ┌─────────────────────────────────────────────────────────┐
   │  ADAPTERS  (UCP REST · UCP MCP · later WebMCP, feeds…)    │
   │  pure functions: Capability Graph → protocol surface     │
   └───────────────────────────▲─────────────────────────────┘
                               │  consume a neutral pivot model
   ┌───────────────────────────┴─────────────────────────────┐
   │  CAPABILITY GRAPH  (versioned JSON Schema, unbranded)     │
   │  "what this system can expose", protocol-independent      │
   └───────────────────────────▲─────────────────────────────┘
                               │  produced by introspection, through a PORT
   ┌───────────────────────────┴─────────────────────────────┐
   │  PLATFORM  (PrestaShop · Sylius · an API · a proxy)       │
   │  implements CatalogSource / ShopProfileSource            │
   └─────────────────────────────────────────────────────────┘
```

**A platform that can produce the Capability Graph inherits every adapter for free.** That's the only platform-specific work; the rest is reusable.

## The four copyable artifacts

1. **The Capability Graph** — the neutral, versioned pivot format ([`capability-core/schema/capability-graph.v0.json`](capability-core/schema/capability-graph.v0.json)). The project's internal norm.
2. **The adapter contract** — [`docs/blueprint/adapter-contract.md`](docs/blueprint/adapter-contract.md): what an adapter receives/produces, its 6 obligations, the graph↔adapter asymmetry rule.
3. **The discovery pattern** — [`docs/blueprint/discovery-pattern.md`](docs/blueprint/discovery-pattern.md): `/.well-known/` conventions, the *"a manifest is a promise"* principle, security defaults.
4. **The deployment modes** — [`docs/blueprint/deployment-modes.md`](docs/blueprint/deployment-modes.md): embedded (coded), library (Sylius `FondoukBundle`), standalone proxy.

## The load-bearing decisions (and why)

- **Decoupled core, zero framework dependency.** `capability-core` knows neither PrestaShop nor HTTP. That's what makes the library and proxy modes possible — and what makes the core the most reusable artifact.
- **Introspection, not configuration.** The graph is generated from the platform's data; the merchant configures nothing.
- **Public = public.** What an anonymous visitor sees on the storefront, an agent sees — nothing more. Prices computed in a visitor context (B2B/group prices never leak), no customer data, strictly read-only.
- **A manifest is a promise.** It only declares what actually responds. An empty manifest beats a lying one.
- **We implement standards, we don't create them.** [UCP](https://ucp.dev) (Google/Shopify/Walmart), [MCP](https://modelcontextprotocol.io) (Anthropic). The day the Blueprint describes a concept they already define, we delete it and point to their spec. Authority comes from being the best implementation, not one more standard.

## Where to read the code

| Element | Path |
|---|---|
| Pivot model + ports + adapters (neutral) | `capability-core/src/` |
| Capability Graph schema | `capability-core/schema/capability-graph.v0.json` |
| PrestaShop implementation (introspection) | `prestashop-module/src/PrestaShop/` |
| Endpoints (manifest, REST, MCP) | `prestashop-module/controllers/front/` |
| Full technical spec (decisions, mapping) | `docs/TECHNICAL-SPEC.md` |

## Status

v1: UCP manifest + Catalog (REST + MCP), read-only, PrestaShop 8/9 — built, tested on both versions, security review (Gate 2) signed. The Blueprint above is **consistent with the code actually shipped**, not written in theory.
