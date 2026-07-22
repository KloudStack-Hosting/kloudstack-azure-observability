# Latency benchmark — release gate C4

Measures the plugin against the performance budget in specification §6.1.

| Requirement | Target |
|---|---|
| Added server latency, p95 | ≤ 5 ms |
| Added server latency, p99 | ≤ 15 ms |
| Added peak memory | ≤ 1 MB |
| Behaviour when Azure ingestion is slow or down | No effect on response time |

```bash
./run.sh              # full run, recorded to results/
./run.sh --quick      # 2 rounds, for iterating on the harness itself
./run.sh --down       # tear down
```

Results land in `results/` as JSON and are committed — §6.1 requires the measurement to be
recorded, and a number nobody can re-derive is not a measurement.

## Why not against a real site

kloudstack.dev and kloudstack.com.au share one App Service Plan (`ASP-KloudStack-9d27`, P0v3,
**one worker, two sites**). Load-testing either one degrades the other, and a contended plan
cannot resolve a 5 ms effect in any case.

## What it measures

Time to the last byte of the response, from a client, over a reused keep-alive connection —
what the visitor actually waits for.

| | Scenario | Ingestion behaviour |
|---|---|---|
| A | baseline | plugin deactivated |
| B | healthy | responds in 5 ms |
| C | slow | responds in 3000 ms |
| D | stalled | never responds inside the plugin's 5 s timeout |

Only the **delta against A** is meaningful. Absolute latency inside a Docker VM on Windows is
not; the difference between two scenarios sampled seconds apart is.

Scenarios are **interleaved in blocks**, not run end to end. A machine that gets slower during a
run would otherwise hand every later scenario a penalty indistinguishable from plugin overhead.

## Things that silently invalidate this benchmark

Each of these produced a confident, wrong number before being fixed. They are listed because the
failure mode in every case was a result that looked fine.

**The plugin sending nothing.** A plugin that has switched itself off has magnificent overhead.
The harness now counts telemetry items arriving at the sink per scenario and fails the run if a
scenario that should have sent did not. Three separate causes hit this:

- `WORDPRESS_CONFIG_EXTRA` is evaluated by `wp-config.php` calling `getenv()` *at request time*,
  and PHP-FPM defaults to `clear_env=yes`. The constant was never defined. `run.sh` writes it
  with `wp config set` instead.
- `Transport` does not use `wp_remote_post`. It calls cURL directly with
  `CURLOPT_SSL_VERIFYPEER => true`, so no WordPress filter can affect it and the sink's
  certificate must be in the container trust store.
- The circuit breaker is a transient shared across workers and stays open for five minutes. Once
  the stalled scenario tripped it, every later block sent nothing.

**The PHP-FPM worker pool.** The stock image gives 5 workers with `pm = dynamic`. Telemetry is
sent after the response is released, but the *worker* is not released, so the pool saturates and
queueing appears as plugin overhead in every scenario — it attributed ~29 ms to the plugin that
was a request waiting for a worker. The pool is now `static`, 16 workers, recorded in the result.

**Docker container startup.** Every `docker compose run` competes for CPU with whatever is being
sampled. Container operations are kept out of measured windows and followed by a settle period.

**Measuring the wrong PHP.** Provenance was collected by shelling out to the `php` binary, which
is the CLI SAPI, and recorded `release_mechanism: ABSENT` — `fastcgi_finish_request` genuinely
does not exist under CLI. It is now probed over HTTP through a real FPM worker.

**Memory granularity.** `memory_get_peak_usage(true)` reports 8 MB allocator chunks and cannot
resolve a 1 MB budget; every scenario returned an identical figure with a zero delta.

## Reading the result

`passed: false` with only C and D failing is not the same defect as B failing. B is the plugin's
cost on a healthy site. C and D are its behaviour when Azure is degraded, where the dominant
effect is FPM worker occupancy rather than the response being held.
