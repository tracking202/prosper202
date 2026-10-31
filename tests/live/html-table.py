#!/usr/bin/env python3
"""Print one table of a saved page as tab-separated rows.

    html-table.py PAGE.html TABLE_ID

Each row of the table's body is one line: the depth of the row's label (the
Group Overview indents a group's rows by `padding-left: <n>rem`, 1.25rem a
level; 0 when a row carries none), then the text of every cell, with the
text of each element in it separated by one space (a pill and the sentence
under it read as two words). The header row is printed first, prefixed "#".
A pass reads the figures a person reads, from the table the page drew,
rather than grepping the markup for numbers that could sit anywhere.

Exits 2 when the page has no table with that id.
"""
import re
import sys
from html.parser import HTMLParser


class Table(HTMLParser):
    def __init__(self, want):
        super().__init__(convert_charrefs=True)
        self.want = want
        self.depth = 0          # nesting of <table> inside the wanted one
        self.inside = False
        self.found = False
        self.section = None
        self.rows = []
        self.row = None
        self.cell = None
        self.indent = 0.0

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == 'table':
            if self.inside:
                self.depth += 1
            elif a.get('id') == self.want:
                self.inside = True
                self.found = True
            return
        if not self.inside or self.depth:
            return
        if tag in ('thead', 'tbody'):
            self.section = tag
        elif tag == 'tr':
            self.row = []
            self.indent = 0.0
        elif tag in ('td', 'th') and self.row is not None:
            self.cell = []
        elif self.cell is not None:
            m = re.search(r'padding-left:\s*([0-9.]+)rem', a.get('style') or '')
            if m and len(self.row) == 0:
                self.indent = float(m.group(1))

    def handle_endtag(self, tag):
        if tag == 'table':
            if self.depth:
                self.depth -= 1
            elif self.inside:
                self.inside = False
            return
        if not self.inside or self.depth:
            return
        if tag in ('td', 'th') and self.cell is not None:
            self.row.append(' '.join(' '.join(self.cell).split()))
            self.cell = None
        elif tag == 'tr' and self.row is not None:
            self.rows.append((self.section, round(self.indent / 1.25), self.row))
            self.row = None

    def handle_data(self, data):
        if self.cell is not None:
            self.cell.append(data)


def main():
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    parser = Table(sys.argv[2])
    with open(sys.argv[1], encoding='utf-8') as fh:
        parser.feed(fh.read())
    if not parser.found:
        sys.exit(2)
    for section, depth, cells in parser.rows:
        prefix = '#' if section == 'thead' else str(depth)
        print('\t'.join([prefix] + cells))


main()
