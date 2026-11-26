# ChatGPT Ads: from your first ad to conversions and real cost

This page walks through running ChatGPT ads with Prosper202, from the
first link to conversions that reach OpenAI and reports whose cost is what
OpenAI billed. Where a step has them, it names the page in Prosper202, the
command that does the same, and the reference page with the details.

Before you start, you need:

- a ChatGPT Ads account in OpenAI's Ads Manager;
- for the quick way, its **Advertiser API key** (Ads Manager › Settings).
  Without it you can still track clicks and send conversions by hand (see
  [Without an Advertiser API key](#without-an-advertiser-api-key)), but you
  can't pull spend or manage campaigns from here.

## 1. Add ChatGPT Ads as a traffic source

On **Setup › Traffic Sources**, press **Add ChatGPT Ads**. It makes the
traffic source *ChatGPT Ads*, an account on it, and two custom variables,
`campaign_id={campaign_id}` and `ad_id={ad_id}`, which Ads Manager fills in on
every click. Pressing it again adds only what is missing.

If you already set up a source named ChatGPT Ads yourself, pressing the button
marks that one instead of adding a second (when it is the only live source of
that name). You can also mark any source on its edit form, under Advanced.
The mark matters for cost: on an account that has a marked source, ChatGPT
Ads spend goes only to the marked sources' clicks; with none marked, an ad's
spend goes to the clicks that carry its ad id, on any source (step 5).

From a script: `p202 ppc-network add-chatgpt-ads`, or `POST
/api/v3/ppc-networks/chatgpt-ads`
([details](../api/04-ppc-networks.md#add-chatgpt-ads)).

## 2. Make a link and put it in the ad

Make a tracker on the ChatGPT Ads traffic source account, as for any other
source, and open its link on **Get Links**. Under **ChatGPT Ads** it shows
two things to copy into Ads Manager:

- the **destination URL**, which goes in the ad;
- the **tracking template**, which goes in the ad's tracking settings.

ChatGPT appends the template to the URL and fills in the campaign and the ad.
It adds OpenAI's click id, `oppref`, to the URL itself: that is what a conversion
sent back to OpenAI is credited by. The template must not set an `oppref`
parameter of its own: OpenAI refuses one that does, and `p202 ad-campaign
set-template --template` (and the API) refuse it before asking OpenAI. A tracker with a landing page whose URL has a host sends the visitor
straight to the page. Any other tracker's destination is its tracking link.
Ads Manager takes a template of up to 2,048 characters, and Get Links warns,
above the destination URL and template it has just made, when it is longer.

If you connect the ad account (step 3), **Setup › Destinations › ChatGPT Ads
› campaigns** has a *push template* action on each campaign whose template is
not already the generic one. It pushes the generic ChatGPT Ads template, not
your tracker's: that is enough for a tracker whose destination is its tracking
link, on a source with no custom variables beyond the two above, with
those two keeping their placeholders (`{campaign_id}`, `{ad_id}`), and with
nothing filled in on the Get Links form. A
landing-page tracker's template also carries its `t202id`, and a source's other
variables add their own parameters (and an edited placeholder changes the
template), so for those paste the Get Links template
into Ads Manager, or push it from a script with `--template`.

From a script: `p202 tracker get-url <id>` answers `chatgpt_ads`, with the
destination URL and the template
([details](../api/06-trackers.md#get-tracking-url)), `p202 ad-campaign
set-template <connection-id> <campaign-id>` pushes the generic template,
and with `--template '<the template get-url gave>'` your tracker's own.

A click that arrives with `oppref` on its URL keeps it (clicks from 1.9.80
on). OpenAI adds `oppref` to the click's URL itself, so no tracking template
carries it. Put `[[oppref]]` in an
offer URL to pass it on, and a server postback that sends it back, with no SubID
filled in, can find its click by it when the clicks carrying it are one account's,
on one campaign and traffic source account, and the latest was in the last 30 days
([tokens](../tutorials-and-guides/999-prosper202-tokens.md)).

## 3. Connect the ad account and send conversions to OpenAI

On **Setup › Destinations**, under **ChatGPT Ads** (Advertiser API
connection):

1. Paste the **Advertiser API key**, choose the ChatGPT Ads **traffic source
   account** from step 1, and press **Connect ChatGPT Ads**. Prosper202 checks the key with
   OpenAI and shows the ad account's name, currency and time zone.
2. Press **Run setup**. It finds or makes your web pixel (with several web
   pixels, that step asks which unless exactly one is named Prosper202) and makes a
   Conversions API key. It adds a ChatGPT Ads destination with both, or takes
   over an enabled one for the same pixel and replaces its key. When none for the pixel is enabled and one is switched off, it is left as it is, and that step asks you to switch it on or delete it,
   then run setup again; the other steps still run. It makes an event setting
   (30-day click window) for each event type you pick under Advanced
   (`--event-type` from the CLI). With none picked, it uses the types sent by a
   destination this connection set up on an earlier run, else `order_created` (standard types only, so the run that takes a
   destination over makes `order_created`). A setting already on the pixel is found rather than made
   again, and a new destination sends the first type picked. Each step says
   what it did.
   Where OpenAI has pixel or key creation switched off for your account, setup
   says so: make them in Ads Manager and give them under Advanced.
3. Test the destination. *Send test* sends a made-up event that OpenAI checks
   and doesn't save. *Send a real test event* sends one that OpenAI saves and
   counts, then reads the account's recent events and says whether it arrived
   from this server. Each one is one more conversion OpenAI counts, so when it
   is not listed yet, or the list could not be read, use *Check again*, which
   only reads the list again and sends nothing. OpenAI's list is a sample of
   its 50 most recent events, so an event missing from it is not lost; after
   15 minutes look for it in Ads Manager › Conversions.

The keys are stored encrypted and never shown again. If OpenAI later refuses
the stored key, or you rotate it, **Replace key** takes a new one for the same
ad account.

From a script:

```bash
p202 ad-connection create --api-key-file ads-key.txt --ppc-account-id 4
p202 ad-connection setup 1
p202 destination test 3            # --real for an event OpenAI counts
p202 destination test 3 --check-sent-at 1794849228   # reads again for that real event, sends nothing
```

([Ad connections API](../api/28-ad-connections.md))

**What is sent.** A conversion that qualifies goes once to OpenAI's
Conversions API, as an
`order_created` event unless you pick another event type, with the click's
`oppref` when the click has one, the landing page and the payout (amount and
currency are left out when the amount is zero). By default only conversions whose
click came from a ChatGPT ad (it carries an `oppref`) are sent. To let OpenAI
credit people who saw an ad without clicking it, set the destination's
**scope** to every conversion (`p202 destination update <id> --scope all`).
[Destinations › ChatGPT Ads](destinations.md#chatgpt-ads) lists every field and
answer.

**What is not sent, or not taken**, so plan for it:

- **Privacy holds.** Under the privacy setting `all`, no visitor's conversion
  is sent. A visitor who is or may be in Europe (the EU, the EEA, the UK or
  elsewhere on the continent), or whose address can't be placed, is held back under every setting: under `eu` (the default for new
  accounts) by the setting, and under `disabled` because ChatGPT Ads has no
  field that states consent to measurement
  ([privacy](destinations.md#privacy-and-personal-data)).
- **Clicks the conversion didn't name.** Only a conversion that named its
  click is sent. One whose click was found another way is withheld: by
  visitor id, by the latest click from the same IP address, or by a method
  not recorded (conversions and goal events stored before 1.9.79, and any other
  that recorded none).
- **Missing details.** A conversion with no usable landing page or tracking
  link (none stored, not http(s), no host, or over 2,048 characters with its query cut off) fails,
  and one with no recorded currency is withheld, even with no amount, until
  a currency is recorded.
- **No refunds or changes.** OpenAI keeps the first event it gets for a
  conversion. A refund or a corrected amount doesn't reach ChatGPT Ads.
- **7 days.** A conversion more than 7 days old isn't taken, so a backfill
  covers at most the last 7 days.
- **Consent.** Once a visitor refuses consent, no later conversion of that
  click is sent to ChatGPT Ads (nor to GA4, Google Ads or an offline file);
  one already sent stays sent, and your reports still count them
  ([visitor identity](visitor-identity.md)).

The delivery log on the destination shows each conversion sent, withheld or
failed, with the reason, and *resend* sends failed ones again after you fix the
cause.

## 4. Put OpenAI's pixel on your landing page

The pixel lets OpenAI tie a conversion to the browser that saw the ad, even
when the click's `oppref` is gone. Prosper202 loads it for you on a simple
landing page and fires its conversion event; you set two things.

1. On **Setup › Traffic Sources**, open your ChatGPT Ads account's
   **Advanced** section and add a pixel of the type **ChatGPT Ads Pixel**.
   Its code is your pixel id from Ads Manager › Conversions (`px_4821`);
   OpenAI's base code or its image tag pasted whole works too, and only the
   id is kept.
2. On **Setup › Landing Pages**, open the page's **ChatGPT Ads pixel**
   section and switch it on. It can't be switched on for an advanced landing
   page.

What then happens:

- **On the page.** For a visit from that traffic source, the landing page
  code loads OpenAI's pixel under your pixel id, on the terms the Bot202
  Facebook Pixel Assistant loads on: the privacy setting in force, a visitor
  who refused consent (`p202_consent`) and the campaign's identity capture
  hold it back, and consent withdrawn on the page turns it off. A visit
  from any other source, or with the switch off, loads nothing of OpenAI's.
- **The browser reference.** Once the pixel sets its `__obref` cookie, the
  page sends it to your Prosper202, which keeps it with the click, and a
  ChatGPT Ads destination sends it as `user.obref` with hashed email and
  phone ([what is sent](destinations.md#privacy-and-personal-data)). When
  the visitor withdraws consent on the page, the pixel is told and the kept
  reference is removed.
- **The conversion.** When a conversion is recorded in the browser (a pixel
  on your thank-you page), the traffic source's ChatGPT Ads Pixel fires
  OpenAI's event under the event type the destination sends for that
  campaign (`order_created` without one) and the id it sends (the
  conversion's transaction id, else `P202-<conversion id>`), with the
  click's `oppref` and the payout. OpenAI counts the pixel's event and the
  destination's once. A postback-recorded conversion fires no browser pixel.
- **A rotator** that sends the visit to your own landing page passes the
  ad's `oppref` on to the page while the pixel is on there.
- **A Content-Security-Policy** on the page must allow OpenAI's hosts:
  `script-src https://bzrcdn.openai.com`,
  `connect-src https://bzr.openai.com https://bzrcdn.openai.com` and
  `img-src https://bzr.openai.com`, plus your Prosper202 domain in
  `connect-src`.

**If you placed OpenAI's pixel on the page yourself**, give its conversion
event the event type the destination sends (`order_created` unless you
picked another; for a `custom` type, its custom event name), and as its
`event_id` the id Prosper202 sends (the conversion's transaction id, else
`P202-<conversion id>`; a conversion that replaced another, as a newer
revenue upload does, goes under the first one's id). Otherwise OpenAI counts
the sale twice. Don't load it twice: with the switch on, remove your own.

## 5. Make cost what OpenAI billed

Until now each click costs the CPC typed on its tracker. To use what OpenAI
billed, turn on the spend pull: **Setup › Traffic Sources › ChatGPT Ads spend
› turn on** (the card is there once the ad account is connected). Each hour
the install's cron job (or AutoCron) runs, and Prosper202 reads what OpenAI
billed per ad per hour into the spend ledger; the first runs read back about
30 days. After a rate limit or a refused key it waits longer before trying
again (doubling from an hour to a day, or as long as OpenAI asks, up to a week), and the card says why. Without a cron job it still runs once an hour while someone is signed
in and loading pages; a cron job or AutoCron keeps it going when nobody is on
the site. `p202 spend sync run` runs it now, once the sync is turned on. The card shows the last run, how far the spend reaches, and
any error.

Reports then cost each click that carries an ad at its share of that ad's
spend for the hour, and say how far the spend data reaches. A click whose ad
has no spend recorded for its hour, and clicks after that point, keep their
CPC. On an account with a marked ChatGPT Ads source, the
spend goes only to that source's clicks. Spend in hours no click carried the
ad is shown as unallocated.

The ad account must bill in the same currency as your Prosper202 account:
spend in another currency is refused, not converted.

From a script:

```bash
p202 spend sync enable 1
p202 spend sync run 1                 # now, rather than at the next hour
p202 spend list --period yesterday    # what each ad cost, and the clicks that carried it
p202 spend conversions --period last7 # OpenAI's conversions beside yours
```

([Spend ledger API](../api/29-spend-ledger.md),
[Cost from the spend ledger](../api/11-reports.md#cost-from-the-spend-ledger))

## 6. Run the campaigns

**Setup › Destinations › ChatGPT Ads › campaigns** lists the campaigns as
OpenAI has them now, the first page of them (the view says when OpenAI has
more, and `p202 ad-campaign list --after` reads on): each one's status, serving issues, daily budget, yesterday's spend,
tracking template and event settings. *Pause* is offered on an active campaign
and *Activate* on a paused one that has not ended; each changes the campaign at
OpenAI right away, after a confirm. `p202 ad-campaign pause
<connection-id> <campaign-id> --dry-run` shows what would change first.

A [money-loss alert](money-loss-alerts.md) for spend without conversions (a
campaign whose cost today reaches your daily cost cap with no conversion today)
names up to three ChatGPT Ads campaigns the clicks came from, when those clicks
carry a `campaign_id` that looks like OpenAI's (the tracking template was pasted into the ad) and were on a
traffic source account linked to the ad connection. The alert on Home
then has a pause button for each: it shows what pausing changes, and pauses
only when you confirm.

## Without an Advertiser API key

You can still track clicks (steps 1 and 2), keep a pixel on the page from
counting a sale twice (step 4), and send conversions:

1. In Ads Manager › Conversions, find your web pixel's **pixel id** and create
   a **Conversions API key**.
2. On **Setup › Destinations › Add ChatGPT Ads**, give both and save.

```bash
p202 destination create --type openai_ads --name "ChatGPT Ads" --pixel-id px_4821 \
    --conversions-api-key-file key.txt
```

What you don't get without the connection: setup done for you, the real test
event, the spend pull (cost stays at each tracker's CPC unless you import spend
with `p202 spend import`), campaign pause and activate (on the campaigns view
and on a money-loss alert), and the template push.

## Asking an AI assistant

An AI assistant connected through `p202 mcp` can check the connected ad
account, read the destination's delivery log and send it a test event, list,
pause and activate campaigns, and read spend and the spend sync's status. Ask
for a pause or an activate as a dry run first: it changes the campaign at
OpenAI at once. Connecting the ad account, running setup and sending a real
test event are done on the page or with the CLI commands above. Of the ad account tools, the
hosted Prosper202 connector offers the campaign tools and the connection list
only.
[The Go CLI](../cli/10-go-cli.md) lists the tools.
