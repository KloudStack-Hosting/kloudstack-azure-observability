# KloudStack Observability for Azure

Azure Application Insights telemetry for WordPress running on Azure.

[![CI](https://github.com/kloudstack/kloudstack-azure-observability/actions/workflows/ci.yml/badge.svg)](https://github.com/kloudstack/kloudstack-azure-observability/actions/workflows/ci.yml)

Sends WordPress request, exception and browser telemetry to Azure Application Insights, with
automatic Azure environment detection, client/server correlation, and privacy controls suitable
for public distribution.

This plugin is built specifically for WordPress on Azure. It reads App Service application
settings automatically, names cloud roles from the Azure site and slot so one Application
Insights resource can serve many sites, and emits a versioned telemetry schema that Azure
Monitor workbooks bind to.

---

## Status

> **This repository is private.** It must stay private until the internal planning documents in
> [`docs/`](docs/README.md) are removed from git history. See [`docs/README.md`](docs/README.md)
> for the sequence to follow before making it public.

**Pre-release — Phases A to C complete.** Foundation, configuration, telemetry core and the
non-blocking transport layer are in place and tested. Collectors are not yet wired up, so the
plugin does not emit telemetry yet; see [`CHANGELOG.md`](CHANGELOG.md) for what remains.

The 1.x source, which shipped inside the KloudStack WordPress image, is retained under
[`legacy/`](legacy/) for reference and is not maintained.

## Documentation

| Document | Purpose |
|---|---|
| [Functional Specification v4](docs/Application-Insights-for-WordPress-by-KloudStack-Functional-Specification-v4.md) | What the plugin does, scope, privacy, telemetry schema contract |
| [Implementation Plan](docs/KloudStack-WordPress-Plugin-Implementation-Plan.md) | Repository, code design, phased work breakdown, release engineering |
| [Marketplace Implementation](docs/KloudStack-WordPress-Observability-Azure-Marketplace-Implementation.md) | The companion Azure Marketplace Solution Template offer |

Specification v3 is superseded and retained only as history.

## Requirements

- PHP 7.4 or later
- WordPress 6.0 or later
- An Azure Application Insights resource

## Configuration

Resolved once per request, first match wins:

| Precedence | Source |
|---|---|
| 1 | `KLOUDSTACK_OBS_CONNECTION_STRING` constant in `wp-config.php` |
| 2 | `APPLICATIONINSIGHTS_CONNECTION_STRING` environment variable |
| 3 | `APPINSIGHTS_INSTRUMENTATIONKEY` environment variable (deprecated) |
| 4 | Settings page |

Environment beats the database deliberately. On managed stacks and Marketplace deployments the
App Service application setting is authoritative, and a site administrator should not be able to
silently redirect a site's telemetry by editing an option.

## Development

```bash
composer install     # development dependencies only — the shipped plugin vendors nothing
composer run check   # PHPCS + PHPStan + PHPUnit
composer run test    # unit tests
composer run lint    # coding standards
```

Unit tests run without WordPress, using the stubs in [`tests/stubs/`](tests/stubs/). Anything
that genuinely needs WordPress belongs in the integration suite.

### Architectural rules

These are not stylistic preferences — each exists because of a specific failure mode:

1. **No blocking I/O on the request path.** Telemetry is transmitted only after the response has
   been released to the visitor. The 1.x plugin POSTed synchronously in its shutdown handler,
   which added up to five seconds of latency to every page view when Azure ingestion was slow.
2. **Every hook callback is wrapped in `Guard`.** An observability plugin that takes a site down
   is worse than no observability plugin.
3. **No Composer dependencies in the shipped artifact.** WordPress plugins share one PHP process;
   vendored libraries collide.
4. **Privacy defaults are conservative.** IP anonymisation on, query strings redacted, header
   tracking off.
5. **The telemetry schema is a contract.** Marketplace workbook queries bind to it. Changing a
   dimension is a breaking change.

## Distribution

| Channel | Notes |
|---|---|
| WordPress.org | Public, standard install and auto-update |
| GitHub releases | Source of truth; tagged, with published SHA-256 checksums |
| KloudStack WordPress image | MU-loader ([`loader/`](loader/)) requires a pinned tagged release; auto-update suppressed |

## Licence

GPL-2.0-or-later. See [`LICENSE`](LICENSE).

The bundled Application Insights JavaScript SDK is © Microsoft Corporation, MIT licensed.