=== KloudStack Observability for Azure ===
Contributors: kloudstack
Tags: application insights, azure, monitoring, observability, telemetry
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send WordPress request, exception and browser telemetry to Azure Application Insights. Built for WordPress running on Azure.

== Description ==

KloudStack Observability for Azure connects a WordPress site to Azure Application Insights, so
you can see request performance, errors and browser telemetry in Azure Monitor alongside the
rest of your Azure estate.

The plugin is built specifically for WordPress hosted on Azure. It reads its configuration from
App Service application settings automatically, names telemetry using the Azure site, slot and
region so one Application Insights resource can serve many sites, and produces a telemetry
schema designed to work with Azure Monitor workbooks.

= Features =

* Server-side request telemetry with normalised route names
* Uncaught exception and fatal error telemetry
* Browser telemetry via the official Application Insights JavaScript SDK
* Correlation between browser and server telemetry using W3C trace context
* Automatic Azure environment detection — App Service, Container Apps, AKS and Virtual Machines
* Cloud role naming from the Azure site and deployment slot
* Configurable sampling to control Azure ingestion cost
* Privacy controls: IP anonymisation, query-string redaction, consent gating
* Built-in diagnostics self-test and WordPress Site Health integration

= Designed for Azure =

This plugin assumes Azure hosting. It will run elsewhere if you supply a connection string, but
Azure environment enrichment will be unavailable and support is limited to Azure deployments.

= Performance =

Telemetry is buffered during the request and transmitted only after the response has been
released to the visitor, so Application Insights latency or availability never affects your
site's response time. A circuit breaker suspends transmission if the ingestion endpoint becomes
unreachable.

== External services ==

This plugin sends data to Microsoft Azure Application Insights, a third-party service operated
by Microsoft. It does this so that telemetry collected from your site can be stored, queried and
visualised in Azure Monitor.

**What is sent, and when:** on each non-excluded page request, the plugin transmits the request
route, duration, HTTP response code, and contextual data about the WordPress installation
(WordPress version, PHP version, active theme, plugin count, memory usage, query count) to the
Application Insights ingestion endpoint configured for your Azure resource. Uncaught exceptions
and fatal errors are transmitted with their message, type and stack trace. If browser telemetry
is enabled, the Application Insights JavaScript SDK is loaded in visitors' browsers and sends
page views, AJAX calls and JavaScript errors directly to the same endpoint.

**Visitor data:** client IP addresses are anonymised before transmission by default. Query
strings are redacted against an allowlist by default. Request and response headers, which may
contain cookies and authorization data, are **not** transmitted unless you explicitly enable
that option. The browser SDK sets `ai_user` and `ai_session` cookies unless cookie-less mode is
enabled; in most jurisdictions these require visitor consent, and a filter is provided to gate
injection behind a consent management platform.

**Endpoints contacted:** the Application Insights ingestion endpoint from your connection string
(typically `https://<region>.in.applicationinsights.azure.com` or
`https://dc.services.visualstudio.com`), and `https://js.monitor.azure.com` for the JavaScript
SDK unless the bundled local copy is selected in settings.

Microsoft terms of use: https://azure.microsoft.com/support/legal/
Microsoft privacy statement: https://privacy.microsoft.com/privacystatement

No data is sent to KloudStack. The plugin does not phone home.

== Installation ==

1. Install and activate the plugin.
2. If your site runs on Azure App Service, add `APPLICATIONINSIGHTS_CONNECTION_STRING` to your
   application settings. The plugin will detect it automatically.
3. Otherwise, go to Tools > KloudStack Observability and paste your Application Insights
   connection string.
4. Run the built-in self-test to confirm telemetry is reaching Azure.

== Frequently Asked Questions ==

= Do I need an Azure subscription? =

Yes. The plugin sends telemetry to an Application Insights resource in your own Azure
subscription. Azure charges for telemetry ingestion and retention apply under your Azure
agreement.

= Does this slow down my site? =

No. Telemetry is transmitted after the response has been released to the visitor, so Azure
latency does not affect page load time.

= Why do my browser page views outnumber my server requests? =

If you use full-page caching, cached responses are served without running PHP, so no server-side
request telemetry is produced — while the browser SDK still fires. This is expected.

= Can I use one Application Insights resource for several sites? =

Yes. Each site reports a distinct cloud role name, derived from the Azure site name or set
manually in settings.

== Changelog ==

= 2.0.0 =
* Initial public release.

== Upgrade Notice ==

= 2.0.0 =
Initial public release.