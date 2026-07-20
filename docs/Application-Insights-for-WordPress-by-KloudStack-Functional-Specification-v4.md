# KloudStack Observability for Azure — WordPress Plugin

## Functional Specification

**Version:** 4.0
**Date:** 20 July 2026
**Supersedes:** v3.0 (July 2026)
**Status:** Approved for implementation
**Companion documents:**
- `KloudStack-WordPress-Plugin-Implementation-Plan.md` — technical build plan
- `KloudStack-WordPress-Observability-Azure-Marketplace-Implementation.md` — Marketplace offer

---

## Changes from v3.0

v3.0 was a roadmap. This version is a specification: it fixes scope, names, claims and
non-functional requirements so the plugin can be built and submitted.

| Area | v3.0 | v4.0 |
|---|---|---|
| Scope | Included "generic Linux hosting (basic functionality)" | **Azure-hosted WordPress only.** Non-Azure hosts are unsupported and the plugin self-disables |
| Product name | "Application Insights for WordPress (by KloudStack)" | **"KloudStack Observability for Azure"** — WordPress.org prohibits "WordPress" in a plugin name, and leading a slug with Microsoft marks risks rejection |
| Compatibility matrix | 8 integrations marked ✅ Supported | Reclassified: nothing is ✅ until it has instrumentation code and a test. See §9 |
| Correlation | "Planned" | **Release 1 requirement.** Without it, client and server telemetry cannot be joined and the Marketplace workbook has nothing to query |
| Configuration | Environment variables only | Documented precedence chain including a settings page (§4) |
| Privacy | Not addressed | §7 — mandatory for WordPress.org review and Marketplace certification |
| Performance | Not addressed | §6 — the current blocking transport is a correctness defect |
| Telemetry schema | Not defined | §8 — versioned custom-dimension contract that workbook queries depend on |

---

## 1. Product definition

KloudStack Observability for Azure is a WordPress plugin that sends WordPress telemetry to
Azure Application Insights, for WordPress sites running on Azure.

It is deliberately **not** a general-purpose APM plugin. It assumes Azure, and it uses that
assumption to do things a host-agnostic plugin cannot: read App Service application settings
directly, enrich telemetry with Azure instance and slot metadata, name cloud roles from the
Azure resource identity, and align its schema with Azure Monitor workbooks.

### 1.1 Scope

**Supported environments**

| Environment | Support | Configuration source |
|---|---|---|
| Azure App Service (Linux, code and container) | Full | Application settings (environment) |
| Azure App Service (Windows) | Full | Application settings (environment) |
| Azure Container Apps | Full | Environment variables or secrets |
| Azure Kubernetes Service | Full | Environment variables from ConfigMap/Secret |
| Azure Virtual Machines | Full | `wp-config.php` constant or settings page |
| Local development against an Azure resource | Supported, telemetry flagged | Settings page or constant |
| Non-Azure hosting | **Unsupported** | Plugin runs but shows an admin notice; telemetry still works if configured |

The plugin will not refuse to run off-Azure — that would be hostile and unenforceable — but
Azure environment detection gates the enrichment features, the documentation states Azure as a
requirement, and support is limited to Azure deployments.

### 1.2 Distribution

One codebase, three delivery paths:

1. **WordPress.org** — public plugin, standard install and auto-update.
2. **GitHub releases** — tagged, signed, checksummed; the source of truth.
3. **KloudStack WordPress image** — a thin MU-loader requires a pinned tagged release from the
   image build. Auto-update is suppressed on managed stacks so the image controls the version.

The plugin detects its own load mode (`mu` vs `standard`) and reports it in diagnostics. The
only behavioural difference is fatal-error coverage — see §5.3.

---

## 2. Naming decision

| Context | Name |
|---|---|
| WordPress.org plugin name | KloudStack Observability for Azure |
| WordPress.org slug | `kloudstack-azure-observability` |
| Text domain | `kloudstack-azure-observability` |
| PHP namespace | `KloudStack\Observability` |
| Option / hook prefix | `kloudstack_obs_` |
| Marketplace offer name | KloudStack WordPress Observability for Azure |
| Short descriptor (listings, readme) | "Azure Application Insights telemetry for WordPress on Azure" |

Rationale: WordPress.org guidelines prohibit "WordPress" in the plugin name and restrict use of
third-party trademarks in slugs. "Application Insights" and "Azure" remain in the description,
which is permitted descriptive use. The Marketplace offer is not bound by WordPress.org rules
and keeps its existing name.

**This naming must be settled before the repository is created.** Renaming a published slug is
not possible on WordPress.org.

---

