"""Latency benchmark for the §6.1 performance budget — release gate C4.

Measures what the visitor actually waits for: time to the last byte of the response, from a
client, over a reused keep-alive connection.

Method
------
Scenarios are interleaved in blocks rather than run end to end. A container that gets slower over
a run -- from cache growth, host scheduling, a background process on the laptop -- would
otherwise hand every later scenario a penalty that looks exactly like plugin overhead. With
round-robin blocks any drift lands on all scenarios equally and cancels out of the delta.

The measured quantity is always a *delta against the baseline in the same round*, never an
absolute number. Absolute latency inside a Windows Docker VM is not meaningful; the difference
between two scenarios sampled seconds apart is.

Scenarios
---------
  A  baseline   plugin deactivated
  B  fast       plugin active, ingestion responds in 5 ms
  C  slow       plugin active, ingestion responds in 3000 ms
  D  stalled    plugin active, ingestion never responds within the plugin's timeout

C is the scenario the gate exists for. Under 1.x this added seconds to every page view, because
the POST happened inside a shutdown handler and PHP-FPM does not release the response until
shutdown completes. If C is indistinguishable from A, fastcgi_finish_request() is doing its job.
"""

import argparse
import http.client
import json
import statistics
import subprocess
import sys
import time
import urllib.request
from datetime import datetime, timezone

WEB = ("127.0.0.1", 8099)
CONTROL = "http://127.0.0.1:8098"

SCENARIOS = [
    # key, label, plugin active, sink delay ms
    ("A", "baseline (plugin off)", False, 5),
    ("B", "ingestion fast (5 ms)", True, 5),
    ("C", "ingestion slow (3000 ms)", True, 3000),
    ("D", "ingestion stalled (30 s)", True, 30000),
]

# Must match CircuitBreaker::TRANSIENT and CircuitBreaker::SLOW_MS.
BREAKER_TRANSIENT = "kloudstack_obs_breaker"
BREAKER_SLOW_MS = 1000

# From spec §6.1. These are the gate.
BUDGET_P95_MS = 5.0
BUDGET_P99_MS = 15.0
BUDGET_MEMORY_BYTES = 1024 * 1024


def compose(*args, capture=True):
    cmd = ["docker", "compose", *args]
    return subprocess.run(cmd, capture_output=capture, text=True)


def sink(path):
    with urllib.request.urlopen(CONTROL + path, timeout=10) as r:
        return json.loads(r.read().decode())


def set_plugin(active):
    action = "activate" if active else "deactivate"
    r = compose("run", "--rm", "cli", "plugin", action, "kloudstack-azure-observability")
    if r.returncode != 0 and "already" not in (r.stdout + r.stderr).lower():
        raise RuntimeError(f"wp plugin {action} failed:\n{r.stdout}\n{r.stderr}")


def settle(seconds=2.0):
    """Let the host go quiet before sampling.

    Docker container startup is the loudest thing on this machine during a run and it is not part
    of what the gate is measuring.
    """
    time.sleep(seconds)


def reset_breaker():
    """Clear the circuit breaker between blocks.

    Without this the benchmark silently measures nothing. The breaker opens after 3 consecutive
    transport failures and stays open for 5 minutes, in a transient shared by every worker -- so
    the stalled-ingestion scenario trips it, and every subsequent block sends no telemetry at all.
    Those blocks would then look identical to the baseline and the gate would pass by measuring a
    plugin that had switched itself off.
    """
    compose("run", "--rm", "cli", "transient", "delete", BREAKER_TRANSIENT)


def sample_block(tag, path, n, warmup):
    """Time n requests over one reused connection. Returns milliseconds."""
    conn = http.client.HTTPConnection(*WEB, timeout=180)
    times = []
    try:
        for i in range(warmup + n):
            start = time.perf_counter()
            conn.request("GET", path, headers={"X-Bench-Tag": tag, "Accept-Encoding": "identity"})
            resp = conn.getresponse()
            body = resp.read()
            elapsed = (time.perf_counter() - start) * 1000.0

            if resp.status != 200:
                raise RuntimeError(f"{path} returned HTTP {resp.status} ({len(body)} bytes)")

            if i >= warmup:
                times.append(elapsed)
    finally:
        conn.close()
    return times


