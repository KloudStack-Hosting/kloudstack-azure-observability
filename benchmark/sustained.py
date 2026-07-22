"""Does the circuit breaker actually protect the site when ingestion goes slow?

bench.py cannot answer this. It interleaves short blocks and resets the breaker between them, so
a protection that needs several seconds of continuous badness to engage never gets the chance —
and the result looks identical to the breaker not working at all. Every scenario-C block in every
recorded run sent telemetry on all 280 requests, meaning the breaker never once opened.

This runs a single uninterrupted stretch of slow ingestion instead, and watches two things
together:

  latency  should spike, then fall back towards baseline once the breaker opens
  items    should climb, then flatline — the proof that it opened, rather than latency
           improving for some unrelated reason

Latency recovering on its own would be suggestive. Latency recovering *at the same moment*
telemetry stops arriving is the causal link.

  python sustained.py                 # 60 s of a 3 s endpoint
  python sustained.py --seconds 90 --delay-ms 4000
"""

import argparse
import http.client
import json
import statistics
import subprocess
import sys
import time
import urllib.request

WEB = ("127.0.0.1", 8099)
CONTROL = "http://127.0.0.1:8098"
BREAKER_TRANSIENT = "kloudstack_obs_breaker"


def compose(*args):
    return subprocess.run(["docker", "compose", *args], capture_output=True, text=True)


def sink(path):
    with urllib.request.urlopen(CONTROL + path, timeout=10) as r:
        return json.loads(r.read().decode())


def run_phase(seconds, tag):
    """Fire requests back to back, recording (elapsed, latency, cumulative items)."""
    conn = http.client.HTTPConnection(*WEB, timeout=180)
    samples = []
    started = time.perf_counter()
    last_poll = 0.0
    items = 0

    try:
        while True:
            elapsed = time.perf_counter() - started
            if elapsed >= seconds:
                break

            t0 = time.perf_counter()
            conn.request("GET", "/", headers={"X-Bench-Tag": tag, "Accept-Encoding": "identity"})
            resp = conn.getresponse()
            resp.read()
            latency = (time.perf_counter() - t0) * 1000.0

            # Polled about once a second rather than per request: the control call is cheap but
            # not free, and charging it to the measurement would be self-defeating.
            if elapsed - last_poll >= 1.0:
                items = sink("/state")["items"]
                last_poll = elapsed

            samples.append((elapsed, latency, items))
    finally:
        conn.close()

    return samples


def buckets(samples, width=5.0):
    out = {}
    for elapsed, latency, items in samples:
        out.setdefault(int(elapsed // width), []).append((latency, items))
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--seconds", type=int, default=60)
    ap.add_argument("--delay-ms", type=int, default=3000)
    args = ap.parse_args()

    print("==> baseline: plugin off, 15 s")
    compose("run", "--rm", "cli", "plugin", "deactivate", "kloudstack-azure-observability")
    sink("/set?delay_ms=5")
    time.sleep(2)
    base = run_phase(15, "BASE")
    base_p50 = statistics.median([s[1] for s in base])
    base_p95 = sorted(s[1] for s in base)[int(len(base) * 0.95)]
    print(f"    p50 {base_p50:.1f} ms   p95 {base_p95:.1f} ms   ({len(base)} requests)")

    print(f"\n==> sustained: plugin on, ingestion takes {args.delay_ms} ms, {args.seconds} s")
    print("    breaker cleared first, then left alone for the whole run")
    compose("run", "--rm", "cli", "plugin", "activate", "kloudstack-azure-observability")
    compose("run", "--rm", "cli", "transient", "delete", BREAKER_TRANSIENT)
    sink(f"/set?delay_ms={args.delay_ms}")
    sink("/reset")
    time.sleep(2)

    samples = run_phase(args.seconds, "SUST")

    print()
    print(f"    {'window':>12} {'reqs':>6} {'p50 ms':>9} {'p95 ms':>9} {'items sent':>11}")
    print("    " + "-" * 50)

    prev_items = 0
    opened_at = None
    for idx in sorted(buckets(samples)):
        rows = buckets(samples)[idx]
        lat = [r[0] for r in rows]
        items = rows[-1][1]
        p50 = statistics.median(lat)
        p95 = sorted(lat)[min(int(len(lat) * 0.95), len(lat) - 1)]
        delta = items - prev_items

        # The first window in which telemetry stopped arriving while requests kept coming.
        if opened_at is None and delta == 0 and prev_items > 0:
            opened_at = idx * 5

        print(f"    {idx * 5:>4}-{idx * 5 + 5:<3}s {len(rows):>6} {p50:>9.1f} {p95:>9.1f} "
              f"{items:>7} (+{delta})")
        prev_items = items

    final = sink("/state")
    print()
    print(f"    telemetry items accepted in total: {final['items']}")
    print(f"    requests issued:                   {len(samples)}")

    breaker = compose("run", "--rm", "cli", "eval",
                      'echo json_encode(get_transient("kloudstack_obs_breaker"));')
    print(f"    breaker state at end:              {(breaker.stdout or '').strip()[-160:]}")

    if opened_at is None:
        print("\n    VERDICT: telemetry never stopped — the breaker did NOT open.")
        return 1

    tail = [s[1] for s in samples if s[0] >= opened_at + 5]
    tail_p50 = statistics.median(tail) if tail else float("nan")
    print(f"\n    VERDICT: telemetry stopped at ~{opened_at}s — the breaker opened.")
    print(f"    p50 after it opened: {tail_p50:.1f} ms   against a {base_p50:.1f} ms baseline")
    return 0


if __name__ == "__main__":
    sys.exit(main())
