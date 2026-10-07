# Setup API: landing-page code, pixels and postbacks, traffic-source variables and pixels

What the UI's Setup section hands out and edits beyond its CRUD records, so an
agent can finish a landing-page tracker or a conversion-tracking setup without
the web UI:

| Method | Path | The Setup page |
| ------ | ---- | -------------- |
| `GET` | `/landing-pages/{id}/code` | Get LP Code (simple and advanced) |
| `GET` | `/conversions/postback-code` | Postback / Pixel |
| `GET` | `/ppc-networks/{id}/variables` | Traffic Sources › variables |
| `POST` | `/ppc-networks/{id}/variables` | |
| `PUT` | `/ppc-networks/{id}/variables/{variableId}` | |
| `DELETE` | `/ppc-networks/{id}/variables/{variableId}` | |
| `GET` | `/ppc-accounts/{id}/pixels` | Traffic Sources › account › Advanced (pixels) |
| `POST` | `/ppc-accounts/{id}/pixels` | |
| `PUT` | `/ppc-accounts/{id}/pixels/{pixelId}` | |
| `DELETE` | `/ppc-accounts/{id}/pixels/{pixelId}` | |

Every route asks for what the Setup pages ask for: the role permission
`access_to_setup_section` (Super user, Admin and Campaign manager have it; a
Campaign optimizer or viewer is answered `403` naming it), plus the path's
scope (`landing-pages:read`, `conversions:read`, `ppc-networks:read|write`,
`ppc-accounts:read|write`). Records are the caller's own: another account's
landing page, campaign, redirector, traffic source or account is a `404` (or,
inside `offers` or `campaign_id`, a `422` naming it). The capabilities report
`features.setup_section`.

The writes take an `Idempotency-Key`, can be staged with `?staged=1` (applied
through the real route, permission included), and the deletes preview with
`?dry_run=1`. Unknown fields and query parameters are refused by name.

## Landing-page code — `GET /landing-pages/{id}/code`

Built by `Prosper202\Setup\LandingPageCode`, the class Get LP Code builds its
code with, so the strings are the page's byte for byte (a test holds them to
what the page served before the class existed). Every snippet starts from
`base_url`: the tracker link's base (user 1's tracking domain, or this
server's name and port when none is set, plus the install directory) written
scheme-relative, `//track.example.com/`, as the page writes it — the code then
works on an http or an https landing page.

**A simple page** (`landing_page_type` 0) links out to its own campaign:

| Field | What it is |
| ----- | ---------- |
| `loader` | The script to put right above `</body>` of **only** the page visitors first arrive on |
| `outbound_link` | Option 1: the link to the offer (`go.php?lpip=`) |
| `outbound_php` | Option 2: a PHP redirect page to save on your site and link to (cloaks the affiliate link) |
| `outbound_javascript` | Option 3: a JavaScript redirect page (lets other tags fire first) |

Its campaign must be live, as the page only lists those (`422` on
`aff_campaign_id` otherwise); `offers` is refused.

**An advanced page** (`landing_page_type` 1) links out to each offer named in
`offers`, in order: `campaign:<aff_campaign_id>` (via `go.php?acip=` and
`off.php`) or `rotator:<rotator id>` (via `go.php?rpi=` and `offrtr.php`),
comma separated, at most 100. Each comes back in `offers[]` with `position`,
`type`, `id`, `public_id`, `name`, `outbound_link` and `outbound_php`.

```bash
curl -H "Authorization: Bearer $KEY" \
  "https://your-domain.com/api/v3/landing-pages/14/code?offers=campaign:3,rotator:2"
```

