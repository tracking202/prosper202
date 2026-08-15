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
   `ghcr.io/tracking202/prosper202:{version,latest}` (amd64 + arm64) on every
   `vX.Y.Z` tag using the built-in `GITHUB_TOKEN` — no registry account
   needed. **One manual step after the first tagged release:** the GHCR
   package is created private; open the package's settings on GitHub and set
   it to **Public** so anonymous `docker pull` works.
2. **Coolify's star requirement.** The contribution guide
   (<https://coolify.io/docs/get-started/contribute/service>) asks that the
   service's repository have **at least 1,000 GitHub stars**. Until the repo
   crosses that bar, either open a GitHub Discussion with the Coolify
   maintainers making the case for an exception, or hold the submission.
   Everything else here stays valid either way.

## Submission checklist

1. Fork `coollabsio/coolify` on GitHub.
2. Copy `prosper202.yaml` to `templates/compose/prosper202.yaml` and
   `svgs/prosper202.svg` to `svgs/prosper202.svg`. (Replace the placeholder
   SVG with official vector brand art if available — the metadata header's
   `logo:` line already points at the right path.)
3. Regenerate/reference the parsed templates per their contribution guide
   (`templates/service-templates.json`).
4. **Test first**: on any Coolify instance, create a resource via
   **Docker Compose Empty**, paste `prosper202.yaml` (without the metadata
   comments), set a domain on the `prosper202` service, deploy, and complete
   the setup wizard.
5. Open the PR. After it merges, add the docs page
   (`/docs/services/prosper202.md` in their docs) so the service appears on
   the website listing.

## Keeping the template in sync

`prosper202.yaml` is derived from `../../docker-compose.coolify.yaml`. If that
stack changes (new env vars, volumes, services), update this template in the
same commit — and after the catalog listing exists, upstream the same change
to `coollabsio/coolify`.
