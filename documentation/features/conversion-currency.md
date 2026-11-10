# Currencies: what each conversion is recorded in

Every conversion keeps the currency its amount is in. Prosper202 writes it
on the conversion when the conversion is recorded (from 1.9.80), so a later
change of your account's currency does not relabel what you already earned.

## What a conversion records

| The amount comes from | Recorded in |
| --- | --- |
| The campaign's payout, or the click's own value | Your account's currency. A campaign paid in another currency keeps its payout converted into yours (Setup › Campaigns). |
| A postback or pixel that sends its own amount (`amount=`), on a campaign in your account's currency | Your account's currency. |
| A postback or pixel that sends its own amount, on a campaign **paid in another currency** | The amount is read as that campaign's currency and converted into yours at the kept exchange rate. The conversion keeps both: the converted amount, and the amount and currency that were sent. |
| The API, an upload, a goal | Your account's currency. |
| A payment provider that states every amount in one currency (ClickBank: USD) while your account counts in another | The provider's currency, as sent: a ClickBank sale on an account that counts in euros is recorded, shown and exported as USD. |
| A refund or chargeback | The currency of the sale it takes back. A refund of a converted sale is converted at the rate its sale was, so a full refund takes back exactly what was recorded. |

### The kept exchange rate

Prosper202 never asks for an exchange rate while a postback is being
recorded. The cron job fetches the rate for each pair of currencies your
campaigns need once a day (and at once when you save a campaign paid in
another currency, or change your account's currency). A rate is used for up
to seven days.

The rate comes from Prosper202's own rate service, the one Setup › Campaigns
already uses to convert a payout. The request holds the two currency codes
and a reference amount and nothing else: no key, and nothing of your
install or account. Each request is given 10 seconds and a run 30, so a
service that does not answer cannot hold up the cron job; a pair it has no
answer for keeps the rate it has and is asked for again the next hour.

When no rate is kept for the pair (the cron job has not run, or the rate
service has not answered for a week), the amount is **recorded as sent, in
the campaign's currency**. Nothing is lost and nothing is guessed: the
conversion says which currency it is in, and every report says how much of
the period is in it.

## Where you see it

- **Conversion log** (Analyze › Conversion Logs) and the **click's
  conversions** panel show each amount in its own currency. An amount that
  was converted shows what was sent beside it; one that is not in your
  account's currency says so.
- **Reports** add each click's value, which carries no currency of its own.
  When part of a period's revenue is not in your account's currency, the
  Overview, Group Overview, Breakdown, Day Parting, Week Parting, Rotator
  Breakdown and Analyze reports say so above their figures, with the amount
  and number of conversions in each other currency. The totals below add
  those amounts as recorded; nothing converts them. The figure is your
  account's for the period, whatever else the report is filtered by.
- **Downloads and the chart** are read without the page around them, so
  each says it in itself. Every row of a report download ends with two
  columns, `Currency` (your account's) and `Other currencies in period`
  (for example `USD 12.50 (1 conversion)`; empty when there are none). The
  Group Overview download says the same in its heading, and the Overview
  chart in its subtitle.
- **The API** answers `currency`, `stated_amount` and `stated_currency` on
  each conversion (`GET /conversions`, `GET /clicks/{id}/conversions`), and
  every report answer lists `other_currencies` beside the account's
  `currency`: empty when everything is in one currency.
- **`p202`** prints a note under a report when its totals hold more than one
  currency, and `p202 report summary --profiles` does not add the money of a
  profile whose own revenue is in more than one.
- **Offline conversion files** and **destinations** (GA4, Google Ads) send
  each conversion with the currency it recorded. A conversion with no
  currency recorded is left out of a file (`no_currency`) and not sent to a
  destination, rather than sent under a currency it may not be in. Once you
  record the currency of those conversions (below), what was held back from
  a destination is sent at the next run.
- **Refunds from a payment platform** (Shopify, PayKickstart, JVZoo, Zaxaa)
  are held to the currency of the sale they take back, not to your
  account's currency of the day: a sale recorded in dollars is refunded in
  dollars after your account moved to euros, and the two net. A refund
  stated in any other currency than its sale's is refused.

## Changing your account's currency

Account › Personal settings › Account currency. The change:

- converts your campaigns' payouts into the new currency;
- records new conversions in the new currency;
- **leaves the conversions already recorded as they are**: each keeps the
  currency it was recorded in, and is not converted.

After a change, a report over a period that spans it holds amounts in both
currencies, and says how much of each.

Your campaigns stay paid in the currency they were set up in: the change
keeps each payout as you entered it and shows it converted. So a postback or
pixel that sends its own amount on one of them is read in that earlier
currency and converted into your new one, as for any campaign paid in
another currency. If a network now reports amounts in your new currency,
set the campaign's currency to it under Setup › Campaigns.

## Conversions from before 1.9.80

Before 1.9.80 no conversion kept its currency, and every page showed it in
the account's currency of the moment. The upgrade gives each earlier
conversion a currency where it can tell:

- **An account whose currency was not changed**: its conversions are
  recorded in the account's currency.
- **An account whose currency was changed before the upgrade** (the change
  left its mark on the campaigns' payouts), or that has a campaign paid in
  another currency: Prosper202 cannot tell which conversions were recorded
  under which currency, so it records none for them. They show with
  "currency not recorded", are counted apart in the reports' notice, and
  are left out of offline conversion files.

If you know those earlier conversions were all recorded in one currency,
say so under **Account › Personal settings › Account currency**: choose the
currency and press **Record this currency**. It applies only to conversions
that have none.
