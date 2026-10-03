#!/usr/bin/env python3
"""Check that every Group Overview group is the sum of the groups under it.

    group-sums.py TABLE.tsv

Reads html-table.py's output for #group-overview-table (depth, label, then
the figures) and, for every row that has rows one level deeper under it,
compares its Clicks, Leads, Income and Cost with the sum of those rows'.
The top-level rows are compared with "Totals for report" the same way.
Prints "ok", or one line per group that does not add up. A table with no
row deeper than 0 is reported, so a report that never nested cannot pass.
"""
import re
import sys
from decimal import Decimal

COLUMNS = ('Clicks', 'Leads', 'Income', 'Cost')


def number(text):
    """"$1,234.50", "($-5.00)", "12" -> Decimal; the page's own formats."""
    t = text.strip()
    negative = t.startswith('(') and t.endswith(')')
    t = t.strip('()').replace('$', '').replace(',', '')
    if not re.fullmatch(r'-?\d+(\.\d+)?', t):
        raise ValueError('not a figure: %r' % text)
    value = Decimal(t)
    return -abs(value) if negative else value


def main(path):
    rows = [line.rstrip('\n').split('\t') for line in open(path, encoding='utf-8')]
    head = next((r for r in rows if r[0] == '#'), None)
    if head is None:
        print('no header row')
        return 1
    index = {name: head.index(name) for name in COLUMNS}
    body = []
    total = None
    for r in rows:
        if r[0] == '#':
            continue
        figures = tuple(number(r[index[c]]) for c in COLUMNS)
        if r[1] == 'Totals for report':
            total = figures
        else:
            body.append((int(r[0]), r[1], figures))
    if not any(depth > 0 for depth, _, _ in body):
        print('no nested rows')
        return 1
    problems = []

    def check(label, own, children):
        if not children:
            return
        summed = tuple(sum(c[i] for c in children) for i in range(len(COLUMNS)))
        if summed != own:
            problems.append('%s: %s != children %s' % (label, own, summed))

    for i, (depth, label, own) in enumerate(body):
        children = []
        for d, _, figures in body[i + 1:]:
            if d <= depth:
                break
            if d == depth + 1:
                children.append(figures)
        check('%d %s' % (depth, label), own, children)
    if total is not None:
        check('Totals for report', total, [f for d, _, f in body if d == 0])
    print('\n'.join(problems) if problems else 'ok')
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1]))
