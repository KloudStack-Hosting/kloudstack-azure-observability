# Contributing

Thanks for your interest. Bug reports and focused pull requests are welcome.

## Reporting bugs

Open an issue using the **Bug report** template. The self-test report it asks for
(**Tools → KloudStack Observability → Run self-test → Copy report**) answers most of the
questions we would otherwise have to ask, and it is scrubbed of secrets.

For anything security-related, see [`SECURITY.md`](SECURITY.md) — please do not open a public
issue.

## Development

No WordPress install is needed for the unit suite.

```bash
composer install     # dev dependencies only; the shipped plugin vendors nothing
composer run check   # PHPCS + PHPStan + PHPUnit
```

CI runs the same checks across PHP 7.4 through 8.4, plus coding standards, static analysis, the
translation template, and a parse check of the rendered client script. A pull request needs all of
them green.

## Ground rules the code holds to

These are enforced in review because each traces to a specific failure:

1. **No blocking I/O on the request path.** Telemetry goes out only after the response is
   released to the visitor.
2. **Every hook callback is wrapped so a fault cannot surface on the site.** An observability
   plugin that takes a site down is worse than none.
3. **No Composer dependencies in the shipped artifact.** Plugins share one PHP process; vendored
   libraries collide.
4. **Conservative privacy defaults.** IP anonymisation on, query strings redacted, header
   tracking off.
5. **The telemetry schema is a contract.** Azure Monitor workbooks bind to it; changing a
   dimension is a breaking change and needs a schema-version bump.

## Scope

This plugin is deliberately for **Azure-hosted WordPress** and Azure Application Insights.
Support for other hosting or other APM backends is generally out of scope.

By contributing you agree your contributions are licensed under GPL-2.0-or-later, the licence of
this project.
