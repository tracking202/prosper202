#!/usr/bin/env python3
"""Refuse a flag spelled in snake_case in any tracked text file.

Both CLIs show and document their flags in kebab-case (--aff-campaign-id) and
accept the snake_case spelling as an alias. A line may still show a snake
spelling when it also shows the kebab one (it is explaining the alias), and a
test listed in ALIAS_TESTS may pass the alias on purpose.

Usage: scripts/check-flag-spelling.py   (from the repository root; exit 1 on a finding)
"""
import re
import subprocess
import sys

TOKEN = re.compile(r'(?:^|(?<=[^A-Za-z0-9_-]))--([a-z0-9]+(?:_[a-z0-9]+)+)')

SKIP_PREFIXES = ('vendor/', 'node_modules/', '202-css/', '202-img/', '202-js/')
SKIP_SUFFIXES = ('.png', '.jpg', '.jpeg', '.gif', '.ico', '.svg', '.woff', '.woff2',
                 '.ttf', '.eot', '.zip', '.gz', '.phar', '.mmdb', '.min.js', '.min.css', '.map')

# Tests that pass the snake_case alias on purpose, to prove it still works.
ALIAS_TESTS = {
    'go-cli/cmd/cli_errors_test.go',
    'tests/Cli/KebabCaseArgvInputTest.php',
    'tests/Cli/SnakeCaseOptionsStillWorkTest.php',
}


def main() -> int:
    files = subprocess.run(['git', 'ls-files'], check=True, capture_output=True, text=True).stdout.split('\n')
    findings = []
    for path in files:
        if not path or path.startswith(SKIP_PREFIXES) or path.endswith(SKIP_SUFFIXES) or path in ALIAS_TESTS:
            continue
        try:
            with open(path, encoding='utf-8') as fh:
                lines = fh.read().split('\n')
        except (UnicodeDecodeError, IsADirectoryError, FileNotFoundError):
            continue
        for number, line in enumerate(lines, 1):
            for match in TOKEN.finditer(line):
                kebab = '--' + match.group(1).replace('_', '-')
                if kebab not in line:
                    findings.append(f'{path}:{number}: --{match.group(1)} (write {kebab})')
    for finding in findings:
        print(finding)
    if findings:
        print(f'{len(findings)} flag(s) spelled in snake_case; flags are written in kebab-case.', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
