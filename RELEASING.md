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
`go-cli/dist`, two skills under `.claude/` (onboarding, and the eval case
format `p202 eval run` names), and two fixtures under `tests/` (the SDK
contract vectors and the eval cases the bundled SDKs and CLI point at) ship.
`exclude_nested` removes dev files inside shipped directories, such as
`202-config/PHPStan` and the messaging mock server.

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

## Upgrades are one-way: a database backup is required

Upgrading an install changes its database in place, and there is no
downgrade. **Restoring a backup taken before the upgrade is the only way
back.** Putting the old files back is not a rollback: from 1.9.76 the upgrade
makes `202_conversion_logs.dedupe_key` `NOT NULL` with no default (and drops
`uniq_click_transaction`), so 1.9.55 code redeployed on an upgraded database
inserts conversions that strict mode refuses or that collide on
`uniq_click_dedupe` — it can no longer record conversions at all.

Every release's notes, and anyone walking an operator through an upgrade,
must say so before the upgrade is started. The upgrade page
(`202-config/upgrade.php`) says it above its button on every upgrade
(`#upgrade-backup-warning`). A backup means a full `mysqldump` (or the
host's snapshot) of the Prosper202 database, taken after the site stops
taking traffic and before the upgrade page is opened; see
`documentation/features/measurement-rewrite-plan.md` §7.5a.

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
   `tests/` holds only `fixtures/agent-eval` and `fixtures/app-sdk-contract`
   (the two the bundled CLI and SDKs point at), confirms you downloaded the
   release asset rather than GitHub's auto-generated "Source code" archive,
   which has no `vendor/` and the whole test suite.

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
- **A branch deployment's schema is behind the code** — databases above 1.9.55
  from before the app-core reshape are reinstalled, not repaired: no release
  ever carried their intermediate schema, so the upgrade path has none to
  convert (see `documentation/features/measurement-rewrite-plan.md`,
  Constraints).

- **An upgrade on a large install answers 504 (or another timeout).** The page
  renders only when the upgrade finishes. At 1M conversions that takes 57 s on
  MariaDB 10.11 and 91 s on MySQL 8.0 (plan §7.5a), which is more than a 60 s
  proxy read timeout. The upgrade keeps running on the server:
  `UPGRADE::upgrade_databases()` ignores the client going away and has no time
  limit. Tell the operator to wait a few minutes and reload `upgrade.php`;
  there is nothing to redo. If they reload while it is still running, the page
  says "An upgrade is already running" and changes nothing, because a named
  lock allows one upgrade per database. Once it has finished, the page sends
  them to the login page. A process killed mid-upgrade leaves the stored
  version where the last completed step put it, and the next run resumes
  from there.
- **`fail_on_unmatched_files`** trips when the build produced no zip — read the
  "Build release artifact" step log; the publish step is working as intended by
  refusing to create an empty release.
