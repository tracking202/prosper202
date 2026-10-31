#!/usr/bin/env python3
"""Changelog fragments: one file per pull request, folded in at release.

Every pull request used to append its line to the same place in changelogs.txt
and 202-config/changelogs.json, so each merge into a version branch made every
other open pull request conflict there. A pull request now adds a fragment,
changelogs.d/<version>/<any-name>.txt, and the release folds the fragments
into the two files (RELEASING.md, step 2).

A fragment holds one or more entries. An entry is one line as changelogs.txt
writes it, optionally followed by the line the app shows (changelogs.json):

    -- Fixed: The long entry for developers, on one line.
    app: Fixed: The short entry the app shows.

Kinds are New, Changed and Fixed. Blank lines and lines starting with # are
ignored. An entry with no app: line is in changelogs.txt only.

Usage (from the repository root):
  scripts/changelog.py check              every fragment parses; no entry twice
  scripts/changelog.py assemble <version> fold that version's fragments in, delete them
  scripts/changelog.py guard <base-ref>   refuse a pull request that adds entries to
                                          the two files directly (CI, pr-checks.yml)
Exit 1 on a finding, 2 on bad usage.
"""
import json
import os
import re
import subprocess
import sys
import time

TXT = 'changelogs.txt'
JSON = '202-config/changelogs.json'
FRAGMENTS = 'changelogs.d'
KINDS = ('New', 'Changed', 'Fixed')
VERSION = re.compile(r'^\d+\.\d+\.\d+$')
ENTRY = re.compile(r'^-- (%s): \S' % '|'.join(KINDS))
APP = re.compile(r'^app: ((%s): \S.*)$' % '|'.join(KINDS))
HEADING = re.compile(r'^Version (\S+)\s*$')


class Problem(Exception):
    pass


def heading(line):
    """The version a "Version x.y.z" line names, or None."""
    m = HEADING.match(line)
    return m.group(1) if m else None


def parse_fragment(path, text):
    """[(txt line, app text or None)] in file order."""
    entries = []
    for number, line in enumerate(text.split('\n'), 1):
        line = line.rstrip('\r')
        if line.strip() == '' or line.startswith('#'):
            continue
        if ENTRY.match(line):
            entries.append([line.rstrip(), None])
            continue
        app = APP.match(line)
        if app and entries and entries[-1][1] is None:
            entries[-1][1] = app.group(1).rstrip()
            continue
        if app:
            raise Problem('%s:%d: an app: line must follow its "-- " entry, one each' % (path, number))
        raise Problem('%s:%d: not an entry; start it with "-- New: ", "-- Changed: " or "-- Fixed: ", '
                      'or "app: " for the line the app shows' % (path, number))
    if not entries:
        raise Problem('%s: no entries' % path)
    return [tuple(e) for e in entries]


def txt_blocks(text):
    """{version: [entry lines]} from changelogs.txt."""
    blocks, current = {}, None
    for line in text.split('\n'):
        version = heading(line)
        if version:
            current = blocks.setdefault(version, [])
        elif current is not None and line.startswith('-- '):
            current.append(line.rstrip())
    return blocks


def json_logs(text):
    return {v: list(r.get('logs', [])) for v, r in json.loads(text).items()}


def fragment_paths(version=None):
    if not os.path.isdir(FRAGMENTS):
        return []
    found = []
    for name in sorted(os.listdir(FRAGMENTS)):
        folder = os.path.join(FRAGMENTS, name)
        if not os.path.isdir(folder) or (version and name != version):
            continue
        for item in sorted(os.listdir(folder)):
            found.append(os.path.join(folder, item))
    return found


def read(path):
    with open(path, encoding='utf-8') as f:
        return f.read()


def check():
    blocks, logs = txt_blocks(read(TXT)), json_logs(read(JSON))
    seen_txt, seen_app, problems = {}, {}, []
    for path in fragment_paths():
        version = os.path.basename(os.path.dirname(path))
        try:
            if not VERSION.match(version):
                raise Problem('%s: the folder must be a version (1.9.79)' % path)
            if not path.endswith('.txt') or os.path.basename(path).startswith('.'):
                raise Problem('%s: a fragment is a .txt file' % path)
            for line, app in parse_fragment(path, read(path)):
                if line in blocks.get(version, []):
                    raise Problem('%s: already in %s under Version %s: %s' % (path, TXT, version, line[:80]))
                if line in seen_txt.setdefault(version, {}):
                    raise Problem('%s: also in %s: %s' % (path, seen_txt[version][line], line[:80]))
                seen_txt[version][line] = path
                if app is None:
                    continue
                if app in logs.get(version, []):
                    raise Problem('%s: already in %s under %s: %s' % (path, JSON, version, app[:80]))
                if app in seen_app.setdefault(version, {}):
                    raise Problem('%s: also in %s: %s' % (path, seen_app[version][app], app[:80]))
                seen_app[version][app] = path
        except Problem as e:
            problems.append(str(e))
    for p in problems:
        print('::error::' + p if os.environ.get('GITHUB_ACTIONS') else p, file=sys.stderr)
    return 1 if problems else 0


def first_added(path):
    """When the fragment's first commit landed (the merge order), or now if uncommitted."""
    out = subprocess.run(['git', 'log', '--diff-filter=A', '--format=%ct', '--', path],
                         capture_output=True, text=True).stdout.split()
    return int(out[-1]) if out else int(time.time())


