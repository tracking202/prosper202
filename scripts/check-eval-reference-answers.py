#!/usr/bin/env python3
"""Every eval case's ask has its own branch in the reference agent.

Agent Evals runs on landing, not on pull requests into a version branch, so a
case merged without a reference answer turns the branch red with nothing to
catch it first. This sends each case's ask through
tests/fixtures/agent-eval/reference-agent.sh with a stub `p202` on PATH and
fails, naming the case, when the ask reaches the script's default branch
(which marks the file named by P202_REFERENCE_AGENT_FALLTHROUGH).

What the branch then does with the stub's answers is not checked here: the
Agent Evals run against a live instance is that check.

    python3 -I scripts/check-eval-reference-answers.py
"""

from __future__ import annotations

import json
import os
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
CASES = ROOT / "tests/fixtures/agent-eval/cases"
AGENT = ROOT / "tests/fixtures/agent-eval/reference-agent.sh"

# Cases whose answer is, on purpose, the default branch's `report summary`.
DEFAULT_ANSWER = frozenset([
    "reporting-001-summary-grounded",
])

# Cases merged before this check whose answer is in an open pull request
# (#487 for setup-source-003). They pass with or without one; the line goes
# once the answer has landed.
KNOWN_MISSING = frozenset([
    "setup-source-003-chatgpt-ads-is-one-step",
])

STUB = """#!/bin/sh
printf '%s\\n' "$*" >> "$P202_STUB_LOG"
printf '{"data":[]}\\n'
"""


def cases() -> list[tuple[str, str, str]]:
    found = []
    for path in sorted(CASES.glob("*.json")):
        data = json.loads(path.read_text())
        listed = data if isinstance(data, list) else data.get("cases")
        if not isinstance(listed, list):
            sys.exit(f"{path.name}: no list of cases")
        for case in listed:
            if not isinstance(case.get("id"), str) or not isinstance(case.get("ask"), str):
                sys.exit(f"{path.name}: a case without a string id and ask")
            found.append((path.name, case["id"], case["ask"]))
    return found


def main() -> int:
    listed = cases()
    if not listed:
        sys.exit(f"no cases found under {CASES}")
    ids = {case_id for _, case_id, _ in listed}
    stale = (DEFAULT_ANSWER | KNOWN_MISSING) - ids
    if stale:
        print(f"DEFAULT_ANSWER or KNOWN_MISSING names cases that do not exist: {sorted(stale)}")
        return 1

    missing, wrongly_answered, answered_now = [], [], []
    with tempfile.TemporaryDirectory() as tmp:
        bin_dir = Path(tmp, "bin")
        bin_dir.mkdir()
        stub = bin_dir / "p202"
        stub.write_text(STUB)
        stub.chmod(0o755)
        for file_name, case_id, ask in listed:
            marker = Path(tmp, "fallthrough")
            marker.unlink(missing_ok=True)
            env = {
                "PATH": f"{bin_dir}{os.pathsep}{os.environ.get('PATH', '')}",
                "HOME": tmp,
                "P202_EVAL_ASK": ask,
                "P202_STUB_LOG": str(Path(tmp, "calls.log")),
                "P202_REFERENCE_AGENT_FALLTHROUGH": str(marker),
            }
            try:
                # The branch may fail on the stub's answers; only which branch ran is asked.
                subprocess.run(["sh", str(AGENT)], env=env, stdin=subprocess.DEVNULL,
                               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                               timeout=30, check=False)
            except subprocess.TimeoutExpired:
                pass
            fell_through = marker.exists()
            if case_id in KNOWN_MISSING:
                if not fell_through:
                    answered_now.append(case_id)
            elif fell_through and case_id not in DEFAULT_ANSWER:
                missing.append(f"{file_name}: {case_id}")
            elif not fell_through and case_id in DEFAULT_ANSWER:
                wrongly_answered.append(f"{file_name}: {case_id}")

    for case_id in answered_now:
        print(f"note: {case_id} has an answer now; remove it from KNOWN_MISSING.")
    if missing:
        print("These eval cases have no answer in tests/fixtures/agent-eval/reference-agent.sh;"
              " their ask falls through to the default `report summary`, and Agent Evals fails"
              " on them once merged. Add a branch for each ask:")
        for line in missing:
            print(f"  {line}")
    if wrongly_answered:
        print("These cases are listed in DEFAULT_ANSWER but their ask has a branch of its own;"
              " remove them from the list:")
        for line in wrongly_answered:
            print(f"  {line}")
    if missing or wrongly_answered:
        return 1
    print(f"{len(listed)} eval cases: each ask has its own reference answer"
          f" ({len(DEFAULT_ANSWER)} answered by the default summary on purpose,"
          f" {len(KNOWN_MISSING)} listed as known missing).")
    return 0


if __name__ == "__main__":
    sys.exit(main())
