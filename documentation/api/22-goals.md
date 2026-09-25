# Goals

A goal is a named outcome — an install, reaching level 3, the third
purchase, $20 spent in a week — and what reaching it is worth. Goals are
core (plan §2.2): web campaigns and both app platforms use the same ones.
Events are the evidence; a goal decides what they count as.

All endpoints live under `/api/v3/goals` and are governed by the `goals`
scope area (`goals:read`, `goals:write`, `goals:stage`). The CLI is
`p202 goal …` (see [the Go CLI](../cli/10-go-cli.md)).

| Method | Path | What |
|---|---|---|
| `GET` | `/goals` | List (filter `campaign_id`, `registration_id`, `scope=account`; `include_archived=1`) |
| `POST` | `/goals` | Create version 1 |
| `GET` | `/goals/{id}` | The current definition, the campaigns paying for it, its live outcome count |
| `PUT` | `/goals/{id}` | Edit: a new version when the definition changes |
| `DELETE` | `/goals/{id}` | Archive (`?dry_run=1` previews) |
| `GET` | `/goals/{id}/versions`, `/goals/{id}/versions/{v}` | Every version |
| `GET` | `/goals/{id}/outcomes` | Live outcomes (`subject_type`, `subject_id`) |
| `GET` | `/goals/{id}/campaigns` | The campaigns paying for it |
| `PUT` / `DELETE` | `/goals/{id}/campaigns/{campaign_id}` | Pay for it on a campaign (`payout`, `notify_traffic_source`) / stop |
| `GET` / `POST` | `/goals/{id}/reevaluation` | Preview / apply re-evaluation under a version |
| `POST` | `/goals/validate` | Validate a definition (a read) |
| `POST` | `/goals/evaluate` | Evaluate definitions against events, writing nothing (a read) |

Writes are stageable (`?staged=1`); creates honor `Idempotency-Key`.

Events reach goals from pixels and postbacks (`event=`), `POST /events` and
`p202.track()` on landing pages — see [web events](23-events.md) — and a
campaign's own goals are edited on its Setup › Campaigns page too.

## Owners

Every goal has one owner (`scope`, `scope_id`):

- **`campaign`** — a campaign's own goal. It evaluates that campaign's clicks,
  and is payable there by default when its value is not `none`.
- **`registration`** — an app's default set. Campaigns attach the ones they
  pay for (`PUT /goals/{id}/campaigns/{campaign_id}`); an attached goal
  evaluates that campaign's clicks from the moment it is attached. The app's
  installs evaluate all of them once the Android intake exists (PR 5).
- **`account`** — goals every app of the account shares; what an
  account-wide SKAN encoding names.

Names are unique per owner. `after` may only name goals of the same owner.

## The definition

Strict: an unknown key anywhere is a `422` naming its path, and nothing is
cast (a count of `"3"` or `3.0` is refused).

```json
{
  "name": "Reached level 3",
  "trigger": {"event": "level_reached", "where": [{"prop": "level", "op": "gte", "value": 3}]},
  "threshold": {"count": 1},
  "after": [12],
  "within": {"days": 7, "from": "install"},
  "repeat": {"mode": "once"},
  "value": {"type": "fixed", "amount": "4.00"}
}
```

| Field | Form | Default |
|---|---|---|
| `name` | 1–100 characters | required |
| `trigger` | `{"event": "<name>", "where": [predicates]}` or `{"install": true}` | required |
| `threshold` | `{"count": 1–10000}` (the Nth matching event) or `{"sum": {"prop": "<prop>", "gte": <amount>}}` (a running sum) | `{"count": 1}` |
| `after` | up to 5 goal ids that must be reached first | `[]` |
| `within` | `{"days": 1–3650, "from": "install" \| "click"}` or `null` | `null` |
| `repeat` | `{"mode": "once"}` or `{"mode": "each"}` / `{"mode": "each", "max": 1–10000}`; with a `sum` threshold `each` requires `max` | once |
| `value` | `{"type": "fixed", "amount": …}`, `{"type": "from_property", "prop": "<prop>"}` (default prop `$revenue`), `{"type": "none"}` | none |

Predicates (at most 20, ANDed — a second goal expresses OR): `eq`, `neq`
(string, number or bool), `gt`, `gte`, `lt`, `lte` (numbers), `in` (a list of
1–50), `exists` (no value). A prop is a property name or `$revenue`, the
event's own revenue field. Event names are 1–64 of letters, digits and
`_ . : -`. Amounts are at most 999999.99999 with at most 5 decimal places.
An install trigger takes no `where`, a count of 1, `repeat: once`, no
`after`, no window, and no `from_property` value.

A sum that repeats must say how many times (`repeat.max`, refused by that
path without it): one $1,000 purchase against "every $0.01" would otherwise
reach 100,000 outcomes in one event. One event reaches at most `max` of a
goal, and the count is computed, not searched for. A summed value outside
±999999.99999 (the most one conversion holds) does not count, and the
running sum is held at `max × gte` (the largest threshold the goal can
still use), so progress reports at most that. A count goal may repeat
without a max: it grows by one per event. A definition stored before this
rule is shown with `definition_valid: false` and evaluates nothing until it
is edited.

The stored form is canonical — every key, defaults written out, amounts as
decimal strings — and a `PUT` whose canonical form equals the current one
is not a new version (`version_created: false`).

## Evaluation

The rules, and the cross-language vectors that pin them, are in
`tests/fixtures/app-sdk-contract/goals/README.md`; the server's evaluator
and the iOS SDK (which evaluates the goals an SKAN encoding names on the
device) are held to them; Android's goals are evaluated on the server, so
the Android SDK reports every event and evaluates none. In
short: events are evaluated in event-time order (`min(occurred_at,
received_at)`, then arrival, then event id), never arrival order; each event
under the goal version current when it was received; a window whose anchor
the subject lacks makes the outcome *ineligible* (`no_click`, `no_install`),
never re-based.

