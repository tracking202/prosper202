---
name: p202-sweep
description: >-
  Fan a single invariant out across every call site in Prosper202 in parallel,
  then land the result as a PHPStan rule or a structural test rather than a
  pile of one-off fixes. Use whenever a defect turns out to be an instance of a
  shape rather than a one-off, whenever you are about to fix one occurrence of
  something and assume the rest are fine, and whenever CLAUDE.md error pattern
  #5 ("grep for every analogous code path") or #12 ("trace a bad value from the
  outermost entry point") applies. Also use for coverage questions across many
  modules: does every command re-check this flag, does every endpoint enforce
  this scope, does every handler check this return value.
allowed-tools: Bash, Read, Grep, Glob, Task
---

# Prosper202 invariant sweep

`CLAUDE.md` records the same lesson twice. Error pattern #5: "When
implementing a security measure, grep for every analogous code path and apply
the same pattern. Spot-checking misses these." Error pattern #11: the fail-open
scope default was fixed, and the identical shape "reappeared two functions
away."

A sweep is the answer to that, and it has two halves that are easy to
separate and must not be. The fan-out finds the instances. The machine check
stops the shape recurring. A sweep that ends at "I fixed eleven call sites" is
half done, because the twelfth gets written next month.

## Step 1: state the invariant as a predicate over one site

Write it so a worker looking at a single file can answer yes or no with
evidence, without knowing about the other files. Vague invariants produce
vague reports.

Weak: "check the API key handling is safe."
Strong: "for each function that answers a question about schema or config
state, does a failed `prepare`, a failed `execute`, or a false `get_result`
return the same value as a legitimate negative answer?"

That second form is error pattern #11 generalised, and it is checkable by
reading one function.

## Step 2: partition the tree, one worker per slice

Slice by directory or by call site, never by "half the codebase." Each worker
gets the invariant, its slice, and nothing else. Useful partitions here:

- `api/` by resource controller
- `cli/Commands/` by command file
- `go-cli/internal/` by package
- `202-config/` by class
- `tracking202/redirect/` as a single slice, since the click path is coupled
- callers of one function, from `grep -rn` output split into equal chunks

Spawn workers in parallel. Give each one file pointers rather than pasted file
contents, so the payload stays small and the main thread keeps room to reason
about the aggregate.

Every worker returns the same shape, so the results can be compared:

```
slice: <path or call-site range>
sites examined: <n>
violations:
  - <file>:<line>  <one line: what the code does, why it violates>
uncertain:
  - <file>:<line>  <what could not be decided by reading, and what would settle it>
clean: <n>
```

The `uncertain` bucket is load-bearing. A worker that guesses to avoid an
empty section is worse than one that reports it cannot tell, because the guess
enters the aggregate as a fact.

## Step 3: settle the uncertains by executing, not by reading

"Reading a signature, a config, or a comment yields an argument; running it
yields a fact." A ten-line `php -r` scratch script or a `go test` in a scratch
package settles most of them in under a minute.

This is the step that catches the expensive mistakes. Error pattern #8 shipped
because arrow-function capture semantics were asserted from reading one level
up rather than executed. The codebase turned out to have eleven `bind()`
methods in two different shapes, which no amount of reading a single call site
would have revealed.

Derive shapes from the callee's own signature rather than a hardcoded list.

## Step 4: decide the check before fixing anything

Fixing first is tempting and it loses the sweep's whole value, because once
the tree is clean it is much harder to prove a new check actually catches the
shape. Decide now, in this order:

1. **A PHPStan rule** in `202-config/PHPStan/Rules/`, registered in
   `phpstan.neon.dist`, if the shape is expressible over the syntax tree.
   Capture semantics, `bind_param` arity, forbidden call shapes.
2. **A structural test** under `tests/`, if the question is about the codebase
   rather than one file. Follow the existing shapes: `UncheckedExecuteTest`,
   `DuplicateGlobalClassTest`, `ScopeCoverageTest`, `ApiKeyAuthPathScopeTest`,
   `StaticSqlSchemaTest`.
3. **A CLAUDE.md entry** under "Error patterns to avoid", if the shape needs
   judgement a checker cannot apply. Write the failure mode, not the fix.
4. **Both a check and an entry**, when the check covers the common case only.
   The rule is the floor; the entry says what the floor does not cover.

An unregistered rule never runs. Register it in the same commit.

## Step 5: meet the bar for the new check

A rule with false positives gets disabled, which leaves the pattern unguarded
and nobody notices. One draft in this repo produced 194 false positives.
Before the check ships:

- Run it against the whole clean tree. Zero findings on correct code.
- Plant a defect in **every** shape and call form it claims to cover.
  Extending `BindParamArityRule` to the `bind()` wrapper needed four: variadic
  instance, array-taking, static namespaced, static global. The first "it
  works" run exercised one of them.
- Confirm each planted defect actually landed before believing the run. A
  `str.replace` that silently matched nothing once made a good test look
  worthless. Re-grep the file after planting.
- Instrument the rule to report every site it declines to analyse. If that
  count is zero, fuller symbol resolution in CI cannot surface new findings;
  if it is not, say so.

## Step 6: sequence the delivery

Order the commits so the sequence proves itself to a reviewer:

1. the check, failing against the current tree
2. the fixes, in slices small enough to review
3. the check passing, plus the CLAUDE.md entry if the shape needs one

Then run `p202-verify` and report the scope. A sweep touching many files is
exactly the case where "tests pass" hides which paths were exercised.

## Reply shape

State the invariant, the partition, how many sites were examined, how many
violated, what settled each uncertain, and which artefact now prevents
recurrence. Name any slice a worker could not complete and why. Do not report
a count without saying what was counted.