def pct(values, p):
    if not values:
        return float("nan")
    return statistics.quantiles(sorted(values), n=100, method="inclusive")[p - 1]


def summarise(values):
    return {
        "n": len(values),
        "min": round(min(values), 2),
        "p50": round(statistics.median(values), 2),
        "p95": round(pct(values, 95), 2),
        "p99": round(pct(values, 99), 2),
        "max": round(max(values), 2),
        "mean": round(statistics.fmean(values), 2),
    }


def environment():
    """Provenance for the recorded result.

    A latency number without the worker-pool size and PHP version beside it is not reproducible,
    and the worker pool in particular changed these figures by an order of magnitude.
    """
    def sh(cmd):
        r = compose("exec", "-T", "wp", "sh", "-c", cmd)
        return (r.stdout or "").strip()

    # Probed over HTTP, through a real FPM worker.
    #
    # An earlier version shelled out to the `php` binary, which is the CLI SAPI. It recorded
    # sapi=cli and release_mechanism=ABSENT -- because fastcgi_finish_request genuinely does not
    # exist under CLI -- and wrote that into the result file as though it described the thing
    # being measured. It is exactly the environment fact the whole gate depends on, stated
    # backwards.
    probe = (
        "<?php header('Content-Type: application/json'); echo json_encode(["
        "'php' => PHP_VERSION,"
        "'sapi' => PHP_SAPI,"
        "'opcache' => ini_get('opcache.enable') ? 'on' : 'off',"
        "'release_mechanism' => function_exists('fastcgi_finish_request')"
        " ? 'fastcgi_finish_request' : 'ABSENT',"
        "]);"
    )
    sh(f"cat > /var/www/html/bench-env.php <<'PHPEOF'\n{probe}\nPHPEOF")

    try:
        with urllib.request.urlopen(f"http://{WEB[0]}:{WEB[1]}/bench-env.php", timeout=15) as r:
            info = json.loads(r.read().decode())
    except Exception as exc:  # pragma: no cover - provenance must not abort a completed run
        info = {"error": f"probe failed: {exc}"}
    finally:
        sh("rm -f /var/www/html/bench-env.php")

    info["fpm_pool"] = sh("grep -E '^pm( |\\.)' /usr/local/etc/php-fpm.d/www.conf | tr '\\n' ' '")
    info["wordpress"] = sh(
        "php -r '$v=@file_get_contents(\"/var/www/html/wp-includes/version.php\");"
        "preg_match(\"/wp_version = .([0-9.]+)/\", (string)$v, $m); echo $m[1] ?? \"?\";'"
    )
    return info