def assemble(version):
    if not VERSION.match(version):
        raise Problem('not a version: %s' % version)
    if check() != 0:
        raise Problem('fix the fragments first')
    paths = sorted(fragment_paths(version), key=lambda p: (first_added(p), p))
    if not paths:
        print('no fragments for %s' % version)
        return 0
    entries = [e for p in paths for e in parse_fragment(p, read(p))]

    lines = read(TXT).split('\n')
    start = next((i for i, l in enumerate(lines) if heading(l) == version), None)
    new = [line for line, _ in entries]
    if start is None:
        # A new block goes above the newest one, after the file's title.
        top = next((i for i, l in enumerate(lines) if heading(l)), len(lines))
        lines[top:top] = ['Version ' + version] + new + ['']
    else:
        end = next((i for i in range(start + 1, len(lines)) if heading(lines[i])), len(lines))
        last = max([i for i in range(start + 1, end) if lines[i].startswith('-- ')], default=start)
        lines[last + 1:last + 1] = new
    with open(TXT, 'w', encoding='utf-8') as f:
        f.write('\n'.join(lines))

    data = json.loads(read(JSON))
    apps = [app for _, app in entries if app is not None]
    if version in data:
        data[version]['logs'].extend(apps)
    elif apps:
        data = {version: {'version': version, 'logs': apps}, **data}
    with open(JSON, 'w', encoding='utf-8') as f:
        f.write(json.dumps(data, indent=2, ensure_ascii=False) + '\n')

    for p in paths:
        os.remove(p)
    folder = os.path.join(FRAGMENTS, version)
    if os.path.isdir(folder) and not os.listdir(folder):
        os.rmdir(folder)
    print('folded %d fragment(s): %d entries into %s, %d into %s' % (len(paths), len(new), TXT, len(apps), JSON))
    return 0


def git_show(ref, path):
    r = subprocess.run(['git', 'show', '%s:%s' % (ref, path)], capture_output=True, text=True)
    return r.stdout if r.returncode == 0 else None


def guard(base):
    """Refuse new entries written straight into the two files; a deleted fragment's entries must have been folded in."""
    mb = subprocess.run(['git', 'merge-base', base, 'HEAD'], capture_output=True, text=True)
    if mb.returncode != 0:
        raise Problem('no merge base with %s (fetch it with full history)' % base)
    mb = mb.stdout.strip()
    # Every fragment deleted by any commit in mb..HEAD, read from just before its deletion: a version branch
    # adds and folds its fragments within its own history, so the net diff against master shows none.
    log = subprocess.run(['git', 'log', '--no-renames', '--diff-filter=D', '--name-only', '--format=%x00%H',
                          '%s..HEAD' % mb, '--', FRAGMENTS], capture_output=True, text=True, check=True).stdout
    removed = []
    for chunk in log.split('\0')[1:]:
        sha, *paths = chunk.split()
        removed += [(p, git_show(sha + '^', p)) for p in paths if (p, git_show(sha + '^', p)) not in removed]
    now_txt, now_json = txt_blocks(read(TXT)), json_logs(read(JSON))
    # A deleted fragment must have been folded in (assemble); its entries may grow the files.
    folded_txt, folded_app, problems = {}, {}, []
    for path, text in removed:
        version = os.path.basename(os.path.dirname(path))
        if not path.endswith('.txt') or text is None:
            continue
        # Only a fragment the base had must be folded in; one added and dropped in this range never counted.
        had = git_show(mb, path) is not None and not os.path.exists(path)
        for line, app in parse_fragment(path, text):
            if line not in now_txt.get(version, []):
                if had:
                    problems.append('%s was deleted but its entry is not in %s under Version %s: %s'
                                    % (path, TXT, version, line[:80]))
                continue
            folded_txt[version] = folded_txt.get(version, 0) + 1
            if app is not None:
                if app not in now_json.get(version, []):
                    if had:
                        problems.append('%s was deleted but its app line is not in %s under %s: %s'
                                        % (path, JSON, version, app[:80]))
                    continue
                folded_app[version] = folded_app.get(version, 0) + 1
    grew = []
    before_txt, before_json = git_show(mb, TXT), git_show(mb, JSON)
    if before_txt is not None:
        old = txt_blocks(before_txt)
        grew += ['%s Version %s: %d -> %d' % (TXT, v, len(old.get(v, [])), len(n))
                 for v, n in now_txt.items() if len(n) > len(old.get(v, [])) + folded_txt.get(v, 0)]
    if before_json is not None:
        old = json_logs(before_json)
        grew += ['%s %s: %d -> %d' % (JSON, v, len(old.get(v, [])), len(n))
                 for v, n in now_json.items() if len(n) > len(old.get(v, [])) + folded_app.get(v, 0)]
    for p in problems:
        print('::error::' + p if os.environ.get('GITHUB_ACTIONS') else p, file=sys.stderr)
    if problems:
        return 1
    if grew:
        msg = ('this pull request adds changelog entries to the files directly (%s). Put them in a fragment, '
               'changelogs.d/<version>/<name>.txt, instead: see scripts/changelog.py' % '; '.join(grew))
        print('::error::' + msg if os.environ.get('GITHUB_ACTIONS') else msg, file=sys.stderr)
        return 1
    return 0


def main(argv):
    try:
        if argv[1:2] == ['check'] and len(argv) == 2:
            return check()
        if argv[1:2] == ['assemble'] and len(argv) == 3:
            return assemble(argv[2])
        if argv[1:2] == ['guard'] and len(argv) == 3:
            return guard(argv[2])
    except Problem as e:
        print(e, file=sys.stderr)
        return 1
    print('usage: scripts/changelog.py check | assemble <version> | guard <base-ref>', file=sys.stderr)
    return 2


if __name__ == '__main__':
    sys.exit(main(sys.argv))
