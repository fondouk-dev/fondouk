# Changelog — Fondouk (free)

All notable changes to this module are documented here.
Format inspired by [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versioning follows [SemVer](https://semver.org/).

> **Project rule:** before any new release, update this file (new number +
> changes) **then** bump `$this->version` in `fondouk.php`.

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
