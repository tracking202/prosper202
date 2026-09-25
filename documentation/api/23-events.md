# Web events

A web event is something a click went on to do after it landed — signed up,
bought, bought again, watched the video. Events are the evidence; a
campaign's [goals](22-goals.md) decide what each one is worth. A funnel that
used to need one campaign copy per step (the
[Transactions ID](../setting-up-prosper202-pro/999-transactions-id.md)
recipe) is one campaign with a goal per step: an opt-in, a sale, an upsell,
each on its own event and with its own payout, added up on the click when
the campaign's payout mode is **Add them up**.

Events reach a click three ways, and all three are evaluated the same way
(one transaction: the event is stored, the click's goals are evaluated, and
what they reach is recorded through the conversion ledger):

| From | How the click is named | `amount` / `revenue` pays a goal valued from the event? |
|---|---|---|
| A pixel or postback with `event=` | the subid, as for a conversion | yes, as it sets a conversion's payout today |
| `POST /api/v3/events` | `click_id` | yes (an API key is the operator's own statement) |
| `p202.track()` on a landing page | the page's visitor id (never a click id) | no: recorded and reported, never paid |

## Pixels and postbacks: `event=`

Every conversion endpoint takes an event: the global postback (`gpb.php`),
the global pixel (`gpx.php`), the universal pixel (`upx.php`), and the
per-campaign postback and pixel (`pb.php`, `px.php`).

```
https://track.example.com/tracking202/static/gpb.php?subid=[[subid]]&event=sale&amount=49.00&txid=ORD-1001
https://track.example.com/tracking202/static/gpb.php?subid=[[subid]]&event=upsell&event_props={"sku":"B-2"}
```

| Parameter | |
|---|---|
| `event` | The event name: 1–64 of letters, digits and `_ . : -` |
| `event_id` | Optional: your id for the event, 1–128 printable characters, not starting with `@` |
| `event_props` | Optional: a JSON object of properties (strings, numbers, booleans; at most 32) |
| `amount` | Optional: what the event was worth, a plain decimal (no exponent, at most 5 places) |
| `txid` (or `transaction_id`, `order_id`, …) | Optional: the network's id, kept on the conversion a goal writes |

**Old setups keep working.** A request without `event=` is today's plain
conversion, exactly as before. A request with `event=` on a campaign that
has no live goal is *also* today's plain conversion — same payout rules,
same de-duplication — with the event's name kept on the conversion row
(`event_name`) when it is a valid name. Goals are opt-in per campaign, so a
network that already appends an `event` parameter changes nothing until
the campaign gets a goal.

On a campaign with goals, an `event=` hit is an event, not a conversion:

- **A retry is a duplicate.** The event's id is `event_id` when sent;
  otherwise `@tx:<transaction id>` when the request carries one (the same
  sale retried is one event), and otherwise `@once:<event name>` — the event
  happens once per click, the pixel rule for a request that carries no id.
  To count several events of one name on one click (the third purchase,
  every renewal), send a transaction id or an `event_id`. The time of a pixel
  event is the server's, so a network's retry an hour later is still a
  duplicate; the same id with different content is refused.
- **Order.** Events are evaluated in time order, then arrival, then id. Two
  events in the same second are ordered by id, so a funnel step that must
  follow another should arrive at least a second later or carry ids that
  sort after it.
- **Refusals are by name.** A malformed `event`, `event_id`, `event_props`
  or `amount` is refused and nothing is written: the postbacks (`gpb`, `pb`,
  `upx`) answer `422` with `field_errors`; the image pixels (`gpx`, `px`)
  have already answered with their image, and log the refusal. An event
  that is also a reversal (`status=reversed`) is refused: reverse a sale
  with its transaction id and no event. An event id reused for a different
  event is `409`.
- **The answer** from a postback is JSON: `{"msg": "Event recorded" |
  "Event already recorded", "event_id", "duplicate", "outcomes": [...],
  "notifications": [...]}`.

## `POST /api/v3/events`

Scope area `events` (`events:write`; a `stage` key proposes with
`?staged=1`). Honors `Idempotency-Key`.

```bash
curl -X POST https://your-domain.com/api/v3/events \
  -H "Authorization: Bearer YOUR_API_KEY" -H "Content-Type: application/json" \
  -d '{"click_id": 123, "events": [
        {"event_id": "ORD-1001", "name": "purchase", "revenue": 49, "transaction_id": "ORD-1001",
         "properties": {"plan": "pro"}}
      ]}'
```

| Field | |
|---|---|
| `click_id` | The click, a whole number (`"1e3"` and `1.5` are refused, not cast) |
| `events` | 1–100 events: `event_id` (required), `name` (required), `occurred_at` (unix seconds, default now), `properties`, `revenue`, `transaction_id` |

`received_at` and `revenue_trusted` are the server's and are refused in the
body; any other unknown field is a `422` naming it. `201` when an event was
stored, `200` when every event was a duplicate:

```json
{"data": {
  "click_id": 123, "campaign_id": 7, "accepted": ["ORD-1001"], "duplicates": [],
  "replayed": false, "goals_evaluated": true, "outcomes_written": 1, "outcomes_retired": 0,
  "outcomes": [{"goal_id": 12, "version": 1, "n": 1, "event_id": "ORD-1001", "outcome_id": 40,
                "conversion_id": 911, "payable": true, "amount": "49.00000", "notify": "reached"}],
  "notifications": [{"goal_id": 12, "n": 1, "outcome_id": 40, "kind": "reached", "status": "queued",
                     "queued": 1, "browser_pixels": 0, "browser_skipped": 0}]
}}
```

On a campaign with no live goal the events are stored and evaluated against
nothing (`goals_evaluated: false`, with a `note`); a goal added later
applies to them with `POST /goals/{id}/reevaluation`. An event whose time
sorts before one already stored replays the click (`replayed: true`); an
outcome that moves to it is written as a replacement (`notify:
suppressed`).

The CLIs:

```bash
p202 event send --click-id 123 --name purchase --id ORD-1001 --revenue 49 --props '{"plan":"pro"}'
p202 event send --click-id 123 --file events.json            # a list of events, or {"events": [...]}
bin/p202 event:send --click_id=123 --name=purchase --id=ORD-1001 --revenue=49
```

## `p202.track()` on landing pages

The landing page snippet (`tracking202/static/landing.php`) defines
`p202.track(name, props, options)`:

```html
<script>
  p202.track('signup');
  p202.track('purchase', {plan: 'pro'}, {revenue: 49});
</script>
```

- The click is the one the page's first-party visitor id (`p202lpid`, see
  [visitor identity](../features/visitor-identity.md)) was last seen on:
  the landing page's own pageview click, or — on a page that belongs to one
  campaign — that campaign's newest click for this visitor in the last 30
  days. A click id is never taken from a page: anyone can count to one,
  while the visitor id is 128 random bits in the page's own storage, so a
  page can only report events for its own visitor's clicks.