On the server, a subject's events are stored once (a retried event id is a
duplicate; the same id with different content is a `409`-class refusal; a
subject holds at most 10,000 events; an event's `revenue` is at most
±999999.99999) and evaluated in the same transaction.
`POST /goals/evaluate` answers at most 10,000 outcomes; one that reaches
more is refused with a `422` naming `events`.
An event that sorts before a stored one replays the subject: outcomes whose
reaching event moved are superseded (`replay`), outcome and conversion
alike, and nothing is withdrawn.

## What a reached goal pays

Every reached goal writes an outcome. When the subject has a click it also
writes a conversion through the ledger (`source: goal`, `source_ref:
goal:<id>:<version>`, `dedupe_key: goal:<id>:<version>:<n>:<event_id>`, the
event's `transaction_id` kept), so the click's value, its breakdown and MTA
see it like any other conversion. Whether it pays is the campaign's:

- a goal the campaign does not pay for is tracked, not paid (the row carries
  the goal's value, `payable` 0);
- a campaign `payout` overrides the goal's value there;
- otherwise the goal's value: `fixed` pays; `none` is tracked; a
  `from_property` value pays only when the path the event arrived by may set
  a paid value (`revenue_trusted`) — an untrusted one is stored on the row
  but not credited (`value_note: untrusted_value`);
- an ineligible outcome never pays.

A payable goal whose campaign term has `notify_traffic_source` (the default)
tells the click's traffic source when it is reached, once, with
`[[p202_goal]]`, `[[p202_goal_id]]` and `[[p202_goal_value]]` filled; an
outcome written in place of an earlier one by a replay or a re-evaluation
is never announced again ([web events](23-events.md#telling-the-traffic-source)).

The click's value is the ledger's: `accumulate` campaigns add their payable
goal rows, `replace` campaigns show the latest. Attaching goals does not
change a campaign's payout mode; `GET /goals/{id}/campaigns` shows it.

## Installs

An Android install is a subject too ([24-android-installs.md](24-android-installs.md)).
It evaluates its registration's goals, the account's and — when it is
attributed and trusted — its click's campaign's; its outcomes carry
`app_registration_id`. Only an attributed, trusted install has a click, so
only its outcomes write conversions; the others count in the funnel.

- **The built-in install goal.** Every Android registration has one goal
  with `builtin: install` (created with the registration): triggered by the
  install, reached once, no value of its own. It cannot be edited, archived
  or re-evaluated. Its conversion *is* the install conversion — key
  `install`, source `app_install`, one per click — never a second `goal:`
  row. A campaign that lists no goals pays for installs at its default
  payout; one that lists goals pays only if it lists this one too, at the
  listed payout or its default.
- **Revenue from the app** pays a `from_property` goal only under the
  registration's `trust_client_revenue`.
- **Traffic-source postbacks.** A paid outcome of an install with
  `notify_traffic_source` on queues the click's traffic-source server
  postback, with `[[p202_goal]]` and `[[p202_goal_value]]`; the source hears
  about an outcome once (a pending postback of a replaced outcome is
  cancelled; a sent one is never repeated). Web clicks' postbacks join with
  the web events work.

## Re-evaluation

An edit leaves history alone. `GET /goals/{id}/reevaluation` previews, per
subject — a click with events on the campaigns the goal applies to, or with
`subject_type=install` (the default for a registration or account goal) an
Android install of the goal's registration, the account or those
campaigns — which outcomes
would be retired and written under a version (default the current one) and
what happens to each retired outcome's conversion — superseded by the new
row, or deleted where the new version no longer reaches the goal. `POST`
applies it, one click per transaction, and rebases the click onto the
version so later events and replays keep using it. The funnel count for a
click stays one, never two.

The goal is re-decided with its **dependents** — every goal whose `after`
names it, directly or through another dependent. If the new version stops
reaching a prerequisite, the goals reached behind it are retired too (their
conversions deleted or superseded); if it starts reaching it, the goals
that were waiting on it are written. `goals` in the answer (per click and
in total) lists them, and every retired or written row names its
`goal_id`. Their stored progress is replaced with the outcomes, so the next
event continues from the same answer a replay would give. Dependents never
add clicks — the selection and the 1,000-click cap are the goal's own — and
a dependent's version that cannot be evaluated (an invalid definition, a
missing prerequisite) is left exactly as it is.

A re-evaluation can return to an outcome an earlier one retired — the same
goal, version, repeat and event, for example a dependent whose prerequisite
stopped matching and then matched again. That outcome is revived rather than
written twice, and so is its conversion, whichever way it was retired: a
superseded conversion counts again, and one the re-evaluation deleted is
restored (with its revenue posted to the customer again when its deletion
voided it). A conversion someone deleted by hand stays deleted. The revived
conversion counts toward the click again under the campaign's payout mode
(on a `replace` campaign the latest conversion still sets the value, and a
revived one keeps its original place in that order).

## Examples

```bash
curl -X POST https://your-domain.com/api/v3/goals \
  -H "Authorization: Bearer YOUR_API_KEY" -H "Content-Type: application/json" \
  -d '{"scope":"campaign","scope_id":7,"definition":{"name":"Sale","trigger":{"event":"sale"},"value":{"type":"fixed","amount":"20.00"}}}'

p202 goal create --registration-id 3 --name "Level 3" --event level_reached --where "level gte 3" --value 4
p202 goal campaign set 12 7 --payout 5.00
p202 goal update 12 --count 2
p202 goal reevaluate 12          # preview
p202 goal reevaluate 12 --apply
```