Refused, as the page refuses: an advanced page with no offer (`422`, "Please
select an affiliate campaign or rotator"); an offer that is not yours or was
removed (`422` on `offers[N]`, "Offer N: that campaign is not yours, or it was
removed."). Beyond the page: a malformed offer (`campaign:1e3`, `lp:2`,
spaces) is refused by position, never skipped. A landing page or campaign
without a public id (made through the API before ids were assigned) is given
one first.

`segments` lists the elements the loader fills on the page (Dynamic Content
Segments), e.g. `<span name="t202Country" t202Default="Your Country">Your
Country</span>`.

## Pixels and postback URLs — `GET /conversions/postback-code`

What Postback / Pixel shows, built by `Prosper202\Setup\PostbackCode` (the
page's own script writes the same strings; a test runs it and compares):

| Field | What it is |
| ----- | ---------- |
| `simple.pixel` / `simple.postback_url` | The image pixel (gpx.php) and the server-to-server postback (gpb.php) |
| `advanced.pixel` / `advanced.postback_url` | The same with `cid` — the campaign a conversion is recorded under when a visitor clicked several |
| `universal.javascript` / `universal.iframe` | The smart pixel (upx.php), which also fires the account's traffic-source pixels |

| Parameter | Default | Description |
| --------- | ------- | ----------- |
| `amount` | empty (pays the campaign's payout) | A number or the network's payout macro, e.g. `{payout}` |
| `subid` | empty (`subid=` for you to fill) | The network's sub id macro: `{aff_sub}` (HasOffers), `#s2#` (Cake), `xxC1xx` (HitPath), `[=SID=]` (LinkTrust) |
| `campaign_id` | none | The advanced pixel's `cid`: one of your live campaigns |
| `scheme` | the tracking domain's | `http` or `https` |

`amount` and `subid` are written in as given, so what would break a URL, an
HTML attribute or a JavaScript string is refused: whitespace, quotes, `<`, `>`,
`\`, `&` and control characters.

```bash
curl -H "Authorization: Bearer $KEY" \
  "https://your-domain.com/api/v3/conversions/postback-code?subid=%7Baff_sub%7D&amount=%7Bpayout%7D"
```

## A traffic source's custom variables — `/ppc-networks/{id}/variables`

The extra `parameter=placeholder` pairs every tracking link of the source's
accounts carries (`GET /trackers/{id}/url` writes the live ones after
`t202id`). A variable whose parameter is a built-in token (c1–c4, utm_*,
t202ref, t202b, t202kw) sets that token's default instead.

Fields: `name` (shown in reports), `parameter`, `placeholder`. The Setup
dialog's rules hold — the source must be yours and not removed, every field
is required and not blank, a variable is only read or changed within its own
source, and a removed variable is retired (`deleted = 1`: new links stop
carrying it, clicks already recorded keep its values). Beyond the dialog,
what would break every link of the source is refused, because the link
builder writes both into the link as given: more than 255 characters, spaces,
`&`, `#`, `?` or control characters, an `=` in the parameter, and the
parameter `t202id` (the link's own id).

```bash
curl -X POST -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -d '{"name":"Ad id","parameter":"adid","placeholder":"{ad_id}"}' \
  https://your-domain.com/api/v3/ppc-networks/3/variables
```

## An account's pixels — `/ppc-accounts/{id}/pixels`

What the account fires when one of its clicks converts. Fields:

| Field | Description |
| ----- | ----------- |
| `pixel_type_id` | 1 Image, 2 Iframe, 3 Javascript, 4 Postback (server to server), 5 Raw (markup as given), 6 Bot202 Facebook Pixel Assistant (answered with `pixel_type`, the name) |
| `pixel_code` | For every type except Raw, the URL from the pixel's src — several separated by spaces — with tokens such as `[[subid]]`, `[[payout]]`, `[[transactionid]]` filled in when it fires |
| `correction_url` | Postback pixels only: where a correction goes when a conversion it announced is replaced; one URL per code URL, in the same order. `""` removes it |

The account form's rules hold: the account must be yours (and filed under
one of your traffic sources), a pixel is saved only with a type and a code
(trimmed), a correction URL goes on a Postback pixel only and is checked as
the form checks it, a pixel that stops being a Postback keeps no correction
URL, and a removed pixel is erased with its correction URL. Beyond the form:
the type must be one `202_pixel_types` names, and a Postback's URLs must be
http(s) — the only ones the postback sender calls.

```bash
curl -X POST -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -d '{"pixel_type_id":4,"pixel_code":"https://network.example/pb?click=[[subid]]&payout=[[payout]]"}' \
  https://your-domain.com/api/v3/ppc-accounts/4/pixels
```

## From the CLI

```bash
p202 landing-page code 12                                    # simple page
p202 landing-page code 14 --offer campaign:3 --offer rotator:2
p202 conversion postback-url --subid '{aff_sub}' --amount '{payout}'
p202 conversion pixel --type universal
p202 ppc-network variable create 3 --name 'Ad id' --parameter adid --placeholder '{ad_id}'
p202 ppc-account pixel create 4 --type-id 4 --code 'https://network.example/pb?click=[[subid]]'
```

See [the Go CLI guide](../cli/10-go-cli.md#setup-code-and-traffic-source-settings).
