#!/usr/bin/env python3
"""Compare 202-config/Http/CloudflareRanges.php with Cloudflare's published ranges.

Exit 0 when they match, 1 when they differ (printing the CIDRS list to paste),
2 when either side cannot be read. Needs network access to www.cloudflare.com.
"""
import pathlib
import re
import sys
import urllib.request

ROOT = pathlib.Path(__file__).resolve().parent.parent
PHP = ROOT / "202-config" / "Http" / "CloudflareRanges.php"
SOURCES = ["https://www.cloudflare.com/ips-v4", "https://www.cloudflare.com/ips-v6"]


def published() -> list[str]:
    ranges: list[str] = []
    for url in SOURCES:
        # Cloudflare refuses urllib's default User-Agent with a 403.
        request = urllib.request.Request(url, headers={"User-Agent": "curl/8"})
        with urllib.request.urlopen(request, timeout=20) as response:
            lines = [line.strip() for line in response.read().decode("ascii").splitlines()]
        found = [line for line in lines if line]
        if not found or not all(re.fullmatch(r"[0-9A-Fa-f.:]+/[0-9]{1,3}", line) for line in found):
            raise ValueError(f"{url} did not answer a list of CIDR ranges")
        ranges += found
    return ranges


def shipped() -> list[str]:
    text = PHP.read_text()
    block = re.search(r"public const CIDRS = \[(.*?)\];", text, re.S)
    if block is None:
        raise ValueError(f"no CIDRS list in {PHP}")
    return re.findall(r"'([^']+)'", block.group(1))


def main() -> int:
    try:
        theirs, ours = published(), shipped()
    except (OSError, ValueError) as error:
        print(f"cloudflare-ranges: {error}", file=sys.stderr)
        return 2
    if sorted(theirs) == sorted(ours):
        print(f"CloudflareRanges matches Cloudflare's {len(theirs)} published ranges.")
        return 0
    print("CloudflareRanges differs from Cloudflare's published ranges.")
    print("  added:   " + ", ".join(sorted(set(theirs) - set(ours))))
    print("  removed: " + ", ".join(sorted(set(ours) - set(theirs))))
    print("Paste into CIDRS:")
    print(", ".join(f"'{cidr}'" for cidr in theirs))
    return 1


if __name__ == "__main__":
    sys.exit(main())