## 3. Architecture

```
┌─ Bootstrap ──────────────────────────────────────────────────────────┐
│  Config resolution → Azure context detection → kill-switch check     │
└───────────────────────────┬──────────────────────────────────────────┘
                            │
        ┌───────────────────┼───────────────────┐
        │                   │                   │
┌───────▼──────┐   ┌────────▼───────┐   ┌───────▼────────┐
│  Collectors  │   │   Enrichment   │   │  Client (JS)   │
│              │   │                │   │                │
│ Request      │   │ Azure context  │   │ SDK v3 loader  │
│ Exception    │──▶│ WP context     │   │ Correlation    │
│ Dependency   │   │ Correlation    │   │ initializer    │
│ Trace        │   │ Privacy scrub  │   │ Consent gate   │
│ Metric       │   │ Sampling       │   │                │
└──────────────┘   └────────┬───────┘   └────────────────┘
                            │
                   ┌────────▼────────┐
                   │  Buffer         │  in-memory, per request
                   └────────┬────────┘
                            │  after fastcgi_finish_request()
                   ┌────────▼────────┐
                   │  Transport      │  batched, non-blocking,
                   │  + breaker      │  circuit-broken on failure
                   └────────┬────────┘
                            │
                   Azure Application Insights
```

Every stage is skippable. If configuration is absent, bootstrap exits before any collector is
registered and the plugin costs one function call per request.

---

## 4. Configuration

### 4.1 Precedence

Resolved once per request, cached statically. First match wins:

1. `KLOUDSTACK_OBS_CONNECTION_STRING` constant in `wp-config.php`
2. `APPLICATIONINSIGHTS_CONNECTION_STRING` environment variable
3. `APPINSIGHTS_INSTRUMENTATIONKEY` environment variable (legacy, deprecated, warns)
4. `kloudstack_obs_connection_string` option (settings page)
5. Nothing → plugin disabled, admin notice shown to users who can `manage_options`

Environment beats the database deliberately: on KloudStack managed stacks and on Marketplace
deployments the App Service application setting is authoritative, and a site administrator
should not be able to silently redirect telemetry by editing an option. When a higher-precedence
source is present the settings page shows the value as read-only with its source labelled.

### 4.2 Settings

| Setting | Default | Notes |
|---|---|---|
| Connection string | — | Masked in UI; never exposed via REST or autoloaded options |
| Telemetry enabled | On | Master kill switch, independent of configuration |
| Sampling rate (requests) | 100% | 1–100; `sampleRate` set on envelopes so Azure extrapolates |
| Sampling rate (dependencies) | 100% | Independent of requests |
| Exception sampling | Always 100% | Not configurable; exceptions are low volume and high value |
| Client-side telemetry | On | Injects the JS SDK |
| Cloud role name | Auto (§8.2) | Override for agencies sharing one App Insights resource |
| Anonymise client IP | On | See §7 |
| Query-string capture | Allowlist | See §7 |
| Request/response headers | Off | Opt-in; captures cookies and auth headers |
| Excluded paths | See §6.3 | Regex list, additive to defaults |
| Track admin requests | On | `wp-admin` and `admin-ajax` |
| Track cron requests | Off | WP-Cron over HTTP is high-volume and low-value |
| Debug log | Off | Writes to `WP_CONTENT_DIR/kloudstack-obs-debug.log` when `WP_DEBUG` |

All settings are filterable (`kloudstack_obs_setting_{key}`) so managed stacks and site owners
can override in code without touching the database.

### 4.3 Multisite

Network-activated: connection string and sampling are network settings; cloud role name and
excluded paths are per-site. Each site's telemetry carries `wp_blog_id` and `wp_site_url` so one
Application Insights resource can serve a whole network. Per-site connection strings are a 2.2
feature, not Release 1.

---

## 5. Telemetry collection

### 5.1 Release 1

| Signal | Trigger | Notes |
|---|---|---|
| Request | Every non-excluded PHP request | Duration from `REQUEST_TIME_FLOAT`, response code at shutdown |
| Exception (uncaught) | `set_exception_handler` | Prior handler preserved and re-invoked |
| Exception (fatal) | `register_shutdown_function` + `error_get_last()` | E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR |
| Exception (PHP error) | `set_error_handler`, opt-in | Warnings and notices, off by default |
| Page view + AJAX + JS error | JS SDK v3 | Correlated to the server request (§5.4) |

### 5.2 Later releases

