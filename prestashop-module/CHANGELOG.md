# Changelog — Fondouk (free)

All notable changes to this module are documented here.
Format inspired by [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versioning follows [SemVer](https://semver.org/).

> **Project rule:** before any new release, update this file (new number +
> changes) **then** bump `$this->version` in `fondouk.php`.

## [2.0.0] — 2026-10-30
### Added
- **MCP protocol revision 2026-07-28 supported, alongside earlier revisions.** The MCP server is now
  dual-era. Modern clients work without the `initialize` handshake:
  - `server/discover` advertises the versions actually served: 2026-07-28, 2025-06-18 and 2025-03-26;
  - every request carries its version and client capabilities in `_meta`;
  - the `MCP-Protocol-Version`, `Mcp-Method` and `Mcp-Name` headers are checked against the body;
  - results carry `resultType`, the server identity and caching hints (`ttlMs`, `cacheScope`).

  Mismatched or missing headers, missing `_meta` fields, unsupported versions and unknown methods return
  the standard errors (`-32020`, `-32602`, `-32022` with the supported versions, `404` + `-32601`). On
  2026-07-28 requests, rate limiting returns the application code `-31429` (HTTP 429) instead of `-32000`,
  outside the ranges reserved by JSON-RPC. Clients using 2025-06-18 or 2025-03-26 keep working:
  `initialize` now answers the requested version when it is one of them (previously always 2025-06-18),
  requests without a version header are served as 2025-03-26, and JSON-RPC batches (modern requests are
  one per POST), `ping` and the `-32000` rate-limit code are unchanged for them. The `2024-11-05` version
  header is now refused (`-32022`): that revision uses the HTTP+SSE transport, which this server never
  provided. The three tools, their results, the anonymous-price rules and the
  load limits are unchanged.
- **Trusted proxies setting (optional).** On the module screen, list the addresses (IP or CIDR range) of
  the reverse proxies, load balancers or CDN you control, so that the rate limit uses the visitor
  address they forward. Empty by default: forwarded addresses are never trusted unless declared.
  Entries are strictly validated (overly wide ranges rejected). A new diagnostic check (A5) warns when
  agent requests seem to arrive through an undeclared proxy (detection is not exhaustive).
- **Commerce Diagnostic (essential, free).** A "Run the diagnostic" button on the module screen checks,
  from inside the store, what AI agents can read: environment (HTTPS, friendly URLs, `.well-known`
  conflict, dependencies), real reachability of the UCP manifest, REST catalog, MCP server, `llms.txt`
  and `robots.txt`, and three security checks — no reserved price (customer group, specific customer),
  no reserved product and no hidden price exposed to agents. Each check returns PASS, WARN, FAIL or
  NOT_TESTED (with its cause, never counted as a pass) and a corrective action. Read-only, run only on
  demand by an authorized employee, one run at a time, nothing stored; the diagnostic's own requests
  are not counted as agent traffic. A request that cannot be completed (network, timeout, run time budget,
  maintenance, rate limit) makes its check NOT_TESTED, never FAIL or PASS.

### Licensing
- **`LICENSE.md` added** at the root of the module, with the full MIT license text and the list of
  redistributed components. The capability core bundled in `vendor/fondouk/capability-core/` now ships its own
  MIT `LICENSE` notice (it was missing from the package); the Composer autoloader keeps its notice.

### Security
- **The MCP endpoint now validates the `Origin` header.** A request from a browser page whose origin is
  not allowed gets `403` (DNS rebinding protection), preflight included. Requests without `Origin`
  (command-line clients, SDKs, agent servers) are served as before; they no longer receive
  `Access-Control-Allow-Origin: *`, which only browsers use. Allowed origins:
  - the shop's own URLs as configured in PrestaShop (each shop separately in multistore);
  - optionally, origins listed in the `FONDOUK_MCP_ALLOWED_ORIGINS` configuration value.

  `Host`, `X-Forwarded-Host` and client-declared origins are never trusted. On the MCP endpoint, CORS
  responses now echo the allowed origin instead of `*`. The REST catalog is unchanged.
- **Telemetry could grow without limit under abusive traffic.** Every agent request, including
  refused ones (429, 400, 405, 413) and every manifest call, wrote one telemetry row. Detailed rows are
  now capped per shop and per day (20,000 served requests, 1,000 manifest calls, 1,000 refused
  requests). Beyond the cap, requests are only counted, in an anonymous daily aggregate whose own writes
  are bounded (exact up to 128, then weighted sampling). Retention applies to these counters as well.
- **One request could make the shop build thousands of products.** A request now costs one rate-limit
  token per 10 products it asks for: lookup identifiers (REST `catalog/lookup`, MCP `lookup_catalog`)
  and search page size (REST `catalog/search`, MCP `search_catalog`; the default page of 10 still costs
  one token). An MCP request can ask for at most 100 product identifiers and 200 products in total
  across its batch: beyond that, it is refused with an explicit JSON-RPC error (HTTP 400), with no
  product built.
- **Rate limit could be bypassed with forged proxy headers.** The client address used by the rate
  limit no longer trusts `X-Forwarded-For` unless the connection comes from a proxy explicitly
  declared in `FONDOUK_TRUSTED_PROXIES` (addresses or CIDR ranges); behind such a proxy the address
  added by the proxy is used. IPv6 clients are grouped per /64. Idle counters are purged in small
  bounded batches.
- **Standby controllers reachable when Fondouk Pro is active.** With Fondouk Pro active this module
  registers no route, but its controllers could still be called directly through PrestaShop's
  module dispatch, bypassing the Pro (agent control, limits, telemetry). They now answer 404 while
  the Pro is active.
- **Agent catalog always computed for an anonymous visitor.** Prices served by the UCP REST and MCP
  endpoints could depend on the session of the requester (customer group, customer-specific prices,
  cart address) instead of always being those of an anonymous visitor. They are now computed for an
  anonymous visitor in every case; shop, language, currency and country requested by the agent are
  unchanged, and cached responses from earlier versions are never served.
- **Prices the shop hides from visitors were exposed to agents.** When a shop does not show
  prices to anonymous visitors — catalog mode without prices, a visitor group that cannot see
  prices, or a product whose price is hidden (`show_price` off) — the storefront hid them but
  the UCP REST and MCP endpoints still returned them. Fondouk now applies the same rule as
  the PrestaShop storefront: a product whose price is hidden has no price in the catalog
  graph and is not served to agents (UCP requires a price; no zero or placeholder price is
  ever exposed instead). When the whole shop hides its prices, `search` and `lookup`
  responses carry an `info` message with code `prices_hidden` and announce no next page;
  cached responses from before the change are never served (the price visibility is part of
  the cache key). Present since the first release.

### Fixed
- **Catalog responses are never stored by external HTTP caches.** REST and MCP catalog responses now
  carry `Cache-Control: no-store`, so that a browser, proxy or CDN can never serve a price or a product
  the shop has just restricted; the module's own cache, invalidated by the shop's hooks, is kept.
- **Protocol robustness (REST and MCP).** CORS preflight (`OPTIONS`) answered with 204; unsupported
  methods answered with 405 and an `Allow` header; malformed JSON answered with 400 (REST
  `invalid_request`, MCP parse error over HTTP 400); an unknown `MCP-Protocol-Version` header is rejected
  with 400 while known versions and an absent header are accepted; MCP tools declare a title and
  read-only annotations; the text content of a tool result is the JSON of its structured content;
  error messages are plain text (no HTML entities); `serverInfo.version` is the real module version.
- **Catalog cache freshness.** Cached agent responses are now invalidated when categories (and their
  customer-group visibility), customer groups and their reductions, taxes, currencies, catalog price
  rules or combinations change, and they depend on the request scheme (http/https) and on stock
  management. Responses carry `Vary: Accept-Language`. Expired cache files are removed by a bounded
  background pass (frequency, count and time limited); a saturated cache stops storing new entries
  instead of growing.
- **Availability when stock management is disabled.** With stock management turned off, the storefront
  sells every product whatever its recorded quantity, but agents were told that products with a zero
  quantity were out of stock. Availability now follows the storefront: in stock (quantity not announced).
- **UCP discovery manifest rejected by the official UCP schema.** `/.well-known/ucp` served
  `"payment_handlers": []` (an array) instead of `{}` (an object): the GET-convenience
  annotation decoded the manifest into PHP arrays and re-encoded it, turning the empty object
  into an empty array. The official UCP conformance suite (`test_discovery`, `ucp-sdk`
  0.4.6) rejected the profile. The manifest is now decoded as objects; empty registries stay
  `{}`. Present since 1.2.1.
- **MCP tools ignored the UCP MCP binding argument shape.** UCP 2026-04-08 sends tool
  parameters under `catalog` (with request metadata under `meta`). They were ignored:
  `search_catalog` returned the unfiltered catalog and `get_product` failed with "missing id".
  The three tools now accept `{meta, catalog: {...}}`; the historical flat arguments still
  work; when both are sent, `catalog` wins; a non-object `catalog` returns a tool error.

## [1.3.2] — 2026-10-21
Maintenance release on top of 1.3.1 (no functional change).

### Changed
- **No use of the legacy global context accessor.** The anonymous-visitor protection introduced in 1.3.1
  now works only on the controller context it receives, without calling `Context::getContext()`
  (flagged by the PrestaShop Addons validator for PrestaShop 9). Behaviour is unchanged.

## [1.3.1] — 2026-10-21
Security release on top of 1.3.0 (no other change).

### Security
- **Agent catalog always computed for an anonymous visitor.** Prices served by the UCP REST and MCP
  endpoints could depend on the session of the requester (customer group, customer-specific prices,
  cart address) instead of always being those of an anonymous visitor. They are now computed for an
  anonymous visitor in every case; shop, language, currency and country requested by the agent are
  unchanged, and cached responses from earlier versions are never served.
- **Prices the shop hides from visitors were exposed to agents.** When a shop does not show
  prices to anonymous visitors — catalog mode without prices, a visitor group that cannot see
  prices, or a product whose price is hidden (`show_price` off) — the storefront hid them but
  the UCP REST and MCP endpoints still returned them. Fondouk now applies the same rule as
  the PrestaShop storefront: a product whose price is hidden has no price in the catalog
  graph and is not served to agents (UCP requires a price; no zero or placeholder price is
  ever exposed instead). When the whole shop hides its prices, `search` and `lookup`
  responses carry an `info` message with code `prices_hidden` and announce no next page;
  cached responses from before the change are never served (the price visibility is part of
  the cache key). Present since the first release.

## [1.3.0] — 2026-07-31
### Added
- **Automatic telemetry retention (90 days, batched purge).** The
  `fondouk_access_log` table had NO purge at all (neither automatic nor manual) — it grew
  unbounded. It is now pruned automatically beyond the retention window, **without cron**:
  - triggered on the write path (`AccessLogger::log`), **at most once a day**;
  - **bounded per-request budget** (at most 3 batches of 10,000) — an agent request never
    pays for a large backlog; any remainder drains on subsequent accesses;
  - **race-safe** via a MySQL advisory lock (`GET_LOCK`, non-blocking) — never two
    concurrent purges on the shared table;
  - retention = `FONDOUK_RETENTION_DAYS` (overridable, no settings screen) or **90 days** by default;
  - **deference intact**: when Fondouk Pro is active, the free module does not serve → does
    not log → does not purge (Pro owns retention on its side).
- Documented in the README (EN/FR/AR), retention/GDPR section.

## [1.2.7] — 2026-07-21
### Fixed
- Documentation: the bundled readme PDFs (EN/FR/AR) still advertised **8.2 / 9.1**
  compatibility → corrected to **8.0 – 9.2** (badge + requirements). No functional change.
### Added
- `build/check-versions.sh` + `build/SUPPORTED-VERSIONS` (single MIN/MAX source of truth): a
  guard that breaks the build if a stale PrestaShop version appears in any public artifact
  (README, module, PDF via `pdftotext`). Wired into `package.sh` and the public export.

## [1.2.6] — 2026-07-19
### Changed
- Documentation: README (EN/FR/AR) — "Fondouk Pro" section (open-core), compatibility badge
  and requirements **8.0 – 9.2**, roadmap realigned (Pro = analytics, available;
  checkout = backlog). No functional change to the module.

## [1.2.5] — 2026-07-17
### Fixed
- **Coexistence**: uninstalling the free module no longer drops the shared telemetry table
  (`fondouk_access_log`/`fondouk_rate_limit`) nor the IP salt / version counter **when
  Fondouk Pro is installed** — Pro still uses them. Previously, uninstalling the free module
  in a free+Pro store wiped Pro's telemetry and salt. Guard:
  `if (Module::isInstalled(PRO_MODULE)) return parent::uninstall();`.
### Validated
- Extended compatibility verified on **PrestaShop 8.0.5 and 9.2.0** (3 harnesses ALL_PASS,
  coexistence 4 states + migration 22/22 each).

## [1.2.4] — 2026-07-16
### Changed
- Addons Code Style compliance: `declare(strict_types=1)`, `echo` → `$this->ajaxRender()`
  on the 4 front controllers, and `fully_qualified_strict_types` (import + short name).

## [1.2.3] — 2026-07-15
### Security
- Hardening: replaced raw superglobals (`$_SERVER`, `php://input`) with
  `Symfony\Component\HttpFoundation\Request` (Addons validator SQL_Injection false
  positives; none reached a query).

## [1.2.2] — 2026-07-14
### Fixed
- Addons validator compliance: `_PS_VERSION_` guard moved above any executable code,
  `array()` → `[]` in translations, and Diagnostic HTML rendering (a `{l}` tag display bug).

## [1.2.1] — 2026-07-13
### Added
- **GET gateway** (non-normative convenience): `GET /catalog/{search?q=,product?id=,lookup?ids=}`
  and a bare `GET /fondouk/ucp?capability=&query=` entry, for agentic browsers.
  Rate limit / cache / telemetry identical to the canonical POST transport.

## [1.2.0] — 2026-07-13
### Added
- **Polite coexistence** with Fondouk Pro: when Pro is installed and active, the free module
  defers the whole agent surface (`hookModuleRoutes` returns `[]`); uninstalling Pro makes
  the free module resume automatically, history intact.

## [1.1.0] — 2026-07-12
### Added
- First tracked release of the free module (read-only UCP/MCP agent surface: `/.well-known/ucp`
  manifest, REST catalog, MCP server, `llms.txt`; agent telemetry, SHA-256 hashed IPs).
