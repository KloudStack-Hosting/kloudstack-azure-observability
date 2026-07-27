# Security Policy

## Reporting a vulnerability

Please report security vulnerabilities **privately**, not as a public issue.

Use GitHub's private advisory form:
<https://github.com/KloudStack-Hosting/kloudstack-azure-observability/security/advisories/new>

If you cannot use that, email **security@kloudstack.com.au** with enough detail to reproduce.

We will acknowledge within a few business days and keep you updated as we work on a fix. Once a
fix is released we are happy to credit you, unless you would rather remain anonymous.

## Scope

This plugin transmits telemetry from a WordPress site to Azure Application Insights. The security
concerns most relevant to it:

- Leakage of sensitive data into telemetry (request bodies, headers, query strings, PII).
- The connection string or instrumentation key being exposed to the browser or to unauthorised users.
- Any code path that could be induced to run on a visitor request.

Privacy behaviour — IP anonymisation, query-string redaction, and the fact that request and
response **header tracking is off by default** — is described in the functional specification.

## Supported versions

Security fixes are made against the latest released 2.x version. The 1.x code under `legacy/`
shipped only inside the KloudStack WordPress image, is unmaintained, and receives no fixes.
