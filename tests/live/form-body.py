#!/usr/bin/env python3
"""Serialize one form of a saved page the way a browser submits it.

    form-body.py PAGE.html MARKER [name=value ...]

MARKER is the name of a field that only the wanted form carries (for example
update_profile), or name=value when several forms carry the field and the
value tells them apart (delete_user_id=42). The form containing it is found and bounded, and its own
fields are serialized: hidden, text-like and password inputs with their value,
checked checkboxes and radios, the selected option of each select (or the
first, as a browser does), textareas. Disabled fields and buttons are left
out. Each name=value argument then replaces that field (or adds it), so a pass
submits what the page rendered and changes only what it means to change.

The point is error pattern #21: a live pass that scrapes the first token on
the page and posts a hand-built body proves nothing about the form a person
submits. The token this prints is the one inside the form that posts.

Prints the application/x-www-form-urlencoded body on stdout; exits 2 when no
form carries the marker.
"""
import sys
from html.parser import HTMLParser
from urllib.parse import urlencode


class Forms(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.forms = []          # list of (fields list, names set)
        self.current = None
        self.select = None       # [name, disabled, chosen value or None, first value or None]
        self.option = None
        self.textarea = None

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == 'form':
            self.current = ([], set())
            self.forms.append(self.current)
            return
        if self.current is None:
            return
        fields, names = self.current
        name = a.get('name')
        if name:
            names.add(name)
            if 'value' in a:
                names.add(name + '=' + a['value'])
        disabled = 'disabled' in a
        if tag == 'input' and name and not disabled:
            kind = (a.get('type') or 'text').lower()
            if kind in ('submit', 'button', 'image', 'reset', 'file'):
                return
            if kind in ('checkbox', 'radio'):
                if 'checked' in a:
                    fields.append((name, a.get('value', 'on')))
                return
            fields.append((name, a.get('value', '')))
        elif tag == 'select' and name:
            self.select = [name, disabled, None, None]
        elif tag == 'option' and self.select is not None:
            value = a.get('value')
            self.option = [value, 'selected' in a, '']
        elif tag == 'textarea' and name and not disabled:
            self.textarea = [name, '']

    def handle_data(self, data):
        if self.option is not None:
            self.option[2] += data
        if self.textarea is not None:
            self.textarea[1] += data

    def handle_endtag(self, tag):
        if tag == 'option' and self.option is not None and self.select is not None:
            value = self.option[0] if self.option[0] is not None else self.option[2].strip()
            if self.select[3] is None:
                self.select[3] = value
            if self.option[1] and self.select[2] is None:
                self.select[2] = value
            self.option = None
        elif tag == 'select' and self.select is not None:
            name, disabled, chosen, first = self.select
            if not disabled and self.current is not None:
                value = chosen if chosen is not None else first
                if value is not None:
                    self.current[0].append((name, value))
            self.select = None
        elif tag == 'textarea' and self.textarea is not None and self.current is not None:
            self.current[0].append((self.textarea[0], self.textarea[1]))
            self.textarea = None
        elif tag == 'form':
            self.current = None


def main():
    if len(sys.argv) < 3:
        print(__doc__, file=sys.stderr)
        return 2
    page, marker = sys.argv[1], sys.argv[2]
    overrides = []
    for arg in sys.argv[3:]:
        key, _, value = arg.partition('=')
        overrides.append((key, value))
    parser = Forms()
    with open(page, encoding='utf-8', errors='replace') as handle:
        parser.feed(handle.read())
    matching = [form for form in parser.forms if marker in form[1]]
    if not matching:
        print('no form on the page carries a field named ' + marker, file=sys.stderr)
        return 2
    fields = list(matching[0][0])
    for key, value in overrides:
        replaced = False
        for index, (name, _) in enumerate(fields):
            if name == key:
                fields[index] = (key, value)
                replaced = True
        if not replaced:
            fields.append((key, value))
    sys.stdout.write(urlencode(fields))
    return 0


if __name__ == '__main__':
    sys.exit(main())
