#!/usr/bin/env python3
"""Count errors in a `wp plugin check --format=json` report.

The output is NOT a single JSON document. It is one section per file — a bare
`FILE: <name>` line followed by a JSON array of findings — repeated, e.g.

    FILE: kloudstack-azure-observability.php
    [{"line":0,"column":0,"type":"ERROR","code":"...","message":"..."}]

    FILE: readme.txt
    [{"line":0,"column":0,"type":"ERROR","code":"...","message":"..."}]

Feeding that to json.load() fails on the first `FILE:` line, which is what
happened on Release #24.

Exit codes are the contract this is used through:
    0  parsed successfully; the error count is on stdout
    2  could not be parsed — the caller MUST treat this as a failed check, not
       as a clean result. A scanner that did not run produces no findings, and
       that is indistinguishable from a pass unless it is checked for.

Codes named in --ignore-codes are counted separately and excluded from the
total. That exists for checks which cannot pass on a non-tag build by
construction, not to silence real findings.
"""

import argparse
import json
import sys


def parse(text):
    """Yield findings from the per-file report format."""
    for line in text.splitlines():
        line = line.strip()
        if not line or line.startswith("FILE:"):
            continue
        if not line.startswith("["):
            # Anything else is a message from a run that did not complete.
            raise ValueError(f"unexpected line in report: {line[:120]}")
        for finding in json.loads(line):
            yield finding


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("report")
    ap.add_argument("--ignore-codes", default="")
    args = ap.parse_args()

    ignore = {c.strip() for c in args.ignore_codes.split(",") if c.strip()}

    with open(args.report, encoding="utf-8") as fh:
        text = fh.read()

    if not text.strip():
        print(0)
        return 0

    try:
        findings = list(parse(text))
    except (ValueError, json.JSONDecodeError) as exc:
        print(f"unparseable Plugin Check output: {exc}", file=sys.stderr)
        return 2

    counted = [f for f in findings if f.get("code") not in ignore]
    skipped = [f for f in findings if f.get("code") in ignore]

    for f in skipped:
        print(f"  ignored ({f.get('code')}): {f.get('message', '')[:150]}",
              file=sys.stderr)

    for f in counted:
        print(f"  ERROR {f.get('code')}: {f.get('message', '')[:200]}",
              file=sys.stderr)

    print(len(counted))
    return 0


if __name__ == "__main__":
    sys.exit(main())
