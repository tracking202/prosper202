# Money-loss alerts

A postback or pixel that breaks quietly is the costliest failure in paid
traffic: the campaign keeps buying clicks, and the missing conversions are
found only at the next look. Money-loss alerts watch for it.

Turn them on under **Account › Personal settings › Money-loss alerts**. They
are off until you do. The cron job's hourly run then checks each of your
campaigns.

## What is alerted

**Conversions stopped.** A campaign is *steady* when it had at least the
minimum number of conversions in the last 7 days (10 by default). Its usual
gap is the time from its first to its last conversion in those days, over the
gaps between them, and its usual clicks per conversion are its real clicks
over its conversions. It is alerted when all of these hold:

- no conversion for the multiple you set (5 by default) times its usual gap,
  and at least an hour;
- at least that multiple times its usual clicks per conversion arrived since
  its last conversion (real clicks: the click filter's are left out);
- a real click in the last two hours, so a paused campaign is not alerted.

The campaign's next conversion ends the incident; if it goes quiet again, it
is alerted again.

**Spend with no conversions.** With a daily cost cap set (0 is no cap), a
campaign whose cost today reaches the cap with no conversion today is alerted,
at most once a day. "Today" is your account's day in its time zone, and cost
is every click's, filtered clicks included, since each was paid for.

Conversions are counted by when they arrived. A reversal does not count as
one. Each alert is sent once per incident.

## Where alerts go

Choose any of the three; each uses your own account's settings.

- **Slack**: the incoming webhook set under Account › API integrations.
- **Email**: your account's email address, sent through the server's
  `mail()`. A server that cannot send email logs that it did not.
- **Webhooks**: your account webhooks (`p202 ltv webhooks create`, or
  `POST /ltv/webhooks`) subscribed to `alert.conversions_stopped`,
  `alert.spend_without_conversions`, or every event (`*`). They are signed and
  retried as every webhook event is; see [the payloads](../api/20-ltv.md).

An alert that no channel took because each one failed (Slack answered an
error, `mail()` refused it, the webhook could not be queued) is tried again
the next hour. Failures are written to the PHP error log.
