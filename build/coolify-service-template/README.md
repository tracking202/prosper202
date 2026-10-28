# Coolify one-click service template (submission kit)

Everything needed to get Prosper202 listed in Coolify's one-click service
catalog (<https://coolify.io/services>), prepared and ready to submit. The
catalog is generated from the [`coollabsio/coolify`](https://github.com/coollabsio/coolify)
repository — listing means getting a PR merged there.

## Contents

| File | Submits to (in coollabsio/coolify) |
|------|-------------------------------------|
| `prosper202.yaml` | `templates/compose/prosper202.yaml` |
| `svgs/prosper202.svg` | `svgs/prosper202.svg` |

The template mirrors the repo's `docker-compose.coolify.yaml`, except it pulls
the published image (`ghcr.io/tracking202/prosper202`) instead of building
from git — one-click services never clone the repository.

## Prerequisites (in order)

1. **A published container image.** The release workflow
   (`.github/workflows/release.yml`, `docker-image` job) publishes
   `ghcr.io/tracking202/prosper202:<version>` (amd64 + arm64) on every
   `vX.Y.Z` tag, once the `release` job has validated it, using the built-in
   `GITHUB_TOKEN` — no registry account needed. `latest` moves only on a
   stable tag; a suffixed prerelease (`v2.0.0-beta`) publishes its version tag
   alone, since the template pulls `:latest`. **One manual step after the
   first tagged release:** the GHCR package is created private; open the
   package's settings on GitHub and set it to **Public** so anonymous
   `docker pull` works.
2. **Coolify's star requirement.** The contribution guide
   (<https://coolify.io/docs/get-started/contribute/service>) asks that the
   service's repository have **at least 1,000 GitHub stars**. Until the repo
   crosses that bar, either open a GitHub Discussion with the Coolify
   maintainers making the case for an exception, or hold the submission.
   Everything else here stays valid either way.
3. **Coolify must be taking contributions.** As of October 2026 the guide
   says external contributions are paused, with no date to resume. Check the
   guide before opening anything.

## Submission checklist

1. Fork `coollabsio/coolify` on GitHub.
2. Copy `prosper202.yaml` to `templates/compose/prosper202.yaml` and
   `svgs/prosper202.svg` to `svgs/prosper202.svg`. (Replace the placeholder
   SVG with official vector brand art if available — the metadata header's
   `logo:` line already points at the right path.)
3. Do not edit `templates/service-templates.json`: Coolify generates it from
   the compose templates.
4. **Test first**: on any Coolify instance, create a resource via
   **Docker Compose Empty**, paste `prosper202.yaml` (without the metadata
   comments), set a domain on the `prosper202` service, deploy, and complete
   the setup wizard.
5. Open the docs PR in Coolify's documentation repository (the guide links
   it): the logo under `public/images/services/` and a page at
   `content/docs/services/prosper202.mdx`. The template PR cannot merge
   without it, so open it first.
6. Open the template PR in `coollabsio/coolify`.

## Keeping the template in sync

`prosper202.yaml` is derived from `../../docker-compose.coolify.yaml`. If that
stack changes (new env vars, volumes, services), update this template in the
same commit — and after the catalog listing exists, upstream the same change
to `coollabsio/coolify`.
