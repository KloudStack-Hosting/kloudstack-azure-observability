# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[semantic versioning](https://semver.org/).

The telemetry schema is versioned independently — see the functional specification, section 8.

## [Unreleased]

### Fixed
- **Settings no longer appear to reset when the plugin updates.** v2.0.1 changed the telemetry
  defaults from true to false to satisfy WordPress.org guidelines 7 and 9. Settings are stored as
  individual options, so a site that had never pressed "Save Changes" had nothing in the database
  and ran on the defaults in code — and that change stopped telemetry on every such install without
  a word. Defaults are now written to the options table, so the database rather than a constant
  decides what an existing install does, and a future default change cannot reach backwards. Where
  an upgrade finds telemetry off that the owner never chose to turn off, a dismissible notice says
  so. Telemetry is never re-enabled automatically: doing that would recreate exactly what the review
  objected to.

  The plugin previously had no way to detect an upgrade at all — no stored version, no upgrade
  routine, and `kloudstack_obs_schema_version` listed in `uninstall.php` while never being written
  by anything. It now stores a version and compares it on `plugins_loaded`, which is what WordPress
  core does with `db_version`; activation hooks do not fire on update and never fire at all for a
  must-use plugin, which is how managed stacks load this.
- **Toggles that default on can be switched off again.** An unchecked box saves as an empty string,
  which was being coerced back to the default — so "Cookie-less browser telemetry", "Anonymise
  visitor IP addresses" and "Track admin requests" silently refused to turn off and redrew
  themselves ticked. `cookieless` was the costly one: turning it off is the documented route to
  session and returning-visitor aggregation, and that was unreachable.
- **Browser telemetry no longer reports every visitor as one trace.** The server's trace context was
  written into the page body, so a page cache stored it and replayed it to everyone — one request's
  operation id stamped on thousands of unrelated page views. On a cache hit PHP never runs, so no
  server span exists for that visit at all: the correlation was invented rather than stale, and the
  workbook's browser-to-server join returned wrong rows rather than none. Application Insights also
  hashes `operation_Id` to decide sampling, so one shared id collapsed per-page-view sampling into a
  single site-wide keep-or-drop decision.

  The trace context is now emitted only where PHP demonstrably handles every request — logged-in
  users, `DONOTCACHEPAGE`, non-GET — with the `kloudstack_obs_response_is_uncacheable` filter for
  hosts that know their own caching. Everywhere else the SDK generates its own operation id per page
  view, which is correct under any cache. Correlation still works in the other direction, and always
  did: the SDK sends a W3C traceparent on XHR and fetch and `Correlation` adopts it, so REST,
  admin-ajax and other dynamic calls correlate normally. This needs no cache-plugin detection and
  behaves the same under W3TC in any mode, WP Rocket, LiteSpeed, WP Super Cache, a CDN or Front Door.
- **The `unload` console violation is gone.** Chromium now refuses `unload` listeners under
  Permissions Policy, and the SDK hooks it alongside `beforeunload` and `pagehide`, logging a
  violation on every page view while changing nothing. `disablePageUnloadEvents` now excludes it,
  which also makes pages eligible for the back/forward cache.

### Changed
- **Browser telemetry is now opt-in.** `client_enabled` defaults to `false`. Enabling it loads
  Microsoft's Application Insights JavaScript SDK into every visitor's browser, which
  WordPress.org guidelines 7 and 9 require to be off by default. Server-side telemetry is
  unchanged: it transmits only to the site owner's own Azure resource, and only once they supply
  a connection string.
- **Cookie-less browser telemetry is now the default.** `cookieless` defaults to `true`, so
  switching browser telemetry on cannot silently create a consent obligation.

  **Trade-off:** without the `ai_user` and `ai_session` cookies, Application Insights reports page
  and dependency timings but cannot aggregate sessions or identify returning visitors — no
  "users" or "sessions" in the portal. Owners who need that can turn cookie-less mode off and
  take on the consent duty, ideally gating injection through the `kloudstack_obs_has_consent`
  filter from a consent management platform.

### Fixed
- **The client snippet is delivered through the script API** rather than echoed as a raw
  `<script>` tag, as WordPress.org requires. It is attached with `wp_add_inline_script()` to a
  src-less registered handle — Microsoft's loader fetches the SDK itself, so enqueuing that URL
  directly would load it twice. Any CSP nonce supplied through `kloudstack_obs_script_nonce` is
  now applied via the `wp_script_attributes` filter. The rendered JavaScript is unchanged.
- **The debug log no longer writes to the uploads root.** It now lives in
  `uploads/kloudstack-azure-observability/`, created with `wp_mkdir_p()` and protected by
  `.htaccess` and `index.php`. Previously it was written as
  `uploads/kloudstack-obs-debug.log`, publicly fetchable at a guessable URL despite containing
  request paths and error messages. Debug logging remains off by default.

## [2.0.0-rc3]

### Fixed
- **"Debug log" is no longer a dead toggle.** The setting is now bridged to the `kloudstack_obs_debug_log`
  filter in `Plugin::boot()`, so enabling it (with `WP_DEBUG`) actually writes the debug log; previously
  the option saved but never took effect.
- **"Serve the JavaScript SDK locally" now works.** Microsoft's Application Insights JS SDK (Web 3.4.3, MIT)
  is bundled at `src/Client/assets/`. Previously the option pointed at a missing file, so enabling it under a
  strict Content Security Policy broke browser telemetry.

### Changed
- **The duplicate-instrumentation self-test is now actionable** — it names how to disable the App Service
  Application Insights auto-instrumentation extension (keeping the connection string) rather than just
  "remove one of them".
- **The header-collection setting is relabelled** to "Collect browser request and response headers" — it only
  drives the browser SDK's AJAX/fetch header tracking; there is no server-side header capture. Sensitive
  query-parameter redaction remains always-on.
- Plugin URI now points at the public GitHub repository.
- **WordPress.org Plugin Check compliance.** The telemetry transport now uses `wp_remote_post`
  instead of raw cURL; `wp_parse_url` replaces `parse_url`; the debug log writes to the uploads
  directory via `wp_upload_dir()` rather than a hard-coded `wp-content` path; the browser-snippet
  builder no longer uses heredoc syntax; and the redundant `load_plugin_textdomain()` call is
  dropped (WordPress.org loads translations automatically). No runtime behaviour changes.

### Tests
- Added coverage for excluded-path matching (`RequestCollector::matchesUserPattern`) and the `Log::enabled()`
  gate.

## [2.0.0-rc2]

### Changed
- **MU-loader (2.1.0) now respects the WordPress activation state.** It boots the plugin at
  must-use priority only when the plugin is active, so activation and deactivation behave exactly
  as they do for any plugin — a site owner or KloudStack can turn observability off from the
  Plugins screen and it genuinely stops. Earlier the loader booted unconditionally, which removed
  that control. An active plugin still gets the early-load benefit (catching fatals raised while
  other plugins load). The KloudStack image activates the plugin once on first deploy so it is on
  by default. Plugin code is unchanged from rc1.

## [2.0.0]

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
