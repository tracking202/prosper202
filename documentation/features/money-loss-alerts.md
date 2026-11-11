# Money-loss alerts

A postback or pixel that breaks quietly is the costliest failure in paid
traffic: the campaign keeps buying clicks, and the missing conversions are
found only at the next look. Money-loss alerts watch for it.

They are **on for every account by default**, including accounts that
existed before the upgrade to 1.9.80. The cron job's hourly run checks each
account's campaigns. Turn them off, or change when and where they are sent,
under **Account › Personal settings › Money-loss alerts**. An account that
turns them off stays off through later upgrades.

## What is alerted

**Conversions stopped.** This alert watches conversions as they arrive:
postbacks, pixels and the API. Conversions recorded by an upload (Update
Subids, revenue and conversion uploads) and the values carried over from
before 1.9.79 are left out, because a batch arrives in one second and says
nothing about when its conversions happened. Arrivals are counted in hours,
so a burst within one hour counts once.

A campaign is *steady* when its conversions arrived in at least the minimum
number of different hours in the last 7 days (10 by default). Its usual gap
is the time from its first to its last converting hour in those days, over the
gaps between them. Its usual clicks per converting hour are its real clicks
over those hours. It is alerted when all of these hold:

- no conversion for the multiple you set (5 by default) times its usual gap,
  and at least an hour;
- at least that multiple times its usual clicks per converting hour arrived
  since its last conversion (real clicks: the click filter's are left out);
- a real click in the last two hours, so a paused campaign is not alerted.

The campaign's next conversion ends the incident; if it goes quiet again, it
is alerted again.

**Spend with no conversions.** With a daily cost cap set (0 is no cap), a
campaign whose cost today reaches the cap with no conversion today is alerted,
at most once a day. Any conversion recorded today counts, an upload included.
"Today" is your account's day in its time zone, and cost
is every click's, filtered clicks included, since each was paid for.

**Postback refusals jumped.** This alert watches the [inbound
log](../api/00-api-integrations.md) (Setup › Inbound Log): every postback and
pixel the install refused for the account, with why. It is alerted when the
last hour holds at least 10 refusals and at least 5 times the account's
average refusals per hour over the 24 hours before: a network that changed
its postback format, a rotated token or signature secret, or a campaign
deleted under a live offer. The message names the hour's most frequent
refusal reasons. An account refused at the same rate all day is not alerted
(its log shows it). The alert is about the account rather than a campaign,
and is sent at most once a day while the jump lasts. Requests the install
could not attribute to an account are not counted.
The alert is suppressed when the log's 5,000-row retention limit may have
removed part of the 24-hour baseline; check the inbound log directly then.
Successful retries and test requests share that limit. Incidents are keyed to
the UTC day, so a continuing spike can be reported again after midnight UTC.

Conversions are counted by when they arrived. A reversal does not count as
one. Each alert is sent once per incident.

## Where alerts go

Each channel uses your own account's settings. By default all three are on,
and each sends only where it has somewhere to go:

- **Email**: your account's email address, sent through the server's
  `mail()`. If the server cannot send email, the failure is logged.
- **Slack**: only if you have set an incoming webhook under Account › API
  integrations.
- **Webhooks**: your account webhooks (`p202 ltv webhooks create`, or
  `POST /ltv/webhooks`) subscribed to `alert.conversions_stopped`,
  `alert.spend_without_conversions`, `alert.postback_refusals_spiked`, or
  every event (`*`). They are signed and
  retried as every webhook event is; see [the payloads](../api/20-ltv.md).

An account with no email address, no Slack webhook and no subscribed webhook
is sent nothing; its alerts are still listed in the app.

## In the app

Every alert is also listed on **Home** (the last 7 days) and on the settings
card (the last 30 days) from the moment it is raised, whatever became of its
message: when it was first seen, whether the problem is still going on (the
last hourly run still found it), a link to the page that shows the problem,
and how it was sent, or why each channel did not take it. A server whose
`mail()` accepts a message and never delivers it cannot hide an alert there.
The list keeps an alert for 90 days after its problem was last seen.

Each message carries the same link when the install's address is known: the
Inbound Log for conversions that stopped and for refused postbacks, the
campaign for spend with no conversions. Webhook payloads carry it as `url`.

## Send a test alert

**Send a test alert** on the settings card sends a harmless alert at once to
every place your saved settings switch on, the way a real one is sent, and
the page then says what each did: Slack took it or why it did not, whether
the server's mail system accepted the email (and what to do when it does not
arrive), and whether a webhook was queued. Webhooks receive it as the event
`alert.test`, on every webhook subscribed to any of the alerts or to every
event. A test is not listed as an alert.

Every email and Slack message ends with a line saying how to turn the alerts
off. The line links to the settings page when the install's address is known:
user 1's tracking domain is set and the cron job is fetched through the web
server. A command-line cron run cannot know the install's path, so the
message names the page without a link.

If every channel tried for an alert fails (Slack answered an error, `mail()`
refused it, the webhook could not be queued), the alert is tried again the
next hour, at most three times per incident. Failures are written to the PHP
error log, and the alert's row in the app says which channel failed and why.
