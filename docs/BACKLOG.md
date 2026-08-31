# Backlog

Known work, not urgent. Each entry says what is wrong, why it matters, and what "done" looks like,
so it can be picked up without reconstructing the reasoning.

---

## 1. "Track WP-Cron requests" is a dead toggle

**Found 2026-08-31 on kloudstack.dev.** The setting was enabled deliberately to diagnose CPU spikes.
Twelve hours later, with `wp-cron.php` returning 200 and `DISABLE_WP_CRON` unset on the App Service,
**not one cron request had been recorded.**

`RequestCollector::isExcluded()` checks the setting:

```php
if ($context === WordPressContext::CONTEXT_CRON && !$this->settings->bool('track_cron')) {
    return true;
}
```

but `wp-cron.php` is also in `ALWAYS_EXCLUDE`, commented *"Paths excluded regardless of settings"*:

```php
private const ALWAYS_EXCLUDE = [
    '#/wp-cron\.php#',
    ...
```

So the toggle can never take effect. With `DOING_CRON` set the context check drops the request; if
not, the path pattern does. Either way, enabling it changes nothing.

**Decide which is intended, then make the code say it:**

- **Make the toggle work** — drop `#/wp-cron\.php#` from `ALWAYS_EXCLUDE` and let the `track_cron`
  check own the decision. Preferred: the setting exists, someone enabling it expects it to do
  something, and the check is already written.
- **Or remove the toggle** and document that cron is never recorded, pointing at the
  `kloudstack_obs_exclude_request` filter as the escape hatch.

**Also reword the description while in there.** It currently reads *"High volume and rarely useful."*
The first half is fair. The second is wrong — it is the only visibility into WordPress's scheduler,
and it was the first thing reached for when unexplained CPU needed diagnosing. That wording steers
people away from the correct diagnostic step.

---

## 2. No test asserts that a setting changes behaviour

The gap behind item 1, and behind three earlier bugs. Every test asserts Settings **stores and reads**
a value. None asserts that changing it **alters what the plugin does**.

Four settings have now shipped that saved correctly, displayed correctly, and did nothing:

| Setting | Symptom | Fixed |
|---|---|---|
| Debug log | option never bridged to the filter | 2.0.0-rc3 |
| Serve the JavaScript SDK locally | pointed at a missing asset | 2.0.0-rc3 |
| Cookie-less / Anonymise IP / Track admin | could not be turned off — `''` coerced back to default | 2.0.8 |
| Track WP-Cron requests | excluded by path regardless of the setting | open — item 1 |

Three of the four live in `RequestCollector::isExcluded()`, **which has no test at all**.
`ALWAYS_EXCLUDE` has no test. `ExcludedPathMatchingTest` covers only `matchesUserPattern()`, the
user-supplied patterns, not the method that decides whether a request is recorded.

**Done looks like:** one test per user-facing toggle asserting the behaviour flips *both ways* — not
that the value round-trips. For `isExcluded()` that is roughly eight tests, and it would have caught
three of the four bugs above.

The tests added in 2.0.8 are the right shape to copy — `SnippetInjectorTest` asserts the emitted
context changes with cacheability, `UpgradeTest` asserts defaults materialise and the notice fires.
The pattern exists; it has simply never been applied to the older settings.

---

## 3. Add PHP 8.5 to the CI matrix

The unit-test matrix runs 7.4, 8.2 and 8.4. PHP 8.5 is released, and a real incompatibility was
found only because a local container happened to ship it: `ReflectionMethod::setAccessible()` is
deprecated from 8.5, PHPUnit treats the notice as unexpected output, and it failed one test outright
while marking ten others risky. **CI was green throughout.**

Fixed in 2.0.8 by guarding the call on `PHP_VERSION_ID`, but the matrix would not catch the next one.

**Done looks like:** `8.5` added to the matrix in `ci.yml`. Expect it to surface other deprecations;
that is the point.