| Signal | Release | Mechanism |
|---|---|---|
| Dependency — MySQL | 2.1 | `SAVEQUERIES`-independent wrapper; aggregate count and total time by default, per-query only in debug |
| Dependency — HTTP | 2.1 | `http_api_debug` action; captures host, status, duration |
| Dependency — Redis | 2.1 | Object cache stats where the drop-in exposes them |
| Dependency — SMTP | 2.2 | `wp_mail_failed`, PHPMailer hooks |
| Dependency — Blob Storage | 2.2 | Via the HTTP collector, classified by hostname |
| Trace | 2.2 | Opt-in bridge from `error_log` and a `kloudstack_obs_trace()` helper |
| Metric | 2.1 | Peak memory, query count, cache hit ratio, external call count |

Dependency collection must be aggregate-first. Emitting one dependency item per SQL query on a
WooCommerce page would multiply ingestion cost by two orders of magnitude and is the single
most likely way this plugin generates a surprise Azure bill.

### 5.3 Load-mode difference

As an MU-plugin the collector loads before regular plugins and captures fatals raised during
their load. As a standard plugin it cannot. This is documented, surfaced in diagnostics, and is
the reason KloudStack managed stacks keep the MU-loader.

### 5.4 Correlation — Release 1 requirement

1. On request start, read an inbound `traceparent` header (Front Door, customer tracing) and
   adopt its trace ID; otherwise generate a W3C-compliant 16-byte trace ID.
2. Set `ai.operation.id` (trace ID) and `ai.operation.parentId` (span ID) on every server-side
   envelope.
3. Inject a telemetry initializer into the JS SDK configuration that stamps the same
   `ai.operation.id` onto browser telemetry.
4. Set `ai.operation.name` to a **normalised route**, not a raw URL — `GET /product/{slug}`
   rather than `GET /product/blue-widget` — so Azure Monitor aggregates usefully. Normalisation
   uses the matched rewrite rule and query var, with a filter for overrides.

Point 4 matters more than it looks: without it, a site with 10,000 posts produces 10,000
distinct operation names and every Azure Monitor performance view becomes unusable.

### 5.5 Full-page cache blind spot

When W3 Total Cache disk-enhanced, a reverse proxy or Front Door serves a cached response, PHP
never executes and no server-side request telemetry is emitted — while the JS SDK still fires.
Server request counts therefore under-report cached traffic, and client-to-server request ratios
will exceed 1:1 on cached sites.

This is inherent, not a defect. It must be stated in the plugin documentation and annotated on
every Marketplace workbook that charts request volume, or customers will read the gap as
missing telemetry.

---

## 6. Non-functional requirements

### 6.1 Performance

| Requirement | Target |
|---|---|
| Added server latency, p95 | ≤ 5 ms |
| Added server latency, p99 | ≤ 15 ms |
| Blocking network I/O on the request path | **Zero** |
| Added peak memory | ≤ 1 MB |
| Behaviour when Azure ingestion is slow or down | No effect on response time or availability |

The current implementation fails these. It performs a synchronous cURL POST inside
`register_shutdown_function`, with a 2 s connect and 3 s total timeout. Under PHP-FPM the
response is not released to the client until shutdown completes, so every page view can pay up
to five seconds of added latency when Azure ingestion is degraded.

Required implementation:

- Buffer telemetry in memory for the life of the request.
- Release the response first: `fastcgi_finish_request()`, or `litespeed_finish_request()`, or
  fall back to flushing headers with `Connection: close` and `Content-Length`.
- Send the buffer as one batched request (the ingestion endpoint accepts a JSON array).
- Gzip payloads above 1 KB.
- Circuit breaker: after 3 consecutive transport failures, stop sending for 5 minutes
  (transient-backed, so it is shared across workers). Log the trip once.
- Hard cap the buffer at 50 items per request; count and report drops as a metric.

### 6.2 Reliability

- No code path may raise a fatal, warning or notice into a customer's site. All collectors are
  wrapped and fail closed.
- Missing `curl` extension, missing `wp_remote_post`, malformed connection string, unreachable
  endpoint: all degrade to silent no-op with a diagnostics entry.
- The plugin must be safe to activate on a site already running the App Service Application
  Insights auto-instrumentation extension. It detects that case and warns about duplicate
  telemetry and doubled ingestion cost rather than silently doubling both.

### 6.3 Default exclusions

Excluded from request telemetry unless explicitly enabled: WP-Cron over HTTP, the heartbeat
API, `wp-admin/admin-ajax.php?action=heartbeat`, `/wp-json/…/health`, static asset requests
reaching PHP, WP-CLI and any non-web SAPI, XML-RPC, and requests from known uptime-check user
agents.

### 6.4 Platform floors

