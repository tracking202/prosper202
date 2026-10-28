# Offline conversions for Google Ads and Microsoft Advertising

Google Ads and Microsoft Advertising bid on the conversions they see. A
conversion that happens on an affiliate network's page, or after a phone
call, never reaches their tag, so their bidding learns nothing from it.
Prosper202 records those conversions against the click that brought them,
and the click came with the platform's click id (Google's `gclid`, `gbraid`
or `wbraid`, Microsoft's `msclkid`). Setup › Offline Conversions writes them
as the platform's offline conversion import file, which you upload once or
let the platform fetch on a schedule.

## The files

| For | Columns | Times |
| --- | ------- | ----- |
| **Google Ads (Data Manager)**: `format=google` | `Google Click ID`, `GBRAID`, `WBRAID`, `Conversion Name`, `Conversion Time`, `Conversion Value`, `Conversion Currency`, `Order ID`, `Ad User Data`, `Ad Personalization`, headers on the first line | the account's time zone, each with its offset (`2026-10-27T02:22:48-04:00`) |
| **Google Ads (Conversions › Uploads template)**: `format=google_uploads` | a `Parameters:TimeZone=<the account's zone>` line, then the same columns without `GBRAID` and `WBRAID` | the account's time zone, each with its offset (`2026-10-27T02:22:48-0400`) |
| **Microsoft Advertising**: `format=microsoft` | `Parameters:TimeZone=+0000`, then `Conversion Name`, `Conversion Time`, `Conversion Value`, `Conversion Currency`, `Microsoft Click ID`, `Hashed Email Address`, `Hashed Phone Number` (the last two empty) | UTC (`+0000`): Microsoft's time zones are its own names |

Google now recommends its Data Manager for offline conversions, and its
field list has a GBRAID and a WBRAID beside the GCLID, so the Data Manager
file sends every id a click came with. Google's classic Uploads template
documents only the `Google Click ID` column, so its file sends only clicks
with a gclid, and counts the rest as left out. Every time is written with
the offset in force at that conversion's own instant, so a file that spans a
daylight-saving change is right on both sides of it.

Each line's value is the amount the conversion counts for in Prosper202,
net of a partial refund, in the account's currency (which is what the
conversion ledger records every amount in). Microsoft takes three decimals,
so its values are rounded to three. A conversion with no value is sent as 0.

`Order ID` is `P202-` and the conversion's own id. It is the same every time
the conversion is sent, so Google counts a conversion it receives twice once.
Microsoft has no order id column; it counts a conversion once by its click
id, conversion name and conversion time, which are also the same every time.

## Which conversions are sent

Only conversions whose click Prosper202 was told, not guessed. Every
conversion records how its click was found (`match_method`): named by the
postback, the pixel's subid, an API call or an upload (`click_id`), or by a
tracking cookie in the converting browser (`cookie`). Those are sent. A
click found by the landing page's visitor id, or guessed as the latest click
from the visitor's IP address, is not: a guess sent to the platform would
teach its bidding clicks the conversion may not have come from. Conversions
recorded before 1.9.79, which kept no record of how their click was found,
are not sent either.

A conversion is also left out when it no longer counts (deleted, refunded in
full, replaced by a later conversion on its click), when its click came with
no id from the platform, when the click is more than 90 days old (neither
platform imports a conversion that long after its click), when the click is
less than 6 hours old (the platforms may not have processed it yet; the next
fetch sends it), or when it has no conversion name. The page, `GET
/conversions/offline-export/summary` and `p202 conversion offline-export
--summary` count every conversion left out, by reason; the
[API reference](../api/10-conversions.md#offline-conversion-files) lists
them all.

## Conversion names

Each line names the conversion action (Google Ads) or the offline conversion
goal (Microsoft Advertising) it counts toward, and the platform matches the
name exactly, capitalisation included. Create the action or goal in the
platform first (Google: an *Import* conversion action, *Track conversions
from clicks*; Microsoft: an *Offline conversions* goal, which needs two hours
before it takes uploads), then type its name on the page: one for the account,
which every campaign without its own name uses, and one per campaign where
campaigns count toward different actions. A campaign with neither has its
conversions left out.

## Consent (Google)

Google asks for the `Ad User Data` and `Ad Personalization` columns with
traffic from the European Economic Area. Prosper202 does not record a
visitor's consent, so it never infers one: a file states what you choose for
it, `Granted` or `Denied` on every line, or leaves the columns empty. State
what your consent banner collected for the traffic the file covers.

## Uploading a file once

Download the file on the page (or `p202 conversion offline-export --format
google --output google-ads.csv`), then:

- **Google Ads**: Goals › Conversions › Uploads, upload the file. For the
  Data Manager file, use Data Manager's file source and map each column to its
  field (Google Click ID to GCLID, GBRAID, WBRAID, Conversion Time to the
  conversion date and time, Conversion Value, Order ID).
- **Microsoft Advertising**: Conversions › Offline conversions › Uploads ›
  Upload.

A download covers the last 90 days unless you choose fewer.

## Letting the platform fetch it

A fetch URL serves the file to the platform on its schedule, so the upload
runs itself. Make one on the page for each platform (and, where the platform
wants one file per conversion action, one per action: *Only conversions
named* under Advanced). The URL is shown **once**, when it is made: copy it
then. Prosper202 keeps only a fingerprint of its key, so neither the page nor
the database can show it again; a lost URL is replaced, which ends the old
one at once, and deleting the URL ends it too. A URL stops answering when its
account is deactivated or its role no longer reads campaign data.

- **Google Ads**: Goals › Conversions › Uploads › Schedules, a new schedule
  from HTTPS, or Data Manager's HTTPS source for the Data Manager file.
  Google fetches only `https://` URLs, so the install's tracking domain needs
  HTTPS.
- **Microsoft Advertising**: Conversions › Offline conversions › Schedules ›
  Schedule, HTTPS.

Both ask for a user name and a password beside the URL. The URL carries its
own key, and Prosper202 reads neither field, so enter anything.

Schedule a daily fetch. A URL sends the last 7 days by default (2 to 90):
every fetch sends the window again, and the platforms count a conversion they
already have once, so a window longer than the time between fetches loses
nothing when a fetch fails, and a conversion held for its click's first six
hours is still in it the next day. The page shows when each URL was last
fetched and how many conversions it sent.

## What a fetch does not do

A conversion the platform already has stays there: a refund, a deletion or
a later conversion that replaces it on its click is not sent as a change.
Retract or restate it in the platform (Google's and Microsoft's conversion
adjustments).

## The platforms' documentation

The files follow these pages, read in October 2026:

- Google Ads: [Import conversions from ad clicks into Google Ads using files](https://support.google.com/google-ads/answer/7014069),
  [Guidelines for importing offline conversions](https://support.google.com/google-ads/answer/15081888),
  [Data Manager: prepare your data for import](https://support.google.com/google-ads-data-manager/answer/14184381),
  [Data Manager: HTTPS data source](https://support.google.com/google-ads/answer/14605515),
  [Set up offline conversions using GBRAID](https://support.google.com/google-ads/answer/17371255).
- Microsoft Advertising: [Tracking offline conversions](https://learn.microsoft.com/en-us/advertising/msa-help/hlp_ba_conc_uetv2offlineconversion)
  and its CSV template.