def read_memory():
    """Peak memory per scenario tag, as recorded by the probe mu-plugin."""
    r = compose("exec", "-T", "wp", "sh", "-c", "cat /tmp/bench-mem.log 2>/dev/null || true")
    by_tag = {}
    for line in (r.stdout or "").splitlines():
        parts = line.split()
        if len(parts) == 2 and parts[1].isdigit():
            by_tag.setdefault(parts[0], []).append(int(parts[1]))
    return by_tag


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--rounds", type=int, default=8)
    ap.add_argument("--block", type=int, default=25, help="measured requests per scenario per round")
    ap.add_argument("--warmup", type=int, default=10, help="discarded requests at the start of each block")
    ap.add_argument("--path", default="/", help="URL path making up the reference workload")
    ap.add_argument("--label", default="wordpress-core", help="workload name recorded in the report")
    ap.add_argument("--out", default="results", help="directory for the recorded result")
    args = ap.parse_args()

    samples = {key: [] for key, _, _, _ in SCENARIOS}
    ingested = {key: 0 for key, _, _, _ in SCENARIOS}

    compose("exec", "-T", "wp", "sh", "-c", "rm -f /tmp/bench-mem.log")

    total = args.rounds * len(SCENARIOS)
    done = 0
    plugin_state = None

    for rnd in range(1, args.rounds + 1):
        for key, label, active, delay in SCENARIOS:
            if plugin_state != active:
                set_plugin(active)
                plugin_state = active

            sink(f"/set?delay_ms={delay}&status=200")
            sink("/reset")

            # Starting a wp-cli container costs real CPU on the same host, and the first samples
            # of a block land right in the middle of it. Left unaddressed this showed up as tens
            # of milliseconds of "plugin overhead" that was actually Docker starting a container.
            settle()

            times = sample_block(key, args.path, args.block, args.warmup)
            samples[key].extend(times)

            # Read the counter after the block. The plugin sends post-response, so a short grace
            # period avoids crediting this block's sends to the next one.
            time.sleep(0.5)
            ingested[key] += sink("/state")["items"]

            # Reset after any block whose endpoint was slow enough to trip the breaker -- which
            # since the slow-send fix means C as well as D, not D alone. Leaving C's trip in place
            # would hand D an already-open breaker and make a suspended plugin look like a fast
            # one. Done after the block so container churn stays out of the measured window.
            if active and delay >= BREAKER_SLOW_MS:
                reset_breaker()

            done += 1
            print(
                f"  [{done:>3}/{total}] round {rnd} {key} {label:<26} "
                f"p50 {statistics.median(times):7.1f} ms",
                flush=True,
            )

    memory = read_memory()

    base = summarise(samples["A"])
    report = {
        "generated": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "environment": environment(),
        "workload": args.label,
        "path": args.path,
        "method": {
            "rounds": args.rounds,
            "block": args.block,
            "warmup_per_block": args.warmup,
            "interleaved": True,
            "samples_per_scenario": args.rounds * args.block,
        },
        "telemetry_items_received": ingested,
        "scenarios": {},
        "budget": {
            "p95_ms": BUDGET_P95_MS,
            "p99_ms": BUDGET_P99_MS,
            "memory_bytes": BUDGET_MEMORY_BYTES,
        },
    }

    base_mem = statistics.median(memory.get("A", [0])) if memory.get("A") else None
    failures = []

    for key, label, active, delay in SCENARIOS:
        stats = summarise(samples[key])
        entry = {
            "label": label,
            "plugin_active": active,
            "sink_delay_ms": delay,
            "telemetry_items": ingested[key],
            **stats,
        }

        # A scenario that sent nothing would post a flawless overhead figure and mean nothing.
        # The stalled scenario is exempt: sending nothing is the correct outcome there, since the
        # breaker is supposed to open.
        if active and delay < 10000 and ingested[key] == 0:
            failures.append(f"{key} sent no telemetry — overhead was measured on an idle plugin")

        if key != "A":
            entry["delta_p50"] = round(stats["p50"] - base["p50"], 2)
            entry["delta_p95"] = round(stats["p95"] - base["p95"], 2)
            entry["delta_p99"] = round(stats["p99"] - base["p99"], 2)

            if entry["delta_p95"] > BUDGET_P95_MS:
                failures.append(f"{key} p95 +{entry['delta_p95']} ms exceeds {BUDGET_P95_MS} ms")
            if entry["delta_p99"] > BUDGET_P99_MS:
                failures.append(f"{key} p99 +{entry['delta_p99']} ms exceeds {BUDGET_P99_MS} ms")

        if memory.get(key):
            entry["peak_memory_bytes"] = int(statistics.median(memory[key]))
            if base_mem and key != "A":
                entry["delta_memory_bytes"] = entry["peak_memory_bytes"] - int(base_mem)
                if entry["delta_memory_bytes"] > BUDGET_MEMORY_BYTES:
                    failures.append(
                        f"{key} peak memory +{entry['delta_memory_bytes']} bytes exceeds 1 MB"
                    )

        report["scenarios"][key] = entry

    report["failures"] = failures
    report["passed"] = not failures

    print()
    print(json.dumps(report, indent=2))

    import os

    os.makedirs(args.out, exist_ok=True)
    stamp = report["generated"].replace(":", "").replace("-", "")
    path = os.path.join(args.out, f"{args.label}-{stamp}.json")
    with open(path, "w", encoding="utf-8") as fh:
        json.dump(report, fh, indent=2)
    print(f"\nrecorded: {path}")

    if failures:
        print("\nC4 FAILED:")
        for f in failures:
            print(f"  - {f}")
        return 1

    print("\nC4 PASSED against spec §6.1.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
