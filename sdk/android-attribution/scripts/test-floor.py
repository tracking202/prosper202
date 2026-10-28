#!/usr/bin/env python3
"""Fail when a suite executed fewer tests than it should have.

    test-floor.py <results-dir>=<floor> ...

Each <results-dir> is a Gradle JUnit XML directory (for example
core/build/test-results/test). The executed count is tests minus skipped,
summed over the directory's TEST-*.xml files; a green task that ran nothing
(a filter that matched no class, a runner that found no tests) reads as
"0 tests executed" here and fails, rather than passing silently.
"""
import glob
import os
import re
import sys

if len(sys.argv) < 2:
    sys.exit(__doc__)
bad = False
for arg in sys.argv[1:]:
    directory, _, want = arg.rpartition("=")
    want = int(want)
    files = glob.glob(os.path.join(directory, "TEST-*.xml"))
    ran = 0
    for f in files:
        head = open(f, encoding="utf-8").read(4000)
        m = re.search(r'<testsuite[^>]*\btests="(\d+)"[^>]*\bskipped="(\d+)"', head)
        if m is None:
            print(f"{f}: no testsuite counts", file=sys.stderr)
            bad = True
            continue
        ran += int(m[1]) - int(m[2])
    print(f"{directory}: {ran} tests executed in {len(files)} classes (floor {want})")
    bad |= ran < want
sys.exit(1 if bad else 0)
