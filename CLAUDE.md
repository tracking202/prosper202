# CLAUDE.md

## Project
Prosper202 — PHP 8.3 self-hosted campaign tracking and marketing analytics platform (clicks, conversions, attribution, app measurement, customer LTV) with REST API v3 (mysqli, PSR-4) and Symfony Console CLI.

## Branches: every version has its own, and master takes only finished versions
- **All work for a version is pull-requested into the branch named for it**,
  never into master. The name is the version as `202-config/version.php`
  spells it (`1.9.77`, no `v`; the `v` belongs to the release tag).
- **When the version is done, the version branch is merged into master** in
  one PR; the release owner then tags master `v<version>` (RELEASING.md).
  Besides that, master takes only a Dependabot bump or a change to nothing
  but workflows and documentation (`.github/`, `docs/`, `documentation/`,
  `*.md`).
- **A new version starts from master** after the previous one is merged and
  tagged; its first commit is the `version.php` bump. A tagged version is
  frozen (#33): work that finds `version.php` naming a tagged version belongs
  to the next one.
- If the version branch is not on the remote yet (`git ls-remote --heads
  origin`), it is created from master before the first PR. A session limited
  to pushing one branch asks for it.
- CI runs on a PR into a version branch as on one into master. Keep version
  branch names free of `/` (`pr-checks.yml`'s `branches: ["*"]` would not
  match). Agent Evals and upgrade-equals-install run on pushes to master
  only, so read them on the PR before merging.
- `scripts/check-pr-branch.sh` (PR checks) holds the four rules above: the
  base is the branch `version.php` names, that version is not tagged, and
  master takes only what is listed.
- **Changelog entries go in a fragment**, `changelogs.d/<version>/<name>.txt`
  (`changelogs.d/README.md`), never into the two changelog files; the
  `changelog` check refuses direct additions. Rewording an existing entry is
  fine.

## Error patterns to avoid

Each entry gives the failure shape, the rule, and the checks that hold it.
A check is a floor: what it "does not see" is still yours to ask by hand.
Lists of exceptions in those checks only shrink. Code and tests cite these
entries by number, so numbers are never reused. The incidents behind each
entry are in this file's git history.

### 1. Unchecked return values after fallible calls
- Check every call that signals failure by its return value:
  `$stmt->execute()`, `prepare()`, `get_result()`, `store_result()`,
  `$db->query()`, `_mysqli_query()`, `json_encode()`, Go's `json.Marshal`.
  `if (!$stmt->execute()) { $stmt->close(); throw ...; }`. Inside a
  transaction an unchecked failure commits a partial operation.
- The tell is a false that is indistinguishable from a legitimate empty
  answer (a false `get_result()` reads as an empty set, a false
  `store_result()` as "not found").
- What the false becomes matters as much as testing it. A fallback that
  reads like an answer (`null` for both "no row" and "read failed", a
  default version, "no key was found") is the silent case: throw or refuse.
- These are folds, not tests: a bare `return`/`continue` in the failure
  branch; `if (_mysqli_query($sql)) { … }` with no `else`; a branch that
  only records an error and then reads the result anyway; a test on one
  branch vouching for a use on another.
- Ask who is on the other end: on the click path the visitor goes on and the
  failure is logged; a write that has landed is not answered as failed (#13).
- Functions both bootstraps need have one copy, in `functions-shared.php`.
  `_mysqli_query()` logs (`p202LogFailedQuery()`, values redacted from the
  statement and from MySQL's words) and answers false;
  `memcache_mysql_fetch_assoc()` and its list twin throw `QueryException`.
  Differences are asked of `p202OnTheClickPath()`.
- A status line is a return value: an endpoint another process calls answers
  failure in the HTTP status, not in prose over a 200
  (`DataEngineHourAnswersItsFailureTest`). So is a verdict: a result type
  needs a way to say "this did not run" (the review workflows' `complete`,
  NOT FINISHED). One exception, the owner's: an adversarial review stopped
  by a provider limit passes its check and says UNAVAILABLE in its comment,
  since a limit is no verdict on the code.
- Checks: `UncheckedGetResultTest` (`StatementHelpers::resultOf()`),
  `MysqliQueryResultIsNotDiscardedTest`, `MysqliQueryResultIsTestedTest`
  (every `_mysqli_query()` and `->query(` on `$db`, `$this->db`, `$db?->`).
  The v3 API logs every 500 with `p202UncaughtLogLine()`.

### 2. Dead code referencing nonexistent schema
Never reference DB columns, tables, or config keys without verifying they exist in the actual schema. When adding features that touch the DB, confirm the schema first.

### 3. Ordering dependencies — using values before they're initialized
Config constants, DB connections and other resources must be initialized
before use; trace the initialization order when depending on global state.
A sign-in is initialization too: whatever builds an object from the session
runs after everything that can still change who the session is (the
remember-me cookie is tried in connect.php before the user is read;
`SessionSignInPathsTest`).

### 4. Silent data loss on malformed input
Malformed input produces an explicit error naming the field; it is never
dropped, defaulted or coerced.
- No `json_decode(...) ?? []` or similar fallbacks.
- `empty()` is a fallback: `empty('0')` is true. Presence is
  `isset()`/`array_key_exists()` plus a comparison with the value that means
  "none". (`ForbidFalsyRequestParamTestRule`)
- `(bool) "false"` is true. Read flags with
  `Api\V3\Support\RequestFlag::param()`. (`ForbidBoolCastOfRequestParamRule`)
- `(int) "12abc"` is 12. Read numbers with `QueryInt::param()`/`required()`;
  a posted id on a page with `Prosper202\Http\PostedId::read()` (LTV pages:
  `p202_ltv_posted_*()`). (`ForbidNumericCastOfRequestParamRule`; the pages
  still casting are counted in `phpstan-baseline.neon`;
  `StrictIntegerBodyFieldsTest` for hand-read body fields)
- A handler that reads only the keys it knows drops the rest. Every body goes
  through `PayloadKeys::refuseUnknown()` or the base's `validatePayload()`
  first (`PayloadHandlersRefuseUnknownKeysTest`,
  `ControllerFieldsMatchSchemaTest`). Keys a handler consumes itself are
  removed before the base sees them. Read-only keys are accepted with the
  record's own value and refused with any other, so clients building a body
  from another record (`p202 import`, `sync`, `SyncEngine`) leave them out.
- Nested objects and lists go through `PayloadKeys::objectErrors()`,
  `listErrors()` or `valueListErrors()` inside `PayloadKeys::refuse()`
  (`LtvBody` for LTV shapes) (`NestedBodyValuesAreCheckedTest`). Free-form is
  a registered decision, and its values are still read strictly.
- A check that needs a lookup belongs where the lookup is, before the write
  (`ltv_requires_customer`). A path with nobody to answer (a pixel) keeps
  what it can and logs what it dropped.
- "Permissive for forward compatibility" is the same drop when an explicit
  escape hatch already exists (webhook event names vs `*`).
- A form that posts every field rewrites every field, and a select posts its
  first option when none is selected: every select offers the stored value,
  selected. Writer and reader of one value accept the same set
  (`AccountZone::isZone()`, `AccountZoneTest`).
- The rules key on names (`$params`, `$queryParams`, `$payload`, request
  superglobals). A value copied to another variable, trimmed first, or under
  another name (`$data`, `$entry`) is not seen.

### 5. Inconsistent security patterns across similar operations
If create has secure password input, update must too. If one delete command has confirmation, all must. When implementing a security measure, grep for every analogous code path and apply the same pattern. Spot-checking misses these — review exhaustively.
- Pre-login pages (`install.php`, `202-login.php`, `upgrade.php`) all check
  the session token on POST (`PreLoginPostRequiresTokenTest`,
  `tests/live/upgrade-csrf.sh`). Every served file reading
  `$_POST`/`$_REQUEST` calls a guard or is listed with why
  (`PostReadersCheckTokenTest`, per file). A jQuery prefilter guards only the
  transport it hooks, not `fetch()`.
- Every token comparison goes through `AUTH::csrf_token_matches()`, which
  refuses an empty or non-string token (`hash_equals('', '')` is true)
  (`SessionTokenComparedOnlyByAuthTest`). connect.php reseeds an unusable
  token (`tests/live/session-token-reseed.sh`).
- When a state means "this person may not act", list every way a person
  acts: sign-in, remember-me cookie, API keys on v1/v2/v3
  (`ApiKeyAuthPathScopeTest`, `DeactivatedUserKeyInstanceTest`), and a
  session already signed in. connect.php re-reads the user's standing on
  every request; one rule, `UserStanding` (`SessionSignInPathsTest`,
  `SessionEndsWithUserIntegrationTest`). When a check runs at sign-in, ask
  what carries its answer afterwards.
- A credential that can start a sign-in is deleted by the act that ends the
  sign-in, not at its next use: `AUTH::signOut()`, every write switching a
  user off, and every password change delete remember-me keys in their
  transaction (`RememberMeKeys`) and count the user's session generation up,
  which connect.php compares. The sign-in's password rehash is not a change
  and does neither (`RememberMeKeysRevokedTest` lists each writer as one or
  the other).
- Writing a stamped row means writing its stamp
  (`CustomerWritesStampUpdatedAtTest` for `202_customers`; other tables with
  `updated_at` are not held).

### 6. Empty response rendering for void operations
DELETE/204 responses return empty arrays. Rendering an empty array produces no output. Void operations (delete, remove, revoke) need explicit success messages, not render calls.

### 7. bind_param type string mismatches
When building `bind_param($types, ...)` with many parameters, count types against values one-by-one. Integer timestamps bound as 's' work only through MySQL coercion.

### 8. Assumed closure, reference, and capture semantics
A by-reference `use (&$x)` on an outer closure does not propagate into arrow
functions inside it: `fn() => $x` captures by value at definition. A
captured variable passed to a by-reference parameter writes to the copy.
Execute any claim about capture, reference or evaluation order (a ten-line
`php -r`) before relying on it or writing it in a comment.
`ForbidArrowFnByRefCaptureRule`, `ForbidByRefArgumentFromCaptureRule` (an
unresolvable callee is not seen, so call a function that reports through a
reference outside a closure). New rules go in `202-config/PHPStan/Rules/`
and are registered in `phpstan.neon.dist`; an unregistered rule never runs.

### 9. Tests that mock the seam under test
- When the new code is the wiring (a dispatcher, adapter, callback), a test
  that injects a fake for it proves only the bookkeeping. Exercise the real
  path at least once: an integration test or a live request.
- A fake connection accepts SQL the server refuses (`SHOW COLUMNS … LIKE ?`
  will not prepare on MariaDB 10.11). SQL that reaches a server only through
  a builder needs one run against a real server
  (`ListTimeFiltersIntegrationTest`).
- A stand-in answers as the thing it stands in for, under each bootstrap that
  runs it.

### 10. Calling a capability done without an end-to-end pass
Aggregate green suites are not coverage of the path you just wrote. Before
reporting a user-facing capability complete, run it end to end the way a
user will (live-instance recipe under Development environment notes), and
add a case under `tests/fixtures/agent-eval/cases/` when it is agent-facing.
Report which paths were exercised, not suite totals.

### 11. Fail-open defaults on a security value
- A value that cannot be parsed never resolves to the most permissive
  reading. Resolve it to something that satisfies nothing and name it in the
  error. Whenever a default stands in for a missing value, ask separately
  about a malformed one.
- A predicate must not answer when it does not know: `false` for "the probe
  failed" is the same `false` as "not there". Throw or return a tri-state.
- Caching multiplies a wrong answer. Before adding a cache ask what a wrong
  cached value costs. Cache on the object (`WeakMap`), not
  `spl_object_id()`. Cache writes made inside a transaction wait for its
  commit (`p202WithHeldCacheWrites()`). When a lookup's answer changes,
  change its key subject and pin the old key unserved. A namespace is a
  claim about whose values and when: `systemHash()` names the install
  (directory and database) with no shared fallback
  (`SystemHashNamesTheDatabaseTest`), and click-path keys carry the code
  version (`LookupRepositoryFactory::cacheNamespace()`,
  `CacheKeyNamespaceTest`). `Ipv6RowJoinIsGuardedTest` holds every join of
  `202_ips_v6`.
- "Cheap and clearly right" is a claim, not an exemption from review.
- When a flag's absence is the common case, every reader must agree on what
  absence means: `DataScope::userId()` (`PublisherSessionReadsTest`). A
  notice-silencing rewrite preserves whichever meaning was there.
- A switch needs an answer for a value it cannot read: `p202_consent` gives
  consent only as `1`. A second reader of a request value reads it as the
  first one's parser does, proven by a test (`IdentitySignalsTest`,
  `LandingConsentReaderTest`).
- Data whose owner cannot be shown is "not ours". State is keyed by the
  install's id (`InstallId`, `ServerStateStore::forInstall()`,
  `StateStoresNameTheInstallTest`), not by where the database lives; before
  keying on a location ask what can replace it there. A fix to a default
  reaches every setting that replaces it (`P202_SERVER_STATE_DIR`).

### 12. A guard is only as good as the layer that delivers the input
Validation is dead code if a layer above discards or rewrites the input (the
CLI dropping an empty field) or ends the request first (an unguarded connect
throwing before the outage fallback). Trace a bad value from the outermost
entry point and confirm it reaches the check
(`tests/live/outage-fallback.sh`).

### 13. Retry seams must know whether the write landed
An exception says nothing about whether the database changed. Handlers wrap
post-write steps and rethrow `WriteCommittedException`; the retry seams
(idempotency, staged apply) refuse the retry. "Safe to run again" needs a
fact, not an inference from an exception type. mysqli's
`begin_transaction()` inside a transaction implicitly commits the outer one:
`Connection::transaction()` refuses to nest, callers join with
`joinTransaction()`, and transactions begin only in Connection
(`TransactionsBeginOnlyInConnectionTest`).

### 14. Safety flags must survive process and reset boundaries
A flag that withholds an action (`--staged`, `?dry_run=1`) is re-checked at
every boundary that rebuilds state (the shell's flag reset, `p202 exec`'s
child process) and refused, never ignored, where it cannot be honoured: the
dispatcher answers 422 for `dry_run` on a route that does not preview
(`DryRunRoutesTest`).

### 15. A discriminator folded into the storage key can never be checked
If X is part of the lookup path, no lookup can tell you X changed. An
idempotency key's body fingerprint belongs inside the record, not in the
scope its filename derives from. When widening a scope, shard by the lookup
key and bound what a shard keeps. Equally, a key that locates a record is
compared with the request: refuse a difference naming the key and the field
(`RevenueReplay`); a fixed default is compared when absent, a moving one
(now, the account's currency) only when sent. A client that pre-reads to
skip duplicates (`p202 conversion import`) owes the same comparison.

### 16. On a public endpoint, identity is what the attacker cannot choose
Split every value a security decision keys on into what the peer proved and
what the request claimed.
- Rate limits key on `REMOTE_ADDR`; the `X-Forwarded-For` value is for
  display. A dedupe hash covers the full body. Aggregates over rows anyone
  can insert count only verified rows by default.
- Host and scheme: ask who will follow the URL. Back to the requester:
  `TrackingBaseUrl::forRequest()` / `domainForResponse()`. Sent to someone
  else or fetched by the server: the stored domain (`PasswordResetLink`,
  `p202TrackingBaseUrl()`). Operator-pasted links use
  `p202TrackingBaseForResponse()`, the API's rule (`TrackingBaseLookup`).
  (`RequestHostSourceTest`, `HandBuiltLinkSchemeTest`.) Two surfaces handing
  out one URL call one builder.
- A claim shown to a person is still a claim: the AI-connect consent page
  vouches for nothing the link says.
- A path in a world-writable directory is a claim: use it only when
  `lstat()` shows this process's user owns it alone (`TempDirPathsTest`).

### 17. A key derived from an identity must be injective
Ask of any composite key, bucket name, cache key or `CONCAT_WS`: can two
different inputs produce the same string? A sanitizer, truncation, case fold
or bare delimiter usually means yes. Length-prefix fields as
`PostbackReceiver::dedupeHash()` does. The collation folds case: text
columns in a UNIQUE or PRIMARY key declare `COLLATE utf8mb4_bin` or are
listed with why (`UniqueKeyCollationTest`), in the upgrade as well as the
table definition. A cut matches its column's width in its unit, characters
for VARCHAR and bytes for TEXT (`StoredCutsMatchTheirColumnsTest`).

### 18. A guard that runs after the framework already normalized its input
`Controller::create()` runs `validatePayload()`, which casts every `'i'`
field, before `beforeCreate()`, so a hook never sees the raw value. Assume
anything a framework hook hands you is already normalized; prove a check by
feeding a malformed value in at the decoded request body.

### 19. A class name is not a contract, and a container is not a component
- CSS has no undefined-symbol error (`ComponentClassIsConsumedTest`: every
  class in markup is named by a stylesheet rule or a script).
- A component's styling lives in its parts (`.p202-empty__title`): copy the
  markup from `202-account/ui-kit.php`. Read a class's rule before using it
  for anything else (`.p202-help` is the help icon, not helper text).
- Inline content in a flex container loses the spaces around its tags
  (`checks.flexContainersKeepTheirSpaces`).
- An installer-written `202-config.php` persists across upgrades: check an
  optional config method before calling it, and read the replica only
  through `ConnectionFactory::readConnectionOf()`
  (`DatabaseConfigCompatibilityTest`).
- A message rendered where the page does not land is not said (`p202-ui.js`
  moves flashes into the panel the fragment names). Check placement at a
  viewport that scrolls.

### 20. A checker only sees the syntax it follows
A scanner that cannot see a construct must not answer "no such construct".
- Ask the question in a form with no structure to miss (scan every token),
  or forbid by name the construct you cannot read, reading the whole
  balanced construct.
- Enumerate the shapes from the language reference, not memory: writes to a
  variable include named arguments, `foreach`/`catch` targets,
  destructuring, `global`/`static`, `$$name`, `$GLOBALS`, `extract()`,
  `eval()`, `include`.
- What is done to a variable is done to the expression it roots: gather its
  index chain and redundant parentheses, then classify from the edges.
- A string is what PHP assembles: join literals across `.`, read
  interpolations, heredocs and constants, and report an unreadable table as
  `unread` (`RollupWritersAreMarkedTest`).
- Plant the defect in every spelling, against the old version too. Hunt the
  silent direction (a forbidden shape read as absent), not the loud one.

### 21. Naming a thing is not being guarded by it
*Contains x* and *implies x* read the same in a grep and are different
claims.
- An invariant of the form "only X and all X" has two halves; a check on one
  looks like a check on both. `||` widens a gate and `&&` narrows it: an
  upgrade gate is a disjunction of version equalities or it is not a gate.
- A greedy `.*` between anchors turns "is" into "contains". A substring test
  of an expression is this error every time: parse it.
- Two assertions compose only if they name the same thing. For "this value
  reaches that call", follow the value; for "this result decides that",
  execute it where the code is pure.
- A name is not a call site: read what precedes the token (`->`, `::`,
  `new`, `function`, a namespace), `\name`, and case
  (`SourceScan::callableName()`). Then ask what it is called with, and
  whether the thing named is the thing meant.
- "The form that posts carries the token" means finding that form, bounding
  it and reading the input inside it.
- When one site read by name is fixed, every site read by name is the sweep.

### 22. "Somewhere in the block" is not an order
"A guards B" is a claim about sequence, about nothing changing in between,
and about both directions: B never runs when A said no, and B runs whenever
A said yes.
- Walk the range once in source order, carry the value forward, drop it at
  the first thing you cannot read, and refuse the rest by line.
- A statement the invariant depends on runs on every path: first in its
  statement, directly in the block (not the body of a braceless `if`), with
  no `return`/`exit`/`throw`/`break`/`continue`/`goto` above it. Depth is
  not position. Ask it with the one shared helper at every site, and after a
  finding grep for the mechanism (every depth counter), not the symptom.
- Anchor on the act (the call), not on where the value was assigned. A
  condition is code: scan to the brace. A hold lives in the block that set
  it. A scan that accepts a write reads on for the one that undoes it.
- The range is not the scope: a reference (`=&` from either end,
  `use (&$x)`, `foreach` by reference, `global`) and the constructs that
  name nothing (`$$name`, `extract()`, `eval()`, `include`) can touch a
  variable without naming it in the range. Refuse them file-wide for every
  name a scan vouches for.

### 23. Versions compared as text
`"1.9.8" >= "1.9.76"` is true as text. Use `version_compare()`; cast a
version that really is a counter to `int`.
`ForbidVersionRelationalCompareRule` sees only operands named as versions.
Equality is unsafe too (`"1.9" == "1.90"`), and `!==` is not a direction:
older and newer are `version_compare()`'s to say
(`SchemaVersionIntegrationTest`).

### 24. A validation in a hydration path fails the batch, not the record
- A constructor that doubles as row hydration runs over stored data in a
  loop with nobody to answer: one bad row strands the queue. Validate at the
  write boundary and again at the point of use. Before adding a check, ask
  who is on the other end of the throw and how many unrelated records share
  the call.
- A guard that can be dropped silently needs a test of its output. The curl
  pin (`CURLOPT_RESOLVE`) is lost to an unbracketed IPv6 literal, to a proxy
  in the environment (set `CURLOPT_PROXY => ''`, `CURLOPT_NOPROXY => '*'`),
  and to options merged in front of it or a followed redirect
  (`DataEngine\SelfCall` returns every option). Two implementations of one
  protection are pinned to one option set
  (`PinnedRequestsCarryTheHardeningSetTest`).
- Making a read fail loudly changes every caller, including error paths. An
  error path does not ask the failing dependency again, ends the caller's
  transaction before writing its own row, re-enters itself at most once
  (`p202RecordMysqlErrorRow()`), says "recorded" only when it was, and draws
  the footer without reads (`template_bottom(false)`).
- A caller that rolled back names the failure itself
  (`record_mysql_error()`'s third argument;
  `ErrorPageAfterRollbackNamesTheFailureTest`). A scan's file filter is a
  claim about where the construct can be.

### 25. A writer's "none" must be the reader's "none"
- Two writers of one table is the tell (`0` vs NULL: `'0' != null` is true
  in PHP 8). Before adding a writer, find how every reader tests "absent"
  and write that; where readers disagree, make the reader accept every
  "none", since stored rows will not change.
- In SQL, `IS FALSE`, `= 0`, `= ''` and `NOT IN` miss NULL. On a column a
  LEFT JOIN fills, ask `IS NULL OR = 0`, and seed tests with the NULL the
  writer writes. One function owns a spelling:
  `NoTrafficSource::condition()` (`NoTrafficSourceTest`).
- `REPLACE` resets every column it does not name. A rewrite names what it
  changes (`INSERT … ON DUPLICATE KEY UPDATE`). A click's rows have one
  writer, `MysqlClickRepository` (`ClickRowsWrittenOnlyByTheWriterTest`).
- A second writer owes what the first does after its write (the
  subscription sweep queues `subscription.changed`, bounded to 7 days;
  `SubscriptionWritesAreAnnouncedTest`).
- A "none" used as a lookup key finds every row that has none: parse ids
  first (`ClickId::parse()` refuses 0;
  `PublicIdsAreComparedAsNumbersTest`; `ClickPathReads` answers nothing
  below 1).
- When a record answers "did X happen", list who else writes it: page pings
  are marked (`Prosper202\Cron\CronRun`, `PagePingIsNotACronRunTest`). A
  record anyone can write is advice, never a gate
  (`tests/live/autocron-switch.sh`).

### 26. A boundary stands in for a predicate only where its order holds
An id range is not a time range: ids are not allocated in time order.
Select the rows the predicate names, by an index on its own column, and act
on their keys. A fix to one consumer of a stand-in is a sweep of every
consumer. A delete tells the derived sums (`RollupDirty::clicksDeleted()`);
the click-table list is held to the schema
(`ClickRetentionCoversEveryClickTableTest`). A day's midnight is
`DateTimeImmutable::createFromFormat('!Y-m-d', $day, $zone)`, never a zone
abbreviation (#29). A click id names one row per visit: a read of "the
click" names its visit (`LatestVisit`,
`SingleClickReadsNameTheirVisitTest`), and counts take
`LatestVisit::isLatest()` (`ClickRowCountsTakeTheLatestVisitTest`).

### 27. A stored id is a claim about ownership that nothing re-checked
A row of another account named by id reads as naming nothing.
- Joins tie the owner in the ON clause: `AND ref.user_id = row.user_id`, as
  `ReportsController::dimensionJoin()` does (`AccountScopedJoinTest`). The
  scope on the driving row does not cover its joins.
- Not seen by that test: a lookup by an id read from another row; a table
  owned through its parent; SQL from a runtime builder. Whenever an id from
  one row selects another row, ask whose it is and what the answer decides
  (a name, a payout, which rows a bulk write touches).
- A request naming two records independently (an offer and a click, a
  rotator and a click) compares their owners before the write. The account
  handed in is the other record's, never the click's own
  (`MysqlClickRepository::recordExit()`).
- After tying a join the joined row can be NULL: read every consumer. Group
  by the expression you select, count a none row by the same NULL, and give
  a row whose record is not the account's no action rather than a different
  one. Click-path reads go through `ClickPathReads`.
- Writes too: every UPDATE and DELETE in served pages carries
  `user_id = <the account>` in its own WHERE, or a listed check that runs
  first (`AccountScopedWriteTest`; SQL only).
- When a fix closes a hole, ask what the hole left behind. A sign with two
  origins is a report, not a repair (`TrafficSourceOwners`: moved only on an
  admin's say-so, never in the upgrade). What may run unasked is the act
  that is right whichever origin made the sign and takes nothing from
  anyone: the cron job's split of a source every other user's account was
  put on after it was last saved, listed afterwards
  (`TrafficSourceAutomaticSplitIntegrationTest`). Run each repair to its
  fixed point (`TrafficSourceSplitIntegrationTest`).

### 28. A field's JSON type is part of the contract, and its readers are not in the server's tests
A type change breaks readers no PHP suite runs: Go structs and their test
fakes, the eval cases and reference agent, the instance suites,
`tests/live/`, and the display layer. It also makes negative assertions
against the old type pass forever. Before pushing a change to what an
answer is (a type, a null, a field's presence), stand up an instance, run
the eval suite and the `@group instance` suites, and sweep readers by field
name. `TestStructsDecodeAPIMoneyAsJSONNumber`: Go money fields are
`json.Number`.

### 29. One offset stands in for a zone's history
A zone is a list of offsets with the instants they start. Never apply one
offset (a session `SET time_zone`, a cached `getOffset()`, a fixed
`+ 19800`) to a range. Convert each row by the offset in force at its own
instant (`LocalTime::secondsSql()` / `datetimeSql()`) in the account's zone
passed in (`SessionZoneSqlTest` refuses SQL that sets or reads the
connection's zone). One stored value has one parser:
`AccountZone::normalize()`
(`SetTimezoneReadsTheAccountIntegrationTest`).

### 30. A transform applied where a value is stored moves every comparison with it
When a value is masked, hashed, folded, truncated or canonicalized into
storage, find every comparison with the stored value and which side of the
transform the other operand is on, and every other writer of that kind of
value (`StoredAddressWritesTest`, `StoredVisitorIpSourceTest`).
- Compare before the transform where the raw value is in hand. Otherwise
  the comparison is on the transformed key, a decision written where the
  check lives. A many-to-one match needs a rule for several matches
  (`LastClickFromAddress` answers a masked address only when one click
  matches).
- A setting read before its subject is known is about some other subject:
  the click path waits for `p202ApplyOwnerPrivacy()`
  (`OwnerPrivacyAppliedFirstTest`).
- List every place the guarded act happens (every cookie setter) before
  trusting the guard.

### 31. A copied value is stale from the moment its source changes
A denormalized copy needs both halves: every re-derivation writes every
column it derives (`ClickRollupSql::refreshedColumns()`,
`ClickRollupSqlTest`), and every writer of the source queues the copies
(`RollupRefresh`). When adding a column to a derived table or a writer to
its source, find the other half. Stale rows heal only when re-rolled.

### 32. A shortcut that returns early skips the work the old path did after
When a change makes a function skip code it used to run (an early return, a
fast path), list every side effect of the skipped code (flags, counters,
cache writes, the "done" mark) and move each to where the new path runs
(`ClickUpgradeIntegrationTest`). Moving a write later is the same: find
every reader of it in the same request. An endpoint that redirects before
its rows exist fills placeholders from the values it holds
(`replaceClickPlaceholders()`); the read-back is for rows already written
(`ClickUrlTokensInstanceTest`).

### 33. A tagged version's upgrade step is frozen
The ladder runs only when the stored version differs from the code's, so a
schema change after a version is tagged takes a new version and a step
gated on the tagged one. Local clones often lack tags: ask the remote
(`git ls-remote --tags origin 'v*'`) before touching the ladder. Prove an
upgrade by installing from the latest tag and from the oldest, with
`tests/live/upgrade-equals-install.sh` run locally before pushing. The
ladder's error log carries only what a step could not do
(`UpgradeLadderTest`, `ScheduledDeletionUpgradeIntegrationTest`).

### 34. A lock error ends the transaction, not the statement
A deadlock rolls back the whole transaction, so a catch inside one that
carries on is answering for rows that are gone. Undo one step with
`Connection::withSavepoint()` and pass lock errors up to the transaction's
owner (`CatchInsideTransactionPassesItOnTest`; reads only the callback's own
text). Retry a deadlock, not a lock wait timeout. Decide by the errno the
server set, never by words in the message. Once part of a batch may stand,
every row is answered and the answer kept under the idempotency key; an
unconfirmed commit is `unconfirmed`, never `retryable` (#13).

### 35. A cleanup chosen by another row than its write removes what nothing replaces
- "Write, then remove the rest": both select by the same predicate, so a
  window that writes nothing removes nothing
  (`ClickRollupSql::deleteEarlierVisits()`).
- `202_clicks` is partitioned weekly by `click_time`: a lookup by a column
  outside the partition key probes every partition. Latest visits are read
  from `202_click_latest_visits`. Time queries on the layout the installer
  makes.
- A table the upgrade makes is read before the upgrade runs: give each
  reader a path that converges (`ClickRollupSql::queueRetry()`). A bare 5xx
  while an upgrade is pending answers 503 naming it
  (`Bootstrap::pendingUpgrade()`,
  `UpgradePendingAnswerServedIntegrationTest`,
  `ServerFailureAnswersAreListedTest`); a committed write keeps its 500.
- A decision that cannot be undone (stepping over a click in
  `ReClickBackfill`) counts only failures shown to be that item's, not the
  moment's (`MOMENT_ERRNOS`) or the install's (`INSTALL_ERRNOS`).

### 36. A lock does not move the snapshot
- Under REPEATABLE READ a plain read answers from the snapshot taken at the
  transaction's first plain read, not at the lock or `BEGIN`. Take the lock
  before the first plain read: `Connection` notes it (`snapshotTaken()`),
  and `lockClick()` refuses a click asked for after it
  (`LedgerReadsAfterTheLockIntegrationTest`). Locking one read fixes one
  read.
- A locking read locks whatever its plan scans, gaps included. Measure it on
  the path it runs on, joins included.
- An `INSERT … SELECT` share-locks the rows it reads, in join order. Before
  adding a lock, list every statement locking the same tables and take rows
  in their order (`TrafficSourceOwnersIntegrationTest`).
- A confirmation carries what the operator was shown, and the server
  compares with that (`owner_user_id`, `repair_to_user_id`).
- Not seen: statements on the raw handle (`writeConnection()`), reads
  `isPlainRead()` does not recognise, and locks other than a click's.

### 37. A row a batch skips is the first row of the next batch
Every row a `LIMIT`ed batch takes must leave the selection: written to what
the select excludes, or passed by a cursor on the ordering key. A row the
batch cannot act on for an unknown reason is an error, not a skip. A cursor
is a claim about an index: name the index, and read the plan with `ANALYZE`
on a table holding other accounts' rows.

## Go CLI errors must be agent-actionable (`go-cli/`)

An agent reads a failure once and must know what to do next. Every error
path in `go-cli/cmd/` follows this contract (plumbing: `cmd/cli_errors.go`,
`cmd/root.go`, `internal/api/client.go`):

1. **Categorize input errors**: `validationError(...)`, never a bare
   `fmt.Errorf`. API failures carry `auth`/`network`/`server`/`validation`.
2. **Wrap with `%w`, never `%v`**: exit codes and hints resolve through
   `errors.As`.
3. **Attach a next step** when the message leaves a choice: `.WithHint()` on
   new errors, `withHint(err, …)` on wrapped ones. Name the command, flag or
   ordering. Valid values go in the message.
4. **Class-wide hints go in `api.HintFor`**, not per call site.
5. **Never print errors yourself**: return them. `Execute` prints
   `Error [category]: … / Hint: …`, or under `--json`/`--ndjson` one
   `{"error": {…}}` envelope on stderr. Stdout stays empty on failure.
6. **Test the contract**: assert `exitCodeForError(err)` and `hintFor(err)`
   (`cmd/cli_errors_test.go`).

`errorlint` holds 2. `TestFailuresArePrintedOnlyByExecute` holds 5 for
`fmt.Fprint*` to `os.Stderr` with a message beginning "Error" or "Failed",
and lists the bulk commands and the shell that print one line per item.

Flags are written in kebab-case wherever text shows them, in both CLIs;
snake_case is an accepted alias that is never shown
(`scripts/check-flag-spelling.py`, `canonicalFlags()`; PHP CLI: `OptionName`,
`KebabCaseArgvInput`, `FlagsAreKebabCaseTest`).

When adding a command, run it once with a wrong flag and once against a dead
URL under `--json` and read the envelopes as an agent would. The user-facing
contract is in `documentation/cli/10-go-cli.md` under "Errors"; keep it in
sync.

Commands are found through `p202 commands --brief` (one line each, held to a
token budget by `TestCommandsBriefIsTheCatalogAnAgentReads`) or
`p202 search`, which matches words. A command that does what a UI page does
gets an entry in `searchTasks` (`cmd/search_tasks.go`) with its command
line, pages and the words people use
(`TestEveryUIMenuLabelHasASearchTask`, `TestSearchFindsTheEvalAsksCommand`,
`searchKnownMisses`). Write phrases from the task, never from an eval ask.
Search returns candidates with `coverage` and `good_match`; it fails only
when nothing matches or the query names a page no command does. There is no
fuzzy matching, and unknown words are reported (`unknown_terms`), which says
the commands lack the word, not the capability. Measurements are in
`cmd/search_rank.go`'s header.

## Development environment notes (sandboxed/CI sessions)

- **`composer install` can fail with "Could not authenticate against
  github.com"**, leaving `vendor/` partial (no `vendor/bin/`). Workaround:
  `composer dump-autoload --dev`, then the standalone PHPUnit 9 phar
  (`https://phar.phpunit.de/phpunit-9.phar`):
  `php phpunit-9.phar --bootstrap vendor/autoload.php tests/...`.
- **Expect ~76 environmental errors on a partial vendor/** (suites needing
  Symfony Console, Slim, a live DB or memcache). Run the suites relevant to
  the change and say which could not run.
- **The phar takes ONE path argument**; extra paths are silently ignored.
  One path per invocation, and compare the test count with what the path
  should contain.
- **`bin/p202` on a partial vendor/**: clone `symfony/service-contracts`,
  `symfony/deprecation-contracts` and `symfony/string` at `composer.lock`'s
  versions into `vendor/symfony/<pkg>/`, add the `Symfony\Contracts\Service\`
  and `Symfony\Component\String\` PSR-4 mappings to both
  `vendor/composer/autoload_psr4.php` and `autoload_static.php`, and run with
  `php -d auto_prepend_file=vendor/symfony/deprecation-contracts/function.php
  bin/p202 ...`. The CLI keeps its own config (`config:set-url`,
  `config:set-key`).
- **Click endpoints 500 on a partial vendor/**: clone `ua-parser/uap-php`
  v3.10.0 into `vendor/ua-parser/uap-php/` and add the `UAParser\` mapping
  to the same two autoload files.
- **`docs/openapi.yaml`** is parsed as YAML only by
  `scripts/check-openapi-yaml.py`. Write a description containing `: ` as a
  `>-` block or quote it.
- **A Go test reading a file outside `go-cli/`** reads it through
  `repoPath()` and the file is listed in both `paths:` lists of
  `.github/workflows/go-cli.yml` (`TestRepoFilesTriggerTheGoWorkflow`).
- **Go commands run from `go-cli/`** (`go vet ./... && go test ./...`;
  `-short` skips the ~40s forecast suites). Run `golangci-lint run ./...`
  before pushing.
- **`php -S` caches bytecode for 2 seconds** (the CLI does not): wait 3s or
  restart the server between an edit and a probe request.
- **`php -S` here has no memory limit.** Measure request sizes on
  `php -d memory_limit=128M -S …`, and bound any list the input can grow.
- **Sandbox PHP is 8.4; CI runs 8.3.** Before 8.4, `php -S` treats a path
  containing `.` as a static file, so `install-instance.sh` serves through
  `tests/fixtures/agent-eval/ci/router.php`. To run 8.3 here: `apt-get
  download` the `php8.3-{cli,common,mysql,mbstring,curl,xml,gd,zip}`
  packages, `dpkg -x` each into the scratchpad, and run `usr/bin/php8.3 -n
  -d extension_dir=usr/lib/php/20230831 -d extension=mysqlnd -d
  extension=mysqli ...`.
- **A live local instance**: install `mariadb-server`, start `mariadbd
  --user=mysql`, create a DB and user, run
  `tests/fixtures/agent-eval/ci/install-instance.sh` (prints the API key),
  then `tests/fixtures/agent-eval/seed.sh`.
- **PHPStan** runs in CI (`phpstan.neon.dist`, `phpstan-baseline.neon`).
  Without `vendor/bin/`, use the release phar: `php phpstan.phar analyse -c
  phpstan.neon.dist --no-progress`. Expect ~6 `class.notFound` for `cli/` on
  a partial vendor; a scratch config with `scanDirectories` confirms a clean
  run (do not commit it).
- **`StaticSqlSchemaTest`** prepares every static v3 statement on a real
  server. It is `@group integration` and drops and recreates tables: point
  `P202_TEST_DB_*` at a scratch database, never a live instance's.
- **`git checkout <file>` restores from the index**, not your working copy.
  When planting a defect, back the file up to the scratchpad or stage first,
  and re-run the suite after restoring.
- **`pgrep -f`/`pkill -f` match your own shell** when the pattern is in your
  command line. Kill by port (`fuser -k 8098/tcp`) or by a pid looked up
  separately.
- **A worktree's `vendor/` is copied (`cp -a`), never symlinked**: a linked
  one resolves classes to the main checkout, and `verify.sh` refuses it.
- **Zone names**: this sandbox lacks `tzdata-legacy`, so `US/Eastern` and
  `Europe/Kiev` are not zones here. Test with a name in `ALL_WITH_BC` but
  not in `listIdentifiers()` (`America/Montreal`).
- **`tests/live/upgrade-equals-install.sh`** needs PHP 7.4: add the
  `ondrej/php` PPA for noble (key
  `14AA40EC0831756756D7F66C4F4EA0AAE5267A6C`, `signed-by`), install
  `php7.4-{cli,mysql,mbstring,curl,xml,json}`, `git fetch --depth=1 origin
  45897876895372162e83209167e750e078d3e3e5`, and run as a TCP user granted
  on `p202\_uei\_%` with `P202_DB_USER`, `P202_DB_PASS`,
  `P202_ORIGIN_PHP=php7.4`. Local is MariaDB 10.11 and CI is MySQL 8.0, so
  read CI's run too.
- **One page shell, one chrome.** Every page renders on Bootstrap 5.3 with
  the Prosper202 theme: `template_top()` behind the login, `info_top()`
  (`202-config/functions-standalone-ui.php`) for standalone pages.
  `template_top()` throws on an unknown option key. Bootstrap 3, Flat UI and
  Font Awesome 4 classes render as nothing
  (`NoLegacyBootstrapClassesTest`). The chrome (`202-config/template.php`,
  `tracking202/_config/top.php`, `sub-menu.php`) is framework-neutral markup
  styled by `202-css/p202-chrome.css`: keep Bootstrap classes out of it and
  scope page styles with `p202-section-*` / `p202-sub-*`. Every third-party
  file is an entry in `202-config/assets.php` with its SHA-384; nothing
  loads from an external host except the ones `ShellIsolationTest` lists.
  Structural tests: `AssetManifestTest`, `ShellIsolationTest`,
  `NoLegacyBootstrapClassesTest`, `ComponentClassIsConsumedTest`. Layout,
  theme and event handlers are covered by the local browser passes (`node
  tests/browser/run.js`, with `--cdn` here; baseline in `lib/checks.js`).
  The UI standard (`documentation/features/ui-standard.md`): a form shows
  the common case, the rest sits under a closed `.p202-disclosure` labelled
  Advanced, and a value the app can find is never asked for.

## Closing the loop on mistakes

<learn_from_mistakes>

**A mistake is not finished when it is fixed. It is finished when it cannot
recur silently.** Every bug found here ends in one of these, in this order:

1. **A machine check, if the pattern is mechanically detectable.** A
   **PHPStan rule** in `202-config/PHPStan/Rules/`, registered in
   `phpstan.neon.dist`, for anything expressible over the syntax tree; or a
   **structural test** under `tests/` that walks the tree or the schema and
   asserts the invariant everywhere (`UncheckedExecuteTest`,
   `ScopeCoverageTest`, `StaticSqlSchemaTest`).
2. **An entry in "Error patterns to avoid"** when the pattern needs
   judgement a checker cannot apply. Write the failure mode, not the fix, in
   a few lines: the shape, the rule, the check. No incident history.
   `scripts/check-claude-md.py` (PR checks) holds this file to a byte budget
   and every test, rule and file it names to being in the tree.
3. **Both**, when a rule catches the common case only: the entry says what
   the floor does not cover.

- **A regression test is not a substitute.** It proves this bug is gone and
  nothing about the same shape elsewhere. Grep for every analogous site (#5)
  and decide whether a check can cover them all; the `p202-sweep` skill is
  that procedure.
- **Meet the bar for a new check**: clean against the whole tree first, then
  exercised against every shape it claims to cover. A rule that fires on
  correct code gets disabled.

A process failure rather than a code one belongs in "Verify your
assumptions".

</learn_from_mistakes>

## Verify your assumptions

<verify_assumptions>

**Before stating something as fact, know how you know it.**

- **Execute the mechanism, do not reason about it.** Reading yields an
  argument; running yields a fact. A `php -r` probe takes a minute.
- **Never report what you have not checked.** "Tests pass" means the suite
  you ran, in the environment you ran it in. State which suites, which
  environment, and what could not run.
- **A surprising result is a signal to investigate.** First suspect the
  check, not the code.
- **A performance fix must be shown to return the same answer.** Construct
  the input where the shortcut and the full computation could disagree (a
  sparse account, duplicate names, a half-hour zone, a DST boundary) and run
  both. Group after scoping; count final groups, not ids; cache keys carry
  database, account, window, dimension and limit; optional menus stay out of
  the first page request. Held by `SeenFilterListsIntegrationTest`,
  `ReportReadFailureIntegrationTest`, `TimeGroupingIntegrationTest`,
  `ReportIntegrationTest`, `OverviewTablesIntegrationTest`,
  `VariableReportIntegrationTest`.
- **An HTTP failure needs guidance for the status received**: a session hint
  belongs to 401, not 504 (`ReportRequestFailureTest`).
- **Answering a reviewer is reporting.** Open the file before you reply;
  where reachability decides something, execute the path.

Checks that quietly fail to check:

- **Assert that a planted defect landed** (`assert old in s`, re-grep)
  before believing the run. Count a plant as caught only when a named test
  fails, not on a compile error.
- **A case that two checks refuse proves neither.** Build each refusal case
  to trip only the check it names, and plant the removal of each check.
- **Back up to a fresh `mktemp` path**, `cmp` the copy before planting, and
  stop at the first failed restore.
- **A heuristic tuned on a set is measured on a set it never saw.** Write
  and hash the evaluation set first, report the sealed numbers, and treat an
  opened set as a development set. Write the claim after the measurement.
- **Assert that a probe perturbed the target** (the right directory, the
  right process).
- **Concurrent processes must not share a stdout you count.** One file each.
- **A narrow race needs its window widened** (a scratch-only sleep), not
  more attempts.
- **"Not succeeded" is not "refused".** Assert a refusal positively: the
  status and the guard's own sentence. For an expected failure, assert which
  failure.
- **A new lint rule is not done when it fires on its bug.** Run it on the
  clean tree, then plant every shape and call form, derived from the
  callee's signature.
- **CI is not green until CI says so.** Read the run after pushing;
  `in_progress` has not passed.
- **Local green is not CI green when versions differ.** Diff the version of
  everything on the path (PHP 8.4 vs 8.3; MariaDB vs MySQL 8.0, which spell
  `SHOW CREATE TABLE` differently: read `information_schema.COLUMNS`), and
  read the server log the job uploads.
- **A tier that reports PASS with no output did not run.** Give scratch
  shims their own directory and every tier a floor.
- **A macOS pass cannot see a case bug** (`tracking202/Redirect/` beside
  `redirect/`). Produce on Linux what will run on Linux, and compare
  artifacts across platforms.
- **Ambient state hides failures.** Run CLI tests with an empty `HOME` (keep
  `GOENV`, `GOPATH`, `GOMODCACHE`, `GOCACHE`); validate flags before
  building a client. Run a new scratch-DB integration test alone against a
  database created for the run.

</verify_assumptions>

## Review discipline
- Keep comments short: add one only when it records something the code cannot say. No narrative docblocks, no history.
- Review every file individually: read it, think about what each line does, especially error paths.
- After writing code, re-read it as a skeptic looking for the failure mode.
- When fixing a pattern, grep the entire codebase for every instance.
- Deleting CSS is a code change: grep each removed selector against the markup, and measure survivors in a browser.
- Per-file reading cannot catch a defect in the relationship between two files. For cross-file mechanisms, execute the path.
- Never report work as complete or merge-ready on tests that don't exercise the new path. State what was run and what could not be.

## Before committing
- Run the verification ladder and report its scope report verbatim:
  `.claude/skills/p202-verify/scripts/verify.sh --changed` (skill:
  `.claude/skills/p202-verify/`). A tier reported as `SKIP` did not run;
  never fold a skip into a claim that tests pass.
- Do a full deploy-quality review of the staged changes, file by file,
  tracing error paths and the patterns above.
- Confirm the code lints and relevant tests pass, or say explicitly what
  could not be run.
- Commit only once the review is clean.
