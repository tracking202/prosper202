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

- All tracked source (clean `git archive` of the tag — no `.git`, no dev cruft).
- `vendor/` built with `composer install --no-dev --optimize-autoloader`.
- Pre-built Go CLI binaries for all six targets (linux/darwin/windows ×
  amd64/arm64) under `go-cli/dist/`.

That is the "download the release, not the git clone" promise: shared-hosting
users upload the zip and the browser wizard does the rest.

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
   - provisions PHP 8.3 + Composer and Go 1.22 (matching `composer.json` and
     `go-cli/go.mod`),
   - runs `build/scripts/package-release.sh` (no build logic is duplicated in
     YAML),
   - writes a job summary with the artifact name, size, and SHA256, and
   - publishes a GitHub Release for the tag with the zip attached and
     auto-generated release notes.

4. **Verify on the Releases page.** Confirm `prosper202-<version>.zip` is
   attached and the version in the filename matches the tag. Download it, unzip,
   and spot-check that `vendor/autoload.php` and `go-cli/dist/linux-amd64/p202`
   exist.

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

## Troubleshooting

- **`required tool(s) not installed: …`** — install the named tool. Locally you
  need the full set above; in CI this means a `setup-*` step is missing or
  failed.
- **`composer install did not produce vendor/autoload.php`** — a dependency or
  platform constraint failed. Run the `composer install --no-dev` line by hand
  to see the real error.
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

  Two things to know first. `202-config/upgrade.php` takes its POST without a
  login — that is how a fresh upgrade runs before there is a session, and
  `upgrade_needed()` is the only thing holding it shut — so winding the version
  back opens it to anyone who can reach the host until the upgrade finishes. Do
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
