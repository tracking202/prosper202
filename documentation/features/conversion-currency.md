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
keeps each payout as you entered it and shows it converted. A campaign paid
only by the amounts its postbacks send (a payout of 0) stays in its currency
too. So a postback or
pixel that sends its own amount on one of them is read in that earlier
currency and converted into your new one, as for any campaign paid in
another currency. If a network now reports amounts in your new currency,
set the campaign's currency to it under Setup › Campaigns.

### After the upgrade to 1.9.80

Versions 1.9.57 to 1.9.79 recorded a postback's amount as sent on every
campaign; 1.9.80 converts it on a campaign paid in another currency, as paid
1.9.56 did. So that no one is surprised, the upgrade lists those campaigns
on **Account › Personal settings › Account currency**, most of them left so
by an earlier change of your account's currency:

- **Their networks report in … : switch them** makes each one paid in your
  account's currency, keeping the payout it shows; their postback amounts are
  then recorded as sent, as before the upgrade.
- **Keep converting** leaves them as they are.

Either answer clears the notice. You can change any campaign's currency
under Setup › Campaigns at any time.

A campaign paid only by its postbacks' amounts (a payout of 0) from before
1.9.80 cannot be told apart from one an earlier change left in the old
currency, so the upgrade keeps it in your account's currency, as its amounts
were recorded before; set its currency under Setup › Campaigns if its
network reports in another.

## Conversions from before 1.9.80

Before 1.9.80 no conversion kept its currency, and every page showed it in
the account's currency of the moment. The upgrade gives each earlier
conversion a currency where it can tell, and marks it as given by the
upgrade:

- **An account whose currency shows no sign of having changed**: its
  conversions are labelled with the account's currency at the upgrade. The
  only sign the upgrade can read is the one a change leaves on the
  campaigns' payouts, and a change can leave none: every campaign was
  already in the new currency, the campaigns are paid only by the amounts
  their postbacks send (a payout of 0, as a store's are), or the account
  had no campaign. Such an account's earlier conversions are labelled with
  its current currency whichever currency they were in.
- **An account whose currency was changed before the upgrade** (the change
  left its mark on the campaigns' payouts), or that has a campaign paid in
  another currency (a campaign paid in another currency counts even if the
  account never changed currency): Prosper202 cannot tell which conversions
  were recorded under which currency, so it records none for them. They
  show with "currency not recorded", are counted apart in the reports'
  notice, and are left out of offline conversion files and held back from
  destinations.

If you changed your account currency before the upgrade, say when under
**Account › Personal settings › Account currency**: give the date of the
change in **Before** and the currency you counted in before it, and press
**Record**. The conversions recorded before that date that the upgrade
labelled, or left without a currency, take it, and so do their refunds;
what was held back from a destination for want of a currency goes out. A
conversion recorded with its own currency, as every conversion has been
since the upgrade, is never changed, and you can say it again if you got
it wrong. If you changed currency more than once, record each period: open
**Advanced** and give the date it starts in **From**.

From 1.9.80 each change of the account's currency is recorded when it is
made (when, from and to), and Personal settings lists them. That history,
with what you record above and where it begins at the upgrade, decides the
currency of a conversion whose amount is from another time: one that takes
its click's payout (or the click's value, for a repeat conversion) is in the
currency of the click's time, and one dated in the past (a replay from the
Inbound Log, an import) is in the currency of its own time. Where the
history does not reach that far back, the currency is the account's at the
upgrade, and the conversion is among those you can correct by date.

A refund of a sale that has no currency recorded is recorded beside it the
same way, so the two net; when you record the sale's currency, its refunds
take it too.
