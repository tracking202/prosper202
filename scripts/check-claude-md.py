#!/usr/bin/env python3
"""Hold CLAUDE.md to what it names and to a size.

Every session loads CLAUDE.md whole, so it is held under a byte budget, and
a test, rule or file it names in backticks must be in the tree: a name that
no longer exists sends a reader looking for a check that is not there.

Usage: check-claude-md.py [path-to-CLAUDE.md]   (run from the repo root)
Exits 0 when clean, 1 on a finding, 2 when it could not run.
"""

import re
import subprocess
import sys
from pathlib import Path

BUDGET_BYTES = 60_000

# A backticked token that is a path to a source file.
PATH_RE = re.compile(r"^[\w.\-/]+\.(?:php|sh|py|js|yml|yaml|md|css|neon|dist|json|go|txt)$")
# A PHP test or PHPStan rule class, or a Go test function.
CLASS_RE = re.compile(r"\b[A-Z][A-Za-z0-9]+(?:Test|Rule)\b")
GO_TEST_RE = re.compile(r"\bTest[A-Z][A-Za-z0-9]+\b")
# Paths a checkout does not track.
UNTRACKED_PREFIXES = ("vendor/", "usr/")


def tracked_files() -> list[str]:
    out = subprocess.run(
        ["git", "ls-files"], capture_output=True, text=True, check=False
    )
    if out.returncode != 0 or not out.stdout.strip():
        print("check-claude-md: git ls-files listed nothing; run from the repo root", file=sys.stderr)
        sys.exit(2)
    return out.stdout.splitlines()


def go_test_functions(files: list[str]) -> set[str]:
    names: set[str] = set()
    for f in files:
        if f.startswith("go-cli/") and f.endswith("_test.go"):
            try:
                text = Path(f).read_text(encoding="utf-8")
            except OSError as e:
                print(f"check-claude-md: cannot read {f}: {e}", file=sys.stderr)
                sys.exit(2)
            names.update(re.findall(r"^func (Test\w+)\(", text, flags=re.M))
    return names


def main() -> int:
    path = Path(sys.argv[1] if len(sys.argv) > 1 else "CLAUDE.md")
    try:
        raw = path.read_bytes()
    except OSError as e:
        print(f"check-claude-md: cannot read {path}: {e}", file=sys.stderr)
        return 2
    text = raw.decode("utf-8")

    findings: list[str] = []
    if len(raw) > BUDGET_BYTES:
        findings.append(
            f"{path} is {len(raw)} bytes, over the {BUDGET_BYTES} byte budget. "
            "An entry is the failure shape, the rule and the check, in a few lines; "
            "the incident belongs in the commit message."
        )

    files = tracked_files()
    basenames = {f.rsplit("/", 1)[-1] for f in files}
    go_tests = go_test_functions(files)

    checked = 0
    for lineno, line in enumerate(text.splitlines(), 1):
        for span in re.findall(r"`([^`]+)`", line):
            names: list[tuple[str, bool]] = []
            if "/" in span and PATH_RE.match(span) and not span.startswith(UNTRACKED_PREFIXES):
                names.append((span, any(f == span or f.endswith("/" + span) for f in files)))
            for name in GO_TEST_RE.findall(span):
                names.append((name, name in go_tests))
            for name in CLASS_RE.findall(span):
                if GO_TEST_RE.fullmatch(name):
                    continue
                names.append((name, name + ".php" in basenames))
            for name, found in names:
                checked += 1
                if not found:
                    findings.append(f"{path}:{lineno}: `{name}` is not in the tree")

    # A run that resolved no names read nothing: say so rather than pass.
    if checked == 0:
        print(f"check-claude-md: found no test, rule or file names in {path}", file=sys.stderr)
        return 2

    for f in findings:
        print(f)
    if findings:
        return 1
    print(f"{path}: {len(raw)} bytes (budget {BUDGET_BYTES}), {checked} names resolved")
    return 0


if __name__ == "__main__":
    sys.exit(main())
