# Security policy

## Reporting a vulnerability

Please report security vulnerabilities privately, not in a public issue,
pull request or discussion.

- **GitHub:** open a private report at
  [Security › Report a vulnerability](https://github.com/tracking202/prosper202/security/advisories/new).
  Only you and the repository's maintainers can see it, and the fix and the
  advisory can be worked on with you there.
- **Email:** `[SECURITY CONTACT: to be set by the owner before this file is
  merged]`

Include what you can of:

- the version (`202-config/version.php`, or `p202 --version` for the Go CLI)
  and how it is installed (release zip, Docker image, from source);
- the component and the request, page, command or file involved;
- steps to reproduce, or a proof of concept, and what an attacker gains;
- whether you have told anyone else, and any date you plan to publish.

Do not test against installs you do not own or have permission to test, and
do not access or keep other people's data beyond what shows the problem.

Questions about using Prosper202 go to support
([Help and Support](https://prosper.tracking202.com/documentation/tutorials-and-guides/help-and-support)),
not here; send vulnerabilities through the channels above rather than the
support chat.

## What happens next

We aim to acknowledge a report within a few working days and to tell you
whether we can reproduce it. A confirmed vulnerability is fixed in a release,
and we will agree a disclosure date with you. Please keep the details private
until the fixed release is out. We are glad to credit you in the advisory, by
the name you choose, or to leave you out.

These are aims, not guarantees: Prosper202 is maintained by a small team.

## Supported versions

Fixes are made on the next release, not backported: there is one line of
development, and each version is frozen once it is tagged.

| Version | Security fixes |
|---------|----------------|
| Latest release ([Releases](https://github.com/tracking202/prosper202/releases)) | Yes |
| Any earlier release | No: upgrade to the latest release |

Upgrading changes the database in place and there is no downgrade, so take a
database backup first (see the release notes).

## Scope

In scope is the code in this repository:

- the self-hosted application: the web app, the click and conversion
  endpoints, the REST API (v1, v2, v3), the installer and the upgrader;
- the PHP CLI (`bin/p202`) and the Go CLI (`go-cli/`), including its MCP
  server (`p202 mcp`);
- the iOS and Android SDKs (`sdk/`);
- the release zip and the Docker image built from this repository.

Services Tracking202 runs (my.tracking202.com, the hosted MCP connector,
the website) are not code in this repository. If you find a problem in one,
report it through the same channel and say which service.

Not in scope: the configuration of an install's own server, PHP, database
or web server.