PHP 7.4, WordPress 6.0, tested to current. No Composer dependencies, no vendored runtime
libraries. The Application Insights JS SDK is loaded from Microsoft's CDN by default with a
bundled local copy as a configurable alternative (§7.4).

---

## 7. Privacy and data protection

Non-negotiable for WordPress.org review and Marketplace certification. The current
implementation sends client IP addresses, complete request URIs including query strings, and —
because `enableRequestHeaderTracking` and `enableResponseHeaderTracking` are both enabled —
cookies and authorization headers, to a third-party service, with no disclosure.

### 7.1 Requirements

| Control | Default | Behaviour |
|---|---|---|
| Client IP | Anonymised | Last octet (IPv4) / last 80 bits (IPv6) zeroed before transmission |
| Query strings | Allowlist | Only allowlisted keys retained; everything else replaced with `{redacted}`. Blocklist enforced regardless: `key`, `token`, `password`, `pwd`, `secret`, `auth`, `nonce`, `_wpnonce`, `access_token` |
| Request/response headers | Off | Opt-in, with an explicit warning that cookies and auth headers will be transmitted |
| Authenticated user identity | Off | Never sent unless explicitly enabled; hashed user ID when enabled, never email or login |
| POST bodies | Never collected | No setting |
| Exception messages | Collected | May contain data; documented, with a filter to scrub |
| Consent gate | Filter | `kloudstack_obs_has_consent` — when it returns false, no client-side SDK and no cookies |

### 7.2 Cookies

The JS SDK sets `ai_user` and `ai_session`. Under GDPR and ePrivacy these are analytics cookies
requiring consent in most jurisdictions. Release 1 must:

- Document the cookies, their purpose and lifetime in `readme.txt` and the settings page.
- Provide the consent filter above, defaulting to granted, so consent-management plugins can
  gate injection.
- Offer a cookie-less mode (`disableCookiesUsage: true`) as a one-click setting, with the
  trade-off — no session or user aggregation — stated plainly.

### 7.3 Disclosure obligations

- `wp_add_privacy_policy_content()` with suggested privacy-policy text.
- Personal-data exporter and eraser hooks registered, returning "no personal data is stored
  locally by this plugin" and pointing to Azure for the data actually held.
- `readme.txt` external-services section naming Microsoft Azure Application Insights, the
  ingestion endpoints, the CDN, what is transmitted, and links to Microsoft's terms and privacy
  statement. WordPress.org requires this for any plugin contacting a third-party service.

### 7.4 Third-party script loading

The JS SDK loads from `js.monitor.azure.com`. This is permitted but must be disclosed. Release 1
ships a bundled local copy of the SDK as a setting, which additionally allows sites with strict
CSP to avoid whitelisting an external origin. A `nonce` attribute is supported for CSP.

---

## 8. Telemetry schema contract

Versioned as `schema_version`. Marketplace workbook queries bind to this contract, so changes
are breaking changes and follow semantic versioning.

### 8.1 Custom dimensions (schema v1)

Present on all server-side telemetry:

| Dimension | Example | Source |
|---|---|---|
| `schema_version` | `1` | Constant |
| `plugin_version` | `2.0.0` | Constant |
| `load_mode` | `mu` \| `standard` | Runtime |
| `wp_version` | `6.8.1` | `get_bloginfo` |
| `php_version` | `8.3.6` | `PHP_VERSION` |
| `wp_context` | `front` \| `admin` \| `ajax` \| `rest` \| `cron` \| `login` | Runtime |
| `wp_theme` | `twentytwentyfive` | Active stylesheet |
| `wp_plugin_count` | `27` | Active plugin count |
| `wp_is_multisite` | `false` | `is_multisite()` |
| `wp_blog_id` | `1` | Multisite only |
| `wp_user_logged_in` | `true` \| `false` | Never the identity |
| `wp_memory_peak_mb` | `48.2` | `memory_get_peak_usage` |
| `wp_query_count` | `112` | `$wpdb->num_queries` |
| `wp_cache_hit` | `true` \| `false` \| `unknown` | Object cache, where detectable |
| `azure_environment` | `app-service` \| `container-apps` \| `aks` \| `vm` \| `unknown` | §8.2 |
| `azure_site_name` | `mysite` | `WEBSITE_SITE_NAME` |
| `azure_slot` | `production` \| `staging` | `WEBSITE_SLOT_NAME` |
| `azure_region` | `australiaeast` | `REGION_NAME` |

### 8.2 Azure context and cloud role

Detected from the environment, in order: `WEBSITE_SITE_NAME` (App Service),
`CONTAINER_APP_NAME` (Container Apps), `KUBERNETES_SERVICE_HOST` (AKS), Azure IMDS with a short
timeout and a long-lived cached result (VM), otherwise unknown.

