# Releasing Prosper202

This guide is for **maintainers** cutting a new Prosper202 release. End users
never run any of this — they download the finished zip from the
[Releases page](https://github.com/tracking202/prosper202/releases) (see the
"Download & Upload" track in the [README](README.md)).

> Audience note: this is the engineering release process. The commercial
> upgrade *policy* (pricing, free-after-six-months) lives in
> [`documentation/setting-up-prosper202-pro/999-prosper202-release-cycle.md`](documentation/setting-up-prosper202-pro/999-prosper202-release-cycle.md).

## What a release is

A release is a single self-contained zip, `prosper202-<version>.zip`, that
bundles everything a server needs to run with **no terminal, no Composer, and
no Go toolchain**:

- The application source from a clean `git archive` of the tag, minus
  everything only developers or CI need (see below).
- `vendor/` installed with `composer install --no-dev --optimize-autoloader`
  **from the committed `composer.lock`**, so the zip carries exactly the
  dependency set CI tested.
- Pre-built Go CLI binaries for all six targets (linux/darwin/windows ×
  amd64/arm64) under `go-cli/dist/`.

That is the "download the release, not the git clone" promise: shared-hosting
users upload the zip and the browser wizard does the rest.

## What goes in the zip: `build/release-manifest.php`

The zip is extracted straight into a web root, so everything in it is deployed
and usually reachable over HTTP. [`build/release-manifest.php`](build/release-manifest.php)
decides what ships, and it is **fail-closed**. Every top-level path must be
listed under either `ship` or `exclude`, and an unlisted one stops the build.
`keep_only` reduces a shipped directory to named children: only
`go-cli/dist` and the onboarding skill under `.claude/` ship. `exclude_nested`
removes dev files inside shipped directories, such as `202-config/PHPStan`
and the messaging mock server.

`build/scripts/release-tree.php` enforces it at three points:

- **Every pull request** runs `release-tree.php check-manifest` against the
  tracked files (the `release-manifest` job in `pr-checks.yml`), together with
  `composer validate`, which fails when `composer.lock` has drifted from
  `composer.json`.
- **`prune`** removes the excluded paths from the staged tree. It refuses,
  before deleting anything, if a path is unclassified.
- **`verify`** checks the pruned tree before it is zipped:
  - nothing excluded remains;
  - `vendor/` is exactly the locked runtime set, with no dev packages;
  - every namespaced class the shipped PHP imports or names qualified
    (`Commands\Foo`, `\Full\Name`) is declared in the shipped code or found by
    the shipped autoloader (case-exactly, as on Linux), so a missing vendor
    package or a dev-only class fails the build. Unqualified names and class
    names in strings are not seen;
  - every tracked file that ships is in the tree at exactly its tracked path
    (see "Building locally" for why case matters);
  - all six Go binaries are present;
  - no file is group- or world-writable (suPHP hosts answer 500 for those).

Adding a file at the repository root? Put it under `ship` if a server needs it
at runtime, under `exclude` if only contributors do. Adding a Composer package?
Run `composer require` (or `composer update <pkg>`) and commit `composer.lock`;
the platform is pinned to PHP 8.3 in `composer.json`, so the lock resolves for
the oldest PHP the app supports, whatever PHP you run locally.

## The single source of truth: `202-config/version.php`

The version lives in **one** place — the `$version_string` in
[`202-config/version.php`](202-config/version.php). Everything downstream reads
from it:

- `build/scripts/package-release.sh` reads it to name the zip.
- It passes that version into the Go build (`make ... VERSION=<version>`), so
  the CLI's `--version` matches the zip.
- It defines `PROSPER202_VERSION` used throughout the app and the upgrade check.

The format is validated (`MAJOR.MINOR.PATCH` with an optional `-suffix`); an
invalid string throws on load. **Always bump this file first** — never tag a
release without bumping it, or the zip name and the in-app version will disagree.

## Standard release flow (automated)

This is the normal path. CI builds and publishes; you only tag.

1. **Bump the version.** Edit `$version_string` in `202-config/version.php`,
   commit it (e.g. `chore: release vX.Y.Z`), and push to the default branch via
   the usual PR process.

2. **Tag and push the tag.** The tag must be `v` + the exact version you set:

   ```bash
   git checkout main && git pull
   git tag v1.9.59          # must match version.php (currently 1.9.59)
   git push origin v1.9.59
   ```

3. **Let CI do the rest.** The [`Release` workflow](.github/workflows/release.yml)
   fires on `v*` tags. It:
   - runs on `ubuntu-24.04`, using the image's own PHP 8.3 and Composer
     (checked, with the extensions the build needs, before anything else) and
     Go 1.22 via `setup-go` (matching `composer.json` and `go-cli/go.mod`). No
     third-party action touches the release job's PHP, and every action is
     pinned to a commit SHA; Dependabot proposes the updates,
   - runs `build/scripts/package-release.sh` (no build logic is duplicated in
     YAML),
   - writes a job summary with the artifact name, size, and SHA256, and
   - publishes a GitHub Release for the tag with the zip attached and
     auto-generated release notes.

4. **Verify on the Releases page.** Confirm `prosper202-<version>.zip` is
   attached and the version in the filename matches the tag. The build has
   already run `release-tree.php verify` on its contents; a spot check that
   `vendor/autoload.php` and `go-cli/dist/linux-amd64/p202` exist, and that
   `tests/` does not, confirms you downloaded the release asset rather than
   GitHub's auto-generated "Source code" archive (which has no `vendor/`).

