# Changelog fragments

A pull request does not edit `changelogs.txt` or `202-config/changelogs.json`.
It adds a fragment here, and the release folds every fragment into those two
files (`RELEASING.md`, step 2). When every pull request appended to the same
lines of the two files, each merge made every other open pull request conflict.

## Writing one

One file per pull request, in the folder of the version it targets, named
anything ending in `.txt` (the branch name is a good choice):
`changelogs.d/1.9.79/fix-rotator-isp.txt`.

Each entry is the line `changelogs.txt` would hold, on one line. When the app
should show it too, follow it with `app:` and the short line for
`changelogs.json`:

```
-- Fixed: The long entry for developers: what was wrong, for whom, and what now happens.
app: Fixed: The short entry the app shows on the update page.
```

- Kinds: `New`, `Changed`, `Fixed`.
- A fragment may hold several entries. Blank lines and lines starting with `#`
  are ignored.
- An entry with no `app:` line goes into `changelogs.txt` only.
- To reword an entry that is already in `changelogs.txt`, edit it there; that
  is allowed. Only new entries come as fragments.

`python3 scripts/changelog.py check` validates every fragment, and the
`changelog` job in `pr-checks.yml` runs it with the guard that refuses new
entries written into the two files.
