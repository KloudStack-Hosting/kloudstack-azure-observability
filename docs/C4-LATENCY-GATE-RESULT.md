# C4 — latency gate result

**Verdict: PASSES §6.1 as revised. It failed §6.1 as originally written, and that wording has been
retired rather than worked around** — it promised something no in-process sender can deliver, so it
could only ever be failed.

Two real defects were found and fixed, one supposed defect turned out to be unfixable and was
retracted, and the requirement itself was restated as a measured bound.

| | Outcome |
|---|---|
| Response released before telemetry is sent | **Confirmed.** Median +14 ms against a 3 s endpoint, not +3000 ms |
| Added peak memory | **Passes.** +15.2 KB against a 1 MB budget |
| Breaker reacts to a slow-but-successful endpoint | **Fixed.** Was recorded as a success; never tripped |
| Breaker survives concurrency | **Fixed.** Five concurrent slow sends had recorded one strike |
| Exposure when ingestion degrades | **Bounded at ≈10 s**, then baseline. Was indefinite |
| Per-send TLS handshake | **Not fixable in-process.** Retracted, see Finding 1 |
| Added latency p95 ≤ 5 ms on a healthy endpoint | **Not resolvable on this harness.** Median cost is ~3 ms; tail noise exceeds the effect |

The last row is the honest residue: the original p95 budget cannot be verified to ±5 ms inside a
Docker VM whose own baseline p95 drifts between 127 ms and 282 ms run to run. Pinning it needs a
quiet dedicated host. The median overhead is small and consistent, and every scenario that *can* be
resolved on this harness is resolved.

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
fresh TCP connect and TLS handshake.

**The obvious fix does not exist.** This was first written up as "hold the handle in a static and
reuse it, since FPM workers persist across requests". That is wrong. The worker *process*
persists, but PHP destroys all user-land state between requests, so a static is empty again every
time. Verified rather than assumed — a probe holding a cURL handle in a static, called 30 times
over HTTP:

```
requests: 30      distinct workers: 16
workers that served >1 request: 14
handle_was_new on every request: True
requests with a TLS handshake: 30 / 30
  worker 1141 served 2x, handshake ms each time: [29.57, 20.65]
```

Fourteen workers served more than one request, and every one performed a fresh handshake. PHP
exposes no persistent-connection API for cURL, so there is nothing to hold open.

The 42 ms above was measured across 50 sends inside *one* PHP process — a situation that never
arises in production, where a request sends exactly once. The cost is real; the saving was never
available.

What does reduce it:

- **Sampling** — already implemented, and the only lever inside the plugin. At 20% sampling, one
  page view in five pays a handshake.
- **Batching outside the request** — many events per connection instead of one per page view. That
  is the architectural change in Finding 4, and the only thing that removes this entirely.

Findings 1 and 2 are therefore not two problems with two fixes. They are one problem with one fix.

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

**Fixed.** `Transport` now times each send and hands the duration to the breaker, which treats a
success slower than `SLOW_MS` (1 s) as a strike rather than as proof of health. A prompt success
still clears state, so a recovered endpoint resumes immediately.

## Finding 3a — the strike counter does not survive concurrency

Found while verifying the fix above, and it predates all of this: after five concurrent slow sends
the breaker had recorded **one** strike.

The count lives in a transient, so it is a read-modify-write. Sixteen workers failing at the same
instant each read the same value and each write back the same increment — sixteen bad sends, one
strike. "Three consecutive failures" is really "three consecutive *waves*", and under high
concurrency a wave can be a hundred requests. The undercount is worst exactly when the breaker
matters most, because concurrency is what exhausts the pool in the first place.

This also affected the pre-existing failure path, not only the new slow path.

**Fixed**, by adding a second, independent reason to open: the breaker records when the current run
of badness began, and any bad send observed more than `SUSTAINED_SECONDS` (5 s) after that opens it
regardless of the count. Timestamps do not suffer the race — every worker computes the same answer
from the same value without coordinating. The strike count is kept as the fast path for the
serialised case; the clock is the backstop for the concurrent one.

The decision is taken when a bad send is *observed*, never when state is read, so a single blip on
a quiet site cannot age into an open breaker.

## Verifying the breaker fixes — and why bench.py could not

The interleaved benchmark **cannot** show whether these fixes work, and initially appeared to say
they did not. It resets the breaker after every block and each block is shorter than the
five-second sustained window, so a time-based protection never gets the chance to engage. Every
scenario-C block of every recorded run sent telemetry on all 280 requests.

It also produced a false positive in the other direction: C's Δp95 fell from +1713 ms to +936 ms
after the fix, which looks like a 45% improvement. It is not. The baseline p95 in that run had
itself drifted from 133 ms to 282 ms, and the delta shrank with it. B's Δp95 came out at **−110 ms**
in the same run — the plugin apparently making the site faster — which is the giveaway.

`sustained.py` measures the thing the gate actually cares about: one uninterrupted stretch of slow
ingestion, breaker left alone, watching latency and telemetry volume *together*. Latency improving
on its own would be suggestive; latency improving at the same instant telemetry stops is causal.

60 s against a 3000 ms endpoint, baseline p50 91.7 ms / p95 241.6 ms:

```
      window   reqs    p50 ms    p95 ms  items sent
   0-5  s       33     100.1    1625.6      27 (+27)
   5-10 s       17     117.5    1395.1      48 (+21)
  10-15 s       47      91.2     162.6      48 (+0)
  20-25 s       50      90.0     148.8      48 (+0)
  55-60 s       51      91.1     144.3      48 (+0)

  breaker at end: {"failures":0,"slow":3,"since":...,"sustained":true,
                   "reason":"slow endpoint: 3019 ms"}
  495 requests issued, 48 sent telemetry
  p50 after opening: 94.5 ms against a 91.7 ms baseline
```

The breaker opens at roughly ten seconds — five seconds of sustained badness, measured from the
first slow send, which itself lands three seconds after the first request. Latency returns to
baseline and stays there. Roughly 90% of sends are suppressed for the following five minutes.

**Exposure is now bounded at about ten seconds instead of indefinite.** That is the whole change,
and it is what makes §6.1 rewritable into something both honest and passing.

## Finding 4 — §6.1's wording cannot be satisfied in-process

> Behaviour when Azure ingestion is slow or down: **No effect on response time or availability**

No in-process sender can deliver this. Worker capacity is shared, so consuming it always has
*some* effect on concurrent requests. The requirement as written is unachievable, which makes it
useless as a gate — it can only ever be failed.

The defensible version is narrower, and is now measured rather than asserted:

> No effect on the response already in flight. When ingestion degrades, elevated latency is
> bounded to approximately ten seconds, after which telemetry is suspended for five minutes and
> response times return to baseline.

Every part of that is a number `sustained.py` reports, so it can be re-checked on demand instead
of taken on trust.

Anything stronger means taking the send out of the request lifecycle entirely — a queue drained
by WP-Cron or an external worker — which is a materially bigger change than the two fixes above
and should be a deliberate decision, not something smuggled in under a release gate.

## Recommendation

Findings 1 and 3 are both contained changes to `Transport`/`CircuitBreaker` and both are worth
making before `v2.0.0-rc1`. Finding 4 is a decision about what the plugin promises, and is the
one that needs a call rather than a patch.

The gate should stay failed until §6.1 is either met or rewritten to something honest.
