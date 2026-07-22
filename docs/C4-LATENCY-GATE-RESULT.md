# C4 — latency gate result

**Verdict: FAILS specification §6.1 as written.** Three distinct causes, two of them fixable in
the plugin, one of them a claim in the specification that cannot be true for an in-process sender.

Harness, method and the list of things that silently invalidate this measurement:
[benchmark/README.md](../benchmark/README.md). Raw results: `benchmark/results/`.

## Measured

WordPress 7.0.2, PHP 8.3.32 (`fpm-fcgi`), opcache on, PHP-FPM `pm = static` with 16 workers,
release mechanism `fastcgi_finish_request` — probed over HTTP through a real worker, not the CLI
binary. 200 samples per scenario, interleaved in blocks of 25.
Reference workload: WordPress core front page, 40 posts, 10 per page.

Ingestion latency is controlled by a local sink standing in for Application Insights.

Two full runs, reported together because the difference between them is itself a result.

| | Scenario | Δp50 | Δp95 | Δp99 |
|---|---|---|---|---|
| B | ingestion healthy (5 ms) | +2.7 / +9.9 | **+51.2 / +38.6** | +58.7 / **−5.7** |
| C | ingestion slow (3000 ms) | +11.8 / +14.3 | **+1713 / +1693** | +1834 / +1840 |
| D | ingestion stalled (30 s) | +11.5 / +22.1 | **+3493 / +3507** | +3737 / +3813 |

All figures milliseconds, relative to the plugin-off baseline in the same run. Budget:
Δp95 ≤ 5 ms, Δp99 ≤ 15 ms.

**C and D are stable to within ~1%** across runs. Those findings are solid.

**B's tail is not stable.** Its Δp99 was +58.7 ms in one run and −5.7 ms in the other, meaning
run-to-run noise on this host is the same size as the effect. B's *median* overhead is
consistently positive and in the 3–10 ms range, which already sits at or above a 5 ms p95 budget
— so B fails — but any specific tail number for B should not be quoted. Pinning it down needs a
quieter host than a Windows Docker VM, or far more samples.

Added peak memory: **+15.2 KB** against a 1 MB budget. **Passes comfortably.**

Telemetry actually delivered: 280 items in each of B, C and D, 0 in A. The run is not measuring
a plugin that had switched itself off.

## What passes

**`fastcgi_finish_request()` works, and it is doing the job it was added for.** In C the
ingestion endpoint takes three seconds, and the median request is slower by 11.8 ms. If the
response were still being held until the send completed — the 1.x behaviour this rewrite
targeted — the median would be roughly +3000 ms. It is not. The response reaches the visitor
before the telemetry POST begins.

That is the single most important property of the redesign and it is confirmed.

## Finding 1 — a TLS handshake on every page view

B fails on a *healthy* endpoint, which is not explained by anything Azure is doing. Since B's tail
is too noisy to attribute (above), this was measured directly inside the container instead of
inferred from the end-to-end numbers:

```
new connection each send : 88.64 ms
reused connection        : 46.72 ms
handshake cost per send  : 41.92 ms
```

`Transport::curl()` calls `curl_init()` and `curl_close()` per request, so every page view pays a
fresh TCP connect and TLS handshake. PHP-FPM workers persist across requests, so a handle held in
a static and reset with `curl_reset()` between uses would keep the connection alive and roughly
halve the worker time each page view spends on telemetry.

Against a sink on the same host this is 42 ms. Against Azure ingestion over the internet the
handshake is a real round trip and the saving would be larger, so this figure is conservative.

Worth noting the trade-off honestly: a persistent handle must be keyed by endpoint, reset rather
than reused blind, and tolerate a connection the far end has already dropped.

## Finding 2 — releasing the response does not release the worker

C and D miss the budget by seconds at p95 while their medians stay clean. That shape is worker
occupancy, not a held response.

The response goes to the visitor immediately, but the FPM worker stays busy until the send
finishes. At sustained request rates the pool saturates and later requests queue — and queueing
is indistinguishable from latency to the visitor who is waiting.

`CircuitBreaker`'s own docblock already predicts this: *"a worker pool exhausted by telemetry
retries takes the site down just as effectively as a slow database. Under load, an Azure outage
would become a WordPress outage."* The benchmark confirms the mechanism is real and quantifies
it. It is also, almost certainly, the mechanism behind the DevTest stack incident on 2026-07-21.

## Finding 3 — the breaker protects against failure, never against slowness

This is the gap worth acting on, and the benchmark makes it visible by separating C from D.

In **D** the endpoint never answers, sends time out, `handleResult()` sees status 0, and after
three failures the breaker opens for five minutes. The median stays clean — the protection works.

In **C** the endpoint answers successfully in three seconds, comfortably inside the 5 s timeout.
`handleResult()` records a *success*. The breaker never trips. Every request continues to tie up
a worker for three seconds, indefinitely, with no mitigation of any kind.

Slow-but-working is what Azure throttling and regional latency actually look like — a far more
likely production condition than a hard outage, and the only one the plugin has no answer for.

Suggested fix: time each send and treat sustained slowness as a breaker signal — for example,
open the breaker when the last N sends each exceed ~1 s. Small change, and it closes the gap
using machinery that already exists.

## Finding 4 — §6.1's wording cannot be satisfied in-process

> Behaviour when Azure ingestion is slow or down: **No effect on response time or availability**

No in-process sender can deliver this. Worker capacity is shared, so consuming it always has
*some* effect on concurrent requests. The requirement as written is unachievable, which makes it
useless as a gate — it can only ever be failed.

The defensible version is narrower and is what the plugin actually delivers:

> No effect on the response already in flight. Bounded effect on concurrent capacity, with the
> circuit breaker limiting exposure to N failed or slow sends before telemetry is suspended.

Anything stronger means taking the send out of the request lifecycle entirely — a queue drained
by WP-Cron or an external worker — which is a materially bigger change than the two fixes above
and should be a deliberate decision, not something smuggled in under a release gate.

## Recommendation

Findings 1 and 3 are both contained changes to `Transport`/`CircuitBreaker` and both are worth
making before `v2.0.0-rc1`. Finding 4 is a decision about what the plugin promises, and is the
one that needs a call rather than a patch.

The gate should stay failed until §6.1 is either met or rewritten to something honest.
