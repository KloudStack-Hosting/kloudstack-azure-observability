# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[semantic versioning](https://semver.org/).

The telemetry schema is versioned independently — see the functional specification, section 8.

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

### Pending
- Phase B — telemetry core: envelope, Azure and WordPress context, correlation, privacy, sampling, buffer.
- Phase C — non-blocking transport, batching, circuit breaker, latency benchmark gate.
- Phase D — request, exception and error collectors; JS SDK injection with correlation.
- Phase E — settings page, diagnostics self-test, Site Health.
- Phase F — readme, i18n, uninstall, release workflow, image integration.

## 1.2.3 and earlier

Shipped as an MU-plugin inside the KloudStack WordPress image; not publicly distributed. Source
retained under `legacy/` for reference. See `docs/` for the defects that motivated the rewrite:
blocking transport on the request path, absent privacy controls, and no client/server
correlation.
