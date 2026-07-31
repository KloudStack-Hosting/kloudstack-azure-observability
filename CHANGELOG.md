# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[semantic versioning](https://semver.org/).

The telemetry schema is versioned independently — see the functional specification, section 8.

## [2.0.0-rc2]

### Changed
- **MU-loader (2.1.0) now respects the WordPress activation state.** It boots the plugin at
  must-use priority only when the plugin is active, so activation and deactivation behave exactly
  as they do for any plugin — a site owner or KloudStack can turn observability off from the
  Plugins screen and it genuinely stops. Earlier the loader booted unconditionally, which removed
  that control. An active plugin still gets the early-load benefit (catching fatals raised while
  other plugins load). The KloudStack image activates the plugin once on first deploy so it is on
  by default. Plugin code is unchanged from rc1.

## [Unreleased] — 2.0.0

First public release. A rewrite of the 1.x MU-plugin, extracted from the KloudStack WordPress
image into a standalone, publicly distributed plugin.

### Added — Phase A (foundation)
- Namespaced architecture under `KloudStack\Observability` with a hand-written PSR-4 autoloader.
- `Guard` fail-closed wrapper — no plugin code path may surface a Throwable into WordPress.
- `Config` precedence chain: constant, environment, legacy key, option.
- Instrumentation key validation; malformed configuration is reported as unconfigured rather
  than producing telemetry Azure silently discards.
- Sovereign cloud support via `EndpointSuffix`.
- HTTPS enforcement on the ingestion endpoint.
- `Log` debug logging, gated behind `WP_DEBUG` and an explicit filter.
- CI: lint matrix (PHP 7.4–8.4), PHPCS, PHPStan level 6, PHPUnit, version-consistency check.

### Added — Phase B (telemetry core)
- `Envelope` — Application Insights wire format, tick-precision durations, dimension coercion.
- `AzureContext` — App Service / Container Apps / AKS / VM detection, cloud role and slot naming.
- `WordPressContext` — schema v1 custom dimensions.
- `Correlation` — W3C trace context, inbound `traceparent` adoption, route-template operation names.
- `Privacy` — IP anonymisation, query-string allowlist with an unconditional credential blocklist,
  header-capture opt-in, consent filter.
- `Sampler` and `Buffer` — trace-consistent sampling and a bounded per-request buffer.

### Added — Phase C (transport)
- `ResponseRelease` — releases the response before transmission via `fastcgi_finish_request()`,
  LiteSpeed's equivalent, or a flush fallback.
- `CircuitBreaker` — transient-backed, shared across workers, so an Azure outage cannot exhaust
  the PHP-FPM worker pool.
- `Transport` — single batched request per page, gzip above 1KB, failure classification that
  distinguishes an unreachable endpoint from a rejected payload.

### Added — Phase D (collectors)
- `Settings` — typed, filterable configuration access.
- `Reporter` — assembles telemetry, owns sampling and context tags, releases before transmitting.
- `RequestCollector` — request telemetry with exclusions for cron, heartbeat and xmlrpc.
- `ExceptionCollector` — uncaught Throwables and fatals, chaining the previous handler.
- `SnippetInjector` — JS SDK with a telemetry initializer that correlates browser to server.

### Added — Phase E (administration)
- Settings screen under Tools, with environment-locked fields shown read-only and their source named.
- Diagnostics self-test: ten checks including a live round-trip to Azure through the production
  transport path.
- Site Health integration and debug-information export.

### Pending
- Phase F — i18n, release workflow, image integration, WordPress.org submission.
- Integration testing inside a real WordPress install.
- C4 latency benchmark against App Service — a hard release gate.

## 1.2.3 and earlier

Shipped as an MU-plugin inside the KloudStack WordPress image; not publicly distributed. Source
retained under `legacy/` for reference. See `docs/` for the defects that motivated the rewrite:
blocking transport on the request path, absent privacy controls, and no client/server
correlation.
