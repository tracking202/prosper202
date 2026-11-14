# ChatGPT Ads: from your first ad to conversions and real cost

This page walks through running ChatGPT ads with Prosper202, from the
first link to conversions that reach OpenAI and reports whose cost is what
OpenAI billed. Each step names the page in Prosper202, the command that does
the same, and the reference page with the details.

Before you start, you need:

- a ChatGPT Ads account in Ads Manager (ads.openai.com);
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
The mark matters for cost: ChatGPT Ads spend goes only to clicks of marked
sources (step 5).

From a script: `p202 ppc-network add-chatgpt-ads`, or `POST
/api/v3/ppc-networks/chatgpt-ads`
([details](../api/04-ppc-networks.md#add-chatgpt-ads)).

## 2. Make a link and put it in the ad

Make a tracker on the ChatGPT Ads traffic source account, as for any other
source, and open its link on **Get Links**. Under **ChatGPT Ads** it shows
two things to copy into Ads Manager:

- the **destination URL**, which goes in the ad;
- the **tracking template**, which goes in the ad's tracking settings.

ChatGPT appends the template to the URL and fills in the campaign, the ad and
OpenAI's click id, `oppref`, which is what a conversion sent back to OpenAI is
credited by. A tracker with a landing page sends the visitor straight to the
page. Any other tracker's destination is its tracking link. Ads Manager takes
a template of up to 2,048 characters, and Get Links warns when it is longer.

If you connect the ad account (step 3), you don't need to paste the template
into each campaign: **Setup › Destinations › ChatGPT Ads › campaigns** has a
*push template* action for each campaign.

From a script: `p202 tracker get-url <id>` answers `chatgpt_ads`, with the
destination URL and the template
([details](../api/06-trackers.md#get-tracking-url)), and `p202 ad-campaign
set-template <connection-id> <campaign-id>` pushes it.

Every click from a ChatGPT ad keeps its `oppref`. Put `[[oppref]]` in an
offer URL to pass it on, and a postback that sends it back finds its click
([tokens](../tutorials-and-guides/999-prosper202-tokens.md)).

## 3. Connect the ad account and send conversions to OpenAI

On **Setup › Destinations**, under **ChatGPT Ads** (Advertiser API
connection):

1. Paste the **Advertiser API key**, choose the ChatGPT Ads **traffic source
   account** from step 1, and press **Connect**. Prosper202 checks the key with
   OpenAI and shows the ad account's name, currency and time zone.
2. Press **Run setup**. It finds or makes your web pixel, makes a Conversions
   API key, adds a ChatGPT Ads destination with both, and makes an event
   setting for each event type in your ad account. Each step says what it did.
   Where OpenAI has pixel or key creation switched off for your account, setup
   says so: make them in Ads Manager and give them under Advanced.
3. Test the destination. *Send test* sends a made-up event that OpenAI checks
   and doesn't save. *Send a real test event* sends one that OpenAI saves and
   counts, then reads the account's recent events and says whether it arrived
   from this server.

The keys are stored encrypted and never shown again. If OpenAI later refuses
the stored key, or you rotate it, **Replace key** takes a new one for the same
ad account.

From a script:

```bash
p202 ad-connection create --api-key-file ads-key.txt --ppc-account-id 4
p202 ad-connection setup 1
p202 destination test 3            # --real for an event OpenAI counts
```

([Ad connections API](../api/28-ad-connections.md))

**What is sent.** Each conversion goes once to OpenAI's Conversions API, as an
`order_created` event unless you pick another event type, with the click's
`oppref`, the landing page and the payout. By default only conversions whose
click came from a ChatGPT ad (it carries an `oppref`) are sent. To let OpenAI
credit people who saw an ad without clicking it, set the destination's
**scope** to every conversion (`p202 destination update <id> --scope all`).
[Destinations › ChatGPT Ads](destinations.md#chatgpt-ads) lists every field and
answer.

**What OpenAI doesn't take**, so plan for it:

- **No refunds or changes.** OpenAI keeps the first event it gets for a
  conversion. A refund or a corrected amount doesn't reach ChatGPT Ads.
- **7 days.** A conversion more than 7 days old isn't taken, so a backfill
  covers at most the last 7 days.
- **Consent.** A visitor who refused consent has none of that click's
  conversions sent ([visitor identity](visitor-identity.md)).

The delivery log on the destination shows each conversion sent, withheld or
failed, with the reason, and *resend* sends failed ones again after you fix the
cause.

## 4. If your pixel is also on the page

If the ChatGPT Ads pixel on your site reports the same sale, give the pixel's
event the same event name, and as its `event_id` the id Prosper202 sends (the
conversion's transaction id, else `P202-<conversion id>`). Otherwise OpenAI
counts the sale twice.

## 5. Make cost what OpenAI billed

Until now each click costs the CPC typed on its tracker. To use what OpenAI
billed, turn on the spend pull: **Setup › Traffic Sources › ChatGPT Ads spend
› turn on**. Every hour, Prosper202 then reads what OpenAI billed per ad per
hour into the spend ledger, and the first run reads back about 30 days. The
card shows the last run, how far the spend reaches, and any error.

Reports then cost each click that carries an ad at its share of that ad's
spend for the hour, and say how far the spend data reaches. Clicks after that
point keep their CPC. On an account with a marked ChatGPT Ads source, the
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

**Setup › Destinations › ChatGPT Ads › campaigns** lists each campaign as
OpenAI has it now: its status, serving issues, daily budget, yesterday's
spend, its tracking template and event settings. Pause and activate change the
campaign at OpenAI right away, after a confirm. `p202 ad-campaign pause
<connection-id> <campaign-id> --dry-run` shows what would change first.

A [money-loss alert](money-loss-alerts.md) for spend without conversions names
the ChatGPT Ads campaigns the clicks came from, and the alert on Home has a
button to pause each one.

## Without an Advertiser API key

You can still track clicks (steps 1, 2 and 4) and send conversions:

1. In Ads Manager › Conversions, find your web pixel's **pixel id** and create
   a **Conversions API key**.
2. On **Setup › Destinations › Add ChatGPT Ads**, give both and save.

```bash
p202 destination create --type openai_ads --name "ChatGPT Ads" --pixel-id px_4821 \
    --conversions-api-key-file key.txt
```

What you don't get without the connection: setup done for you, the spend pull
(cost stays at each tracker's CPC), campaign pause and activate, and the
template push.

## Asking an AI assistant

An AI assistant connected through `p202 mcp` can check the connected ad
account, read the destination's delivery log and send it a test event, list,
pause and activate campaigns, and read spend and the spend sync's status. Ask
for a pause or an activate as a dry run first: it changes the campaign at
OpenAI at once. Connecting the ad account, running setup and sending a real
test event are done on the page or with the CLI commands above. The hosted
Prosper202 connector offers the campaign tools and the connection list only.
[The Go CLI](../cli/10-go-cli.md) lists the tools.
