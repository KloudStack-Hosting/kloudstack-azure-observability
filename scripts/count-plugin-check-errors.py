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

A run that finds nothing prints no sections at all — just wp-cli's

    Success: Checks complete. No errors found.

which is a POSITIVE signal that the scanner ran to completion, and better
evidence than empty output. Release #26 failed because that line was treated as
garbage. Completion is now required: either findings were parsed, or that line
was seen. Output with neither means the scanner did not finish, and is a
failure rather than a pass — empty output is no longer accepted as clean.

Exit codes are the contract this is used through:
    0  parsed successfully; the error count is on stdout
    2  could not be parsed, or the run never reported completion — the caller
       MUST treat this as a failed check, not as a clean result. A scanner that
       did not run produces no findings, and that is indistinguishable from a
       pass unless it is checked for.

Codes named in --ignore-codes are counted separately and excluded from the
total. That exists for checks which cannot pass on a non-tag build by
construction, not to silence real findings.
"""

import argparse
import json
import sys


def parse(text):
    """Parse the per-file report format.

    Returns (findings, completed) where `completed` records whether wp-cli
    reported the run finishing.
    """
    findings = []
    completed = False

    for line in text.splitlines():
        line = line.strip()
        if not line or line.startswith("FILE:"):
            continue
        if line.startswith("Success:"):
            # "Success: Checks complete. No errors found." — the scanner ran and
            # found nothing. This is the only proof of completion available when
            # there are no findings to parse.
            completed = True
            continue
        if not line.startswith("["):
            # Anything else — a PHP fatal, a wp-cli Error:, a truncated write —
            # is a run that did not complete, not a clean result.
            raise ValueError(f"unexpected line in report: {line[:120]}")
        findings.extend(json.loads(line))

    return findings, completed


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("report")
    ap.add_argument("--ignore-codes", default="")
    args = ap.parse_args()

    ignore = {c.strip() for c in args.ignore_codes.split(",") if c.strip()}

    with open(args.report, encoding="utf-8") as fh:
        text = fh.read()

    try:
        findings, completed = parse(text)
    except (ValueError, json.JSONDecodeError) as exc:
        print(f"unparseable Plugin Check output: {exc}", file=sys.stderr)
        return 2

    # Silence is not success. With no findings AND no completion line there is
    # nothing to show the scanner ever ran, which is the failure this whole
    # script exists to tell apart from a clean result.
    if not findings and not completed:
        print("Plugin Check reported neither findings nor completion — "
              "no evidence it ran.", file=sys.stderr)
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