- **Nothing is sent without a visitor id**: a visitor who refused consent
  (`p202.consent(false)`), or a campaign with identity capture off, is not
  tracked.
- Events called before the pageview is recorded wait for it; calls made
  before the snippet loads can be queued as `window.p202 = {q: [['track',
  'signup']]}`.
- Each call has a random event id (or `options.id`), so it is recorded
  once. It returns a Promise of the event id, or `''` when nothing was sent.
- A revenue from a page is **stored and reported, never paid**: a goal
  valued from the event's amount tracks it with `value_note:
  untrusted_value`. A goal with a fixed value pays as it would anywhere.
- **A thank-you page** on the same site loads the snippet with
  `&p202_beacon=0`, so its own view is not recorded as a click and its
  events go to the visitor's landing page click.

The intake is `POST /tracking202/static/event.php` (form-encoded: `lpip`,
`p202lpid`, `event_id`, `name`, `props`, `revenue`): `202` when stored,
`204` when no click can be tied to the visitor (nothing stored), `422`
naming a bad field, `409` for an event id reused with other content. Any
origin may post; no cookie is read.

## Telling the traffic source

A goal a campaign pays for has a **Tell the traffic source** setting
(`notify_traffic_source`, on by default). When such a goal is reached, the
click's traffic source is sent its pixels, with the same tokens a plain
conversion's postback fills and these as well:

| Token | |
|---|---|
| `[[p202_goal]]` | The goal's name |
| `[[p202_goal_id]]` | Its id |
| `[[p202_goal_value]]` | What the outcome pays on the campaign, e.g. `4.00` |
| `[[payout]]` | The same amount |
| `[[transactionid]]` / `[[t202txid]]` | The event's transaction id, or the conversion's ledger key when it had none |

**Server-to-server postbacks go through the notification outbox**, the one
path for web and app goals (the Android installs guide,
[24-android-installs.md](24-android-installs.md), describes it from the
app side). The request that records the goal queues the postback in the
same transaction as the conversion (`202_notification_pending`), and the
worker — `202-cronjobs/app-installs.php`, and the web cron
`202-cronjobs/index.php` every minute — sends it: a failure is retried with
backoff (1 minute doubling, at most 6 hours) and marked `failed` after 8
attempts, and never undoes the recorded conversion. The response reports
`status: queued` with the number of rows waiting (`queued`). Image, iframe
and script pixels cannot wait for a worker: they are returned to a browser
where one asked (the universal pixel's answer, `status: rendered`), and
counted as `browser_only` elsewhere.

A traffic source is told about an outcome **once**. A duplicate is never
sent again. An outcome that a replay (a late, earlier event) or a
re-evaluation writes in place of an earlier one is announced only if the
earlier one never went out: its queued postback is cancelled and the
replacement's is sent instead. Once one has gone out it cannot be recalled,
so the replacement is not sent (`notify: suppressed`) and a correction is
recorded, unsent, in the outbox (no pixel has a correction URL yet). The
same holds when a late event shifts the count — purchases of $5 and $10
followed by a late $1 that happened first become $1, $5 and $10, and the
$10 purchase, now the third, is not announced a second time.
A goal that is not paid (tracked only) sends nothing.

## Setup › Campaigns

A campaign's page has a **Goals** panel once the campaign is saved: its
goals with what each pays and how often it has been reached, and a form to
add or edit one — a name, the event, and what it is worth (a fixed amount,
the event's amount, or nothing), with the Nth event, repeats, a window from
the click, one condition on a property, a prerequisite goal, a payout
override and the traffic-source setting under **Advanced**. The form writes
through the same code as `POST /goals`, so it refuses what the API refuses,
in the same words. A goal the form cannot show faithfully (a running sum,
several conditions, a window from the install) is listed with a note to
edit it with `p202 goal update`. The campaign's own **When a click converts
more than once** setting (under its Advanced) is what adds a funnel's goals
up.