> No GitHub secrets are required — the workflow uses the auto-provided
> `GITHUB_TOKEN` (with `contents: write`) to create the release and upload the
> asset.

### Re-running a release

You can also trigger the workflow manually from the Actions tab
(`workflow_dispatch`) — useful for testing the build without cutting a tag,
though a manual run won't create a Release unless it was started from a tag ref.
To redo a botched release, delete the GitHub Release and the tag, fix the issue,
and re-tag.

## Building locally (fallback / testing)

To produce the exact same artifact on your own machine — for testing, or if CI
is unavailable:

```bash
build/scripts/package-release.sh
# -> dist/prosper202-<version>.zip   (+ printed SHA256)
```

Prerequisites (the script preflights for these and fails loudly if any is
missing): `php`, `composer`, `go`, `git`, `zip`. The script exports the current
`HEAD`, so commit your changes first — uncommitted edits won't be included.

**The build needs a case-sensitive filesystem, so it refuses to run on a Mac's
default disk.** Git tracks `tracking202/Redirect/` (`RedirectHelper.php`) beside
`tracking202/redirect/` (the click endpoints). A case-insensitive filesystem
merges the two, and the zip then carries `dl.php`, `go.php` and `rtr.php` under
`Redirect/`, which a Linux host serves as 404s: every tracking link breaks. The
script checks with a probe file before starting, and `release-tree.php verify`
checks every shipped file's exact path afterwards. On macOS, build in a Linux
container:

```bash
mkdir -p dist   # or the daemon creates it root-owned
docker run --rm -v "$PWD":/src:ro -v "$PWD/dist":/out ubuntu:24.04 bash -c '
  apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
    php8.3-cli php8.3-curl php8.3-xml php8.3-mbstring composer git zip unzip golang-go make ca-certificates
  git config --global --add safe.directory /src   # the mount is owned by another uid
  git clone -q /src /work && cd /work && bash build/scripts/package-release.sh && cp dist/*.zip /out/'
```

## Troubleshooting

- **`required tool(s) not installed: …`** — install the named tool. Locally you
  need the full set above; in CI this means a `setup-*` step is missing or
  failed.
- **`composer.lock is not committed at HEAD`** — the build never resolves
  dependencies itself. Run `composer update` and commit `composer.lock`.
- **`composer validate` reports "The lock file is not up to date"** —
  `composer.json` changed without the lock. Run `composer update <package>` (or
  `composer update --lock` for a change that adds no package) and commit the lock.
- **`composer install did not produce vendor/autoload.php`** — a dependency or
  platform constraint failed. Run the `composer install --no-dev` line by hand
  to see the real error.
- **`... is on a case-insensitive filesystem`** or **`release-tree verify:
  tracked file '...' is not in the release tree at that exact path`** — you
  are building on macOS's default disk. Use the Linux container above, or point
  `TMPDIR` at a case-sensitive volume.
- **`release-tree prune: '<path>' is not classified`** — a new top-level path
  is in the tree. Add it to `ship` or `exclude` in `build/release-manifest.php`.
  The PR check normally catches this first. If it appears only here, the path
  was created by the build rather than tracked (the ax `go` wrapper, for
  instance, writes `.ax-session/` into the directory it runs in).
- **`release-tree verify: '<Name>' ... does not resolve through the shipped
  vendor/autoload.php`** — shipped code uses a class that a `--no-dev` install
  does not provide. Move the package from `require-dev` to `require`, or stop
  the runtime code depending on it. The `known_unresolved` list in the
  manifest is for dead references only, each with the reason. It should
  shrink, never grow.
- **Go build fails** — confirm `go version` is ≥ 1.22 and that
  `make -C go-cli all` succeeds on its own. Cross-compiles use
  `CGO_ENABLED=0`, so no C toolchain is needed.
- **Zip name doesn't match the tag** — you tagged without bumping
  `202-config/version.php`. Delete the tag, bump the file, re-tag.
- **A branch deployment says "Already Upgraded" but its schema is behind** —
  development only. `upgrade_needed()` is `stored != code`, so an install that
  already recorded the version you are developing never re-runs its step, even
  if the tables were reshaped under that number since. Symptom: an
  unknown-column error on a table the release added. To repair, on that
  install: **back up the database**, set the stored version back to the release
  before the step that creates the tables (`UPDATE 202_version SET
  version='1.9.75';` for the 1.9.76 attribution tables), then run
  `202-config/upgrade.php`.

  Two things to know first. `202-config/upgrade.php` needs no login — that is
  how a fresh upgrade runs before there is a session — so while the version is
  wound back, anyone who can reach the host can load the page and run the
  upgrade themselves. They cannot do it from another site: the POST requires
  the session token the page embeds, the same check `install.php` makes, and
  `tests/live/upgrade-csrf.sh` proves it against a running instance. Still, do
  it behind a firewall or in a maintenance window. And re-running the step is
  idempotent but not inert: `SchemaReconciler` adds the columns and indexes the
  live table is missing and issues `MODIFY COLUMN` where a column is `NOT NULL`
  and the definition is not, the backfill `UPDATE`s run every time (on a
  matching table they change no rows), and the step stops the whole upgrade
  with `Upgrade paused` while pre-release `202_skan_*` tables still hold rows,
  because creating the `202_attribution_*` tables alongside them would strand
  those postbacks.

- **`fail_on_unmatched_files`** trips when the build produced no zip — read the
  "Build release artifact" step log; the publish step is working as intended by
  refusing to create an empty release.
