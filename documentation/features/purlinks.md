# PurLinks

A PurLink sends a landing page visitor straight to the offer, with no redirect
through your tracker, and still records the click-out and credits the
conversion. It is your campaign URL itself, used as the link on your page: the
landing page code fills in `[[subid]]` and the other tokens there.

PurLinks were a paid 1.9.56 feature and came back in 1.9.80. Pages built for
1.9.56 keep working; see [Upgrading from 1.9.56](#upgrading-from-1956).

## Setting one up

1. Put the landing page code from **Setup › Get LP Code** on your page, as for
   any landing page. PurLinks are on for every landing page.
2. Use the **PurLink** the same screen shows as the link to your offer:
   - **Simple page:** the campaign URL, as entered on the campaign.
   - **Advanced page:** each campaign offer's URL with `acip=<offer id>`
     added, which tells the tracker which offer the visitor chose. A
     redirector has no PurLink: use its outbound link.
3. Make sure the campaign URL has `[[subid]]` where your network takes its
   SubID (`https://offer.example/?sub=[[subid]]`). The setup screen warns when
   it does not: without it, a conversion cannot be matched to the click.
4. Send conversions with the network's postback to `gpb.php` or `pb.php` as
   usual, passing back the SubID.

The REST API (`GET /landing-pages/{id}/code`) and `p202 landing-page code`
return the same PurLinks as `purlink`.

## What the visitor gets

- Until the visitor's SubID is ready (a moment after the page loads) a PurLink
  points at the tracker's ordinary redirect (`go.php`), so a very fast click is
  still tracked. Once it is ready, the link points straight at the offer.
- Clicking, a middle-click, and open-in-new-tab all record the click-out. A
  right-click that only opens the menu does not.
- The click-out is sent in the background (`fetch` with `keepalive`, or
  `sendBeacon`) and never holds up the visitor; it is retried up to three
  times. On an advanced page the last offer the visitor chose wins.

## Tokens

These are filled in on the page, URL-encoded, in links, form actions and form
fields:

| Token | Value |
| ----- | ----- |
| `[[subid]]` | The SubID for this visitor and offer (below) |
| `[[t202kw]]`, `[[c1]]`–`[[c4]]` | The values the visitor arrived with |
| `[[utm_source]]`, `[[utm_medium]]`, `[[utm_campaign]]`, `[[utm_term]]`, `[[utm_content]]` | The same |
| `[[gclid]]`, `[[gbraid]]`, `[[wbraid]]`, `[[ttclid]]`, `[[msclkid]]`, `[[fbclid]]`, `[[t202pubid]]` | From the landing page's URL |
| `[[referer]]` / `[[referrer]]` | The page's referrer |
| `[[random]]` | A random number, one per pageview |

A link with tokens but no `[[subid]]` goes straight to its destination at once,
with the tokens filled in. Links into the tracker itself are left alone.

## Forms

A form whose `action` is a PurLink works too:

- **GET:** the URL's query becomes hidden fields (a field the form already has
  keeps its own value), and tokens in field values are filled in.
- **POST:** the action keeps its query string, with the tokens filled in.

Forms and links added to the page later (by a script, a popup, a page
builder) are found and updated as they appear.

## Changing links from a script

- `updatePurLink()` updates every PurLink on the page again, as in 1.9.56.
- A link's raw template can be set in `data-t202rawlink`; the link's `href` is
  then rendered from it.
- `data-p202-purlink` marks a link as a PurLink even without tokens.
- Values in a 1.9.56 page's `dcs.t202DataObj` (`t202Kw`, `t202C1` …) are used
  for their tokens.

## The SubID

The SubID a PurLink carries looks like `1234567-3121-a1b2c3d4e5f6`: the
click, the offer, and a signature, about 25 characters of digits, letters
and hyphens, so networks that limit SubID length or characters accept it. The
signature means nobody can write a SubID that moves a click's conversion to
another offer. It does not expire while the click exists. Everything that
takes a SubID back accepts it and credits its offer: `gpb.php`, `pb.php`,
`gpx.php`, `upx.php`, the ClickBank, JVZoo, PayKickstart, Zaxaa and Shopify
notifications, revenue uploads and Update Subids. `pb.php` also checks that it
names the postback's own campaign. A SubID whose signature does not match
records nothing.

## The deferred pixel

Some traffic source pixels have to fire on your own page, for example one that
reads a cookie of your site, but with a PurLink the visitor never comes back
through your tracker. The deferred pixel fires them on the landing page.

Use the **With the deferred pixel** version of the landing page code (it adds
`p202_defpixel=1`). Then:

1. A PurLink opens the offer in a new tab, so the landing page stays open.
   A link with its own `target` keeps it.
2. The landing page asks the tracker every 30 seconds (every minute after half
   an hour) whether the click has converted.
3. When the click converts, the page fires the traffic source's browser
   pixels (image, iframe, script and raw code types) with that conversion's
   `[[payout]]` and `[[transactionid]]`. Any conversion counts, as in 1.9.56:
   a network's postback, a ClickBank, JVZoo, PayKickstart or Zaxaa
   notification, the API or an upload.

Each conversion fires once. A conversion recorded by a browser pixel (on the
offer's thank-you page) already fired its pixels where it happened and does
not fire again, nor does a goal outcome of the click itself, which a browser
may have reported; a reversed or
replaced conversion does not fire; server-to-server postbacks are never sent
again. The landing page can ask for a day after the visit.

## Privacy

PurLinks follow the landing page's consent: a visitor who refused tracking, or
withdraws it with `p202.consent(false)`, gets no SubID, sends no click-out and
fires no deferred pixel. The links fall back to the tracker's redirect, which
applies its own rules. A deferred-pixel request that refuses consent fires
nothing and leaves the conversion for a later request that consents.

The account's privacy setting (Settings › Privacy) does not stop PurLinks: a
PurLink stores nothing in the visitor's browser and records what the tracker's
redirect would. It does stop the deferred pixel, which fires third-party
pixels: a visitor the setting holds back (under `eu`, anyone who may be in
Europe) gets no deferred pixel. The page's proofs travel in request bodies, never in a
URL, a cookie or a referrer, and the requests send no cookies.

## Limits

- A copied PurLink carries the visitor's SubID: anyone who opens it is
  credited to that click, as with a copied redirect link.
- On an advanced page the visitor's choice is the route: the signature says
  the SubID was issued by this install for this click and one of the
  account's offers, not which offer the network's sale was for. `pb.php`
  checks the offer against its `acip`; `gpb.php` has no offer to check.
- A browser or extension that blocks the background request loses the
  click-out record; the conversion is still credited through the SubID.
- Browser conversion pixels on the offer's thank-you page (`gpx.php`,
  `px.php`, `upx.php`) rely on the tracker's cookie, which a PurLink visit
  does not set as a first party. Use the network's server postback.
- A snippet can opt out with `p202_purlink=0` in the `landing.php` URL, or
  in a 1.9.56 `landing.js` URL; its PurLinks then go through the tracker's
  redirect. Any value other than `1` (`off`, `false`, `no`) also opts out.

## Upgrading from 1.9.56

- Pages carrying the 1.9.56 snippet keep working, including `defpixel=1`.
- 1.9.56 SubIDs (`<click>-<offer>`) that networks send back after the upgrade
  are still accepted, for a click already on that offer.
- The 1.9.80 upgrade creates the tables PurLinks need and carries over the
  PurLink clicks from the 90 days before it that had not converted, so a
  postback that arrives afterwards still records its sale. Nothing else needs
  migrating.
