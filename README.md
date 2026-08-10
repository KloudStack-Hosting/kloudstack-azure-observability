# KloudStack Observability for Azure

Azure Application Insights telemetry for WordPress running on Azure.

[![CI](https://github.com/KloudStack-Hosting/kloudstack-azure-observability/actions/workflows/ci.yml/badge.svg)](https://github.com/KloudStack-Hosting/kloudstack-azure-observability/actions/workflows/ci.yml)

Sends WordPress request, exception and browser telemetry to Azure Application Insights, with
automatic Azure environment detection, client/server correlation, and privacy controls suitable
for public distribution.

This plugin is built specifically for WordPress on Azure. It reads App Service application
settings automatically, names cloud roles from the Azure site and slot so one Application
Insights resource can serve many sites, and emits a versioned telemetry schema that Azure
Monitor workbooks bind to.

---

## Status

**Release candidate — `2.0.0-rc1`.** The plugin collects and transmits request, exception and
browser telemetry end to end, with a settings screen, a diagnostics self-test and Site Health
integration. It has been verified on a live Azure site, and the latency budget that gates
release is met and recorded — see [`docs/C4-LATENCY-GATE-RESULT.md`](docs/C4-LATENCY-GATE-RESULT.md).

Remaining before `2.0.0`: WordPress.org review, and completing translation coverage
(see [`docs/I18N.md`](docs/I18N.md)). Progress is tracked in [`CHANGELOG.md`](CHANGELOG.md).

The 1.x source, which shipped inside the KloudStack WordPress image, is retained under
[`legacy/`](legacy/) for reference and is not maintained.

## Documentation

| Document | Purpose |
|---|---|
| [Functional Specification v4](docs/Application-Insights-for-WordPress-by-KloudStack-Functional-Specification-v4.md) | What the plugin does, scope, privacy, telemetry schema contract |
| [Latency gate result](docs/C4-LATENCY-GATE-RESULT.md) | The §6.1 performance budget, how it is measured, and the result |
| [Translations](docs/I18N.md) | Translation template, coverage, and how to regenerate it |

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
   tracking off, **browser telemetry off**, and **cookie-less on** when it is switched on.

   Browser telemetry is opt-in because enabling it loads Microsoft's JavaScript SDK into every
   visitor's browser — WordPress.org guidelines 7 and 9 require that to be off by default.
   Server-side telemetry is a different case: it transmits only to the site owner's own Azure
   resource and only once they supply a connection string.

   Cookie-less is on by default so that turning browser telemetry on cannot silently create a
   consent obligation. **The trade-off is real:** without `ai_user` / `ai_session` cookies,
   Application Insights reports page and dependency timings but cannot aggregate sessions or
   distinguish returning visitors — no "users" or "sessions" in the portal. Owners who need that
   can disable cookie-less mode and take on the consent duty, ideally driving the
   `kloudstack_obs_has_consent` filter from a consent management platform.
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