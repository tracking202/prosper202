# Recovering a tier on a partial `vendor/`

Read this when `verify.sh --probe` reports a partial vendor and you need a
tier it marked unavailable. Everything here is condensed from the
"Development environment notes" section of `CLAUDE.md`; that section remains
the source of truth if the two ever disagree.

## Contents

- [Why the install fails](#why-the-install-fails)
- [PHPUnit](#phpunit)
- [PHPStan](#phpstan)
- [PHPStan reports a baselined error on macOS](#phpstan-reports-a-baselined-error-on-macos)
- [Local PHP is newer than the project target](#local-php-is-newer-than-the-project-target)
- [The PHP CLI (`bin/p202`)](#the-php-cli-binp202)
- [The click path](#the-click-path)
- [A live instance](#a-live-instance)
- [Go](#go)
- [Committing under `docs/`](#committing-under-docs)

## Why the install fails

Proxied sandboxes commonly serve anonymous git reads of public GitHub repos
but not Composer's authenticated dist downloads through codeload and the API.
Retrying, `--prefer-source`, and a global `preferred-install` override do not
help, because the phar downloads themselves are blocked. The result is a
partially populated `vendor/` with no `vendor/bin/` and empty package
directories.

Expect roughly 76 environmental test errors in this state. Suites needing
Symfony Console (`tests/Cli/*`), Slim (`tests/Attribution/Api/*`), a live DB
singleton, or memcache error out with class-not-found messages. They are not
regressions.

These suites pass without those dependencies and are the usable baseline:

```
tests/Api tests/User tests/Crud tests/Rotator tests/Conversion
tests/Report tests/Upgrade tests/Click tests/Install
```

## PHPUnit

Regenerate the autoloader, then run the standalone phar. The PSR-4 maps in
`composer.json` cover `Api\V3\`, `Prosper202\`, and `Tests\`, and most runtime
dependencies usually did land.

```bash
composer dump-autoload --dev
curl -fsSLO https://phar.phpunit.de/phpunit-9.phar
php phpunit-9.phar --bootstrap vendor/autoload.php tests/Api
php phpunit-9.phar --bootstrap vendor/autoload.php tests/User
```

One path per invocation. PHPUnit 9 takes a single path argument and silently
ignores the rest, so `phpunit tests/Api tests/User` runs `tests/Api` alone
and reports OK. A field report from a sandbox caught this when a two-path
run executed 4 tests where 9 were expected. Use `--testsuite` for several
directories at once.

`phar.phpunit.de` is reachable in sandboxes where codeload is not. The phar
supplies PHPUnit's own classes; the project autoloader supplies the rest.

## PHPStan

The official phar works with the committed config, and both the ladder's
`phpstan` tier and `scripts/check-code-patterns.sh` find it at the repo root
when `vendor/bin/phpstan` is absent:

```bash
curl -fsSLO https://github.com/phpstan/phpstan/releases/download/<version>/phpstan.phar
php phpstan.phar analyse -c phpstan.neon.dist --no-progress
```

Expect about six `class.notFound` errors for `cli/` on a partial vendor, all
of the shape `extends unknown class Symfony\...`. The `phpstan` tier
recognises that shape when `vendor:` is partial: if those are the only
errors it reports SKIP naming the count, and if there are others it reports
FAIL as `(N environmental, M other)` so the real findings are not buried.
PHPStan discovers symbols through Composer package metadata, so a package
cloned into `vendor/` by hand stays invisible to it even after the autoloader
is patched. To confirm a clean run, write a scratch config that `includes:`
the dist file and adds `scanDirectories: [vendor/<pkg>]`. Do not commit that
scratch config.

## PHPStan reports a baselined error on macOS

Symptom: `verify.sh --tier phpstan` reports one error locally that CI does not
report, and the error is already present in `phpstan-baseline.neon`.

```
api/V3/Controllers/CapabilitiesController.php:219
  Direct $stmt->bind_param() bypasses Connection::bind() ref safety.
```

The baseline entry for it reads `path: api/v3/Controllers/...` with a lowercase
`v3`, and baseline paths are matched case-sensitively.

Git tracks the directory as `api/v3`. Some macOS working copies have it on disk
as `api/V3`, and because the filesystem is case-insensitive git never reports
the difference. PHPStan walks the real directory name, emits the uppercase
path, and the baseline entry no longer matches. On a Linux CI checkout the
directory is lowercase, so CI is legitimately green.

Confirm before concluding anything else:

```bash
find api -maxdepth 1 -type d      # on-disk case
git ls-files | grep -oE '^api/[^/]+/' | sort -u   # the case git tracks
```

If those two disagree, the error is an artefact of the working copy, not a
regression. Align the working copy rather than editing the baseline:

```bash
git mv api/V3 api/v3-tmp && git mv api/v3-tmp api/v3
```

The two-step rename is needed because a case-only rename is a no-op on a
case-insensitive filesystem. Do not "fix" this by adding a second baseline
entry for the uppercase path; that would hide the real finding on Linux.

## Local PHP is newer than the project target

`composer.json` requires `php >=8.3` and every CI job pins `php-version: 8.3`.
A newer local PHP promotes deprecation notices, and under the strict
`phpunit.xml` those become test errors CI never sees (16 of them on 8.5).

The `unit` tier does not run that file. It runs CI's exact invocation,
`phpunit.ci.xml` with `--exclude-group integration`, which passes on PHP 8.5
here: 1194 tests, 8 skipped, 0 failures. So a newer interpreter is not, by
itself, a reason for the tier to fail.

If the tier does fail on a PHP that differs from CI's, the `FAIL` line carries
a note naming both versions. That note is context, not a verdict. Read each
failure:

- a deprecation notice (`... is deprecated since 8.5`, an implicit-nullable
  warning from a vendor package) is the interpreter, not the change;
- an assertion failure or an exception from application code is the change,
  whatever PHP it ran on, and must be treated as one.

Never write the whole tier off as environmental because the interpreter
differs. The first version of the tier did exactly that in code, and turned a
deliberate `$this->fail()` into a SKIP with exit 0. Do not recreate that at
the reporting layer. Check the interpreter so you know which failures to
suspect, then report the tier as `FAIL` with what you found:

```bash
php -v | head -1
grep php-version .github/workflows/php-unit.yml
```

The separate errors reading "requires DB singleton which is not available in
tests" and the `memcache` cache-key notices are the documented no-database
gaps and appear on any interpreter.

## The PHP CLI (`bin/p202`)

This is the only way to exercise a `cli/Commands/*` change end to end, so it
is usually worth the ten minutes.

Symfony Console itself lands, but three of its dependencies come down empty.
Clone each at the version pinned in `composer.lock` and copy it into place:

- `symfony/service-contracts`
- `symfony/deprecation-contracts`
- `symfony/string`

Then add the `Symfony\Contracts\Service\` and `Symfony\Component\String\`
PSR-4 mappings to **both** `vendor/composer/autoload_psr4.php` and
`vendor/composer/autoload_static.php`. `dump-autoload` will not pick them up,
and the static map takes precedence.

`deprecation-contracts` ships `trigger_deprecation()` as a Composer *files*
autoload entry, and a partial install generates no `autoload_files.php`, so
prepend it manually:

```bash
php -d auto_prepend_file=vendor/symfony/deprecation-contracts/function.php \
    bin/p202 campaign:list
```

The CLI keeps its own configuration through `config:set-url` and
`config:set-key`. It does not read `P202_API_URL` or `P202_API_KEY`.

## The click path

Click endpoints return 500 on a partial vendor. `ua-parser/uap-php` is a
runtime dependency of `tracking202/redirect/*.php` via
`PLATFORMS::parseUserAgentInfo`, and the failed install leaves its directory
empty.

```bash
git clone --depth 1 --branch v3.10.0 https://github.com/ua-parser/uap-php /tmp/uap
cp -r /tmp/uap/. vendor/ua-parser/uap-php/
```

Add the `UAParser\` PSR-4 mapping to **both** `autoload_psr4.php` and
`autoload_static.php`. The package is missing from `installed.json`, so
`dump-autoload` will not pick it up. The tag ships `resources/regexes.php`, so
a full install in CI needs none of this.

## A live instance

Achievable end to end:

```bash
apt-get install -y mariadb-server
mariadbd --user=mysql &
# create a database and user, then:
tests/fixtures/agent-eval/ci/install-instance.sh   # headless installer, prints the REST API key
tests/fixtures/agent-eval/seed.sh                  # deterministic fixture
```

Reports stay empty until the dataengine cron runs. The seeder triggers
`202-cronjobs/dej.php` itself.

## Go

The repo root is not a Go module, so Go commands run from `go-cli/`:

```bash
cd go-cli
go vet ./...
go test ./...              # forecast acceptance suites take ~40s; -short skips them
golangci-lint run ./...    # .golangci.yml scopes linters to dropped errors, not style
HOME=$(mktemp -d) go test ./cmd/...
```

The empty-`HOME` run matters for anything touching a command that builds a
client. A flag check placed after `api.NewFromConfig()` passes locally only
because the sandbox has a URL configured. CI has none, the config error wins,
and the flag is never examined. Flag validation belongs before the client is
built.

## Committing under `docs/`

`docs/` matches a gitignore pattern even though `docs/cli-agent.md`,
`docs/cli.md`, and `docs/openapi.yaml` are tracked. `git add docs/<file>`
works but prints an ignored-paths warning and exits 1. Use `git add -f`, or
ignore the warning after confirming with `git status` that the files staged.