- `ai.cloud.role` — `{azure_site_name}` or the configured override, falling back to the site
  host. Slot-qualified as `{site}-{slot}` when not production.
- `ai.cloud.roleInstance` — `WEBSITE_INSTANCE_ID` where available, otherwise `gethostname()`.

Correct role naming is what makes one Application Insights resource usable across an agency's
sites and across slots. It is the plugin's most visible Azure-native advantage and it is
currently missing entirely.

---

## 9. Compatibility

v3.0 marked eight third-party integrations as ✅ Supported. None of them had instrumentation
code. Publishing that matrix would breach the Marketplace certification checklist's own
prohibition on unsupported claims, and would fail a WordPress.org review on accuracy.

Three states replace it:

- **Compatible** — verified not to conflict; the plugin collects generic telemetry on these
  sites. No plugin-specific instrumentation.
- **Instrumented** — plugin-specific telemetry exists and is tested.
- **Planned / Research** — no code.

| Integration | Release 1 | Target |
|---|---|---|
| WordPress Core | Compatible | Instrumented (2.1) |
| W3 Total Cache | Compatible | Instrumented — cache hit/miss (2.1) |
| WooCommerce | Compatible | Instrumented — checkout and cart operations (2.2) |
| Elementor | Compatible | Compatible |
| Wordfence | Compatible | Research — conflicts with error handlers need testing |
| WP Mail SMTP | Compatible | Instrumented — mail dependency (2.2) |
| Redis Object Cache | Compatible | Instrumented (2.1) |
| Azure Blob Storage plugins | Compatible | Instrumented via HTTP collector (2.2) |
| Yoast, Gravity Forms, CF7, ACF, UpdraftPlus | Compatible | Assess after 1.0 |

Azure services (Front Door, Key Vault, MySQL Flexible Server, Cache for Redis) are monitored by
Azure diagnostic settings deployed by the Marketplace template, not by this plugin. v3.0 listed
them in the plugin's compatibility matrix, which conflated the two products.

---

## 10. Administration

### 10.1 Settings page

Under Tools → KloudStack Observability. Sections: Connection, Telemetry, Privacy, Advanced.
Where a setting is locked by environment or constant, show the value read-only with its source.

### 10.2 Diagnostics and self-test

The single most valuable support-cost reducer. One button, running:

| Check | Pass condition |
|---|---|
| Configuration | Connection string present, parseable, ingestion endpoint resolved |
| Azure environment | Detected, with the detected type and region shown |
| Outbound connectivity | Ingestion endpoint reachable within timeout |
| Test telemetry | A tagged test event accepted with HTTP 200 and an item count |
| Transport | Response-release mechanism available; which one is in use |
| Circuit breaker | Not tripped; last failure reason and time if it is |
| Duplicate instrumentation | App Service AI extension not also active |
| Client SDK | Injection enabled; consent gate state; cookie mode |
| Load mode | `mu` or `standard`, with the fatal-coverage implication |
| Recent activity | Items sent, dropped and sampled in the last hour |

Results are copyable as plain text for support tickets. Also registered in WordPress Site Health
so failures surface without visiting the page.

### 10.3 Not in Release 1

An in-plugin telemetry dashboard. Querying Application Insights from WordPress needs Azure AD
credentials in the site, which contradicts §7 and the Marketplace security model. Deep links
into the Azure portal for the configured resource instead.

---

## 11. Success criteria

Measurable replacements for v3.0's aspirational list:

1. Published on WordPress.org and passing plugin review without privacy or external-service
   findings.
2. Deployed on 100% of KloudStack managed WordPress stacks via the MU-loader, on a pinned
   tagged release.
3. Measured p95 added latency ≤ 5 ms on a reference WooCommerce workload.
4. Marketplace workbook queries return correct data against telemetry from the exact published
   plugin release.
5. Zero fatal errors attributable to the plugin across the KloudStack managed fleet for 30 days
   after rollout.
6. Client and server telemetry correlate: a browser page view can be joined to its server
   request by `operation_Id` in Azure Monitor.

---

## 12. Out of scope

Explicitly not the plugin's responsibility, to keep the boundary with the KloudStack platform
clear:

- AI-driven diagnosis, recommendations or automated remediation.
- Fleet management or cross-site aggregation.
- Provisioning Azure resources — that is the Marketplace Solution Template.
- Querying or displaying Application Insights data inside WordPress.
- Uptime monitoring — Azure availability tests do this.
- Log shipping to anything other than Application Insights.