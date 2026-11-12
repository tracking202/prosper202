# Setup API: landing-page code, pixels and postbacks, traffic-source variables and pixels

What the UI's Setup section hands out and edits beyond its CRUD records, so an
agent can finish a landing-page tracker or a conversion-tracking setup without
the web UI:

| Method | Path | The Setup page |
| ------ | ---- | -------------- |
| `GET` | `/landing-pages/{id}/code` | Get LP Code (simple and advanced) |
| `POST` | `/landing-pages/{id}/check` | Get LP Code's "Check my page": whether the page at the saved URL carries its code |
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
`ppc-accounts:read|write`). The variables routes ask for
`remove_traffic_source` as well, and first: Setup › Traffic Sources shows its
variables dialog, and the variables it edits, only to a role with it (Super
user and Admin; a Campaign manager is answered `403` naming it). Records are the caller's own: another account's
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
| `loader_deferred_pixel` | The same script with the deferred pixel on (`p202_defpixel=1`); see [PurLinks](../features/purlinks.md) |
| `purlink` | The [PurLink](../features/purlinks.md): the campaign URL, a link straight to the offer. `null` when the campaign URL is not an absolute http(s) URL |
| `outbound_link` | Option 1: the link to the offer (`go.php?lpip=`) |
| `outbound_php` | Option 2: a PHP redirect page to save on your site and link to (cloaks the affiliate link) |
| `outbound_javascript` | Option 3: a JavaScript redirect page (lets other tags fire first) |

Its campaign must be live, as the page only lists those (`422` on
`aff_campaign_id` otherwise); `offers` is refused.

**An advanced page** (`landing_page_type` 1) links out to each offer named in
`offers`, in order: `campaign:<aff_campaign_id>` (via `go.php?acip=` and
`off.php`) or `rotator:<rotator id>` (via `go.php?rpi=` and `offrtr.php`),
comma separated, at most 100. Each comes back in `offers[]` with `position`,
`type`, `id`, `public_id`, `name`, `outbound_link` and `outbound_php`; a
campaign offer also has `purlink`, its campaign URL with `acip=` naming the
offer (`null` when that cannot be written). Both page types have
`loader_deferred_pixel`.

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


## Check a landing page's code — `POST /landing-pages/{id}/check`

"Check my page" on Get LP Code. The server fetches the landing page's saved
URL and reads its HTML for the code above. No body and no parameters. A key
with `landing-pages:read` may call it: it changes no setup.

```json
{"data": {"landing_page_id": 12, "landing_page_id_public": 6122,
          "landing_page_url": "https://lp.example.com/spring",
          "status": "absent", "found": false,
          "message": "The code was not found on your page at https://lp.example.com. …",
          "other_landing_page_ids_public": []}}
```

`status` is `present`, `legacy` (the older `landing.js` code, which still
works), `mixed` (this page's code and another landing page's),
`other_tracker` (loaded from a host that is not this install's tracking
domain), `wrong_id`, `commented` (only inside an HTML comment), `absent`, or
`error` when the page could not be fetched; `found` is true for `present` and
`legacy`. A page that cannot be fetched is a `200` with `status: "error"`. The
answer is kept: Setup › Landing Pages shows it beside the page. That page
checks a landing page with no answer for its present URL when the list is
opened, and the cron job checks each page again about once a day.

Only public addresses are fetched, each redirect is checked, and the fetch
stops at 10 seconds or 2 MB. Code a tag manager adds after the page loads is
not seen. Not stageable; no dry run.

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
| `scheme` | the one the tracking domain is saved with, else the request's (as the page and tracker links decide it) | `http` or `https` |

`amount` and `subid` are written in as given, so what would break a URL, an
HTML attribute or a JavaScript string is refused: whitespace, quotes, `<`, `>`,
`\`, `&` and control characters.

For a key with `conversions:write`, every URL ends with the account's postback
token, `&p202_token=…`, minted the first time a URL is handed out; the answer
also carries it as `postback_token`, with the account's mode as
`postback_auth_mode` ([below](#postback-authentication)). The token records
and reverses conversions, so a key without `conversions:write` gets the URLs
without it: `postback_token` is `null` and `postback_token_withheld` says why. In `signed` mode these URLs are refused
as they are: sign them, and pixels cannot be used.

```bash
curl -H "Authorization: Bearer $KEY" \
  "https://your-domain.com/api/v3/conversions/postback-code?subid=%7Baff_sub%7D&amount=%7Bpayout%7D"
```

## When the network does not send the SubID back

Some networks leave the sub id macro unfilled, so the global postback arrives
as `gpb.php?subid={aff_sub}&amount=49.00` with the macro itself where the
click's id should be, or with `subid=` empty. Until 1.9.80 that conversion was
answered `SubID not found` and lost. Now `gpb.php` looks for the click by an
**ad click id** the postback sends back instead: the `gclid`, `gbraid` or
`wbraid` Google put on the ad click, Microsoft's `msclkid`, Meta's `fbclid`,
TikTok's `ttclid` or OpenAI's `oppref` (ChatGPT ads), which every click keeps.

A SubID that is there is read exactly as before. The ad click id is asked
only when `subid` (and `sid`) is empty or is one unfilled macro and nothing
else: `[[subid]]`, `{aff_sub}`, `{{name}}`, `${name}`, `[name]`, `[=SID=]`,
`#s2#`, `%subid1%` or `<name>`. A SubID that holds anything else and names no
click is still refused, whatever else the postback sends.

**Setting it up**, for a store, Northwind Outdoor, advertising on Google
through a network whose postbacks drop the sub id:

1. Hand the network the ad click id when you send it the visitor. In the
   campaign's URL put the token in one of the network's sub id slots, e.g.
   `https://network.example/offer/812?aff_sub={aff_sub}&aff_sub3=[[gclid]]`
   (`[[msclkid]]`, `[[fbclid]]`, `[[ttclid]]`, `[[oppref]]`, `[[gbraid]]` and `[[wbraid]]`
   work the same way).
2. Have the network send it back. Either name it for what it is in the
   postback URL you give the network:

   ```
   https://track.northwind.example/tracking202/static/gpb.php?subid={aff_sub}&amount={payout}&gclid={aff_sub3}&p202_token=<your token>
   ```

   or, when the network's postback is fixed and returns it under its own
   name (`...&aff_sub3=Cj0KCQiA...`), tell Prosper202 that name: Personal
   settings › Advanced › **Match conversions by ad click id**, `aff_sub3`
   (`user_pref_click_id_params` on
   [`PUT /users/{id}/preferences`](14-users.md#ad-click-id-parameters-user_pref_click_id_params),
   `p202 user prefs update <id> --user-pref-click-id-params aff_sub3`). You
   need not say which platform's id it carries: the value is tried as each
   kind.

What it will and will not match:

- **One visitor's click.** An ad click id names one ad click, so several
  clicks carrying it are that visitor recorded more than once (a reloaded
  landing URL makes two). The conversion goes to the latest of them, by the
  time of its last visit. A retry of a sale stays where the sale is: when one
  of those clicks already holds the order number or event the postback
  sends (or, for a postback with neither, already converted), that click
  is taken, and the retry is answered as the duplicate it is.
- **Never a choice a report would show.** When the clicks carrying the ids
  sent are on more than one campaign, from more than one traffic source
  account, or of more than one account, nothing says which converted: the
  postback is answered `409` and nothing is recorded; when the clicks are
  all your account's, your Setup › Inbound Log says which of those it was.
  Send the SubID to settle those.
- **An id, not a placeholder.** Only a value with an id's shape is looked
  up: at least 8 letters, digits and `.` `_` `~` `-`, with a letter and a
  digit among them, as every gclid, gbraid, wbraid, ttclid, msclkid and
  fbclid is. What a page or template writes where the id was meant to go
  (`undefined`, `null`, `(not set)`, an unfilled `__GCLID__`, a `{{ gclid }}`
  or `$gclid` it left, the parameter's own name, `test`) is not: every
  visitor whose page wrote it shares it.
- **Exactly.** An id's case is part of it: `Cj0KCQ...` and `cj0kcq...` are
  different ids.
- **The last 30 days.** The click must have been visited in the last 30 days.
- **After the upgrade.** Until the 1.9.80 database upgrade has run, a
  postback that needs this lookup is answered `503` (with `Retry-After`),
  which a network that retries sends again; the upgrade fills the lookup
  for the clicks of the last 30 days.
- **Your own clicks.** A parameter you name in Personal settings is looked up
  among your account's clicks only. Whichever way the click is found, the
  postback must still pass that click's account's
  [postback authentication](#postback-authentication).

A conversion found this way is recorded with `match_method` `ad_click_id`
([Conversions](10-conversions.md#how-each-conversions-click-was-found)), so
`GET /conversions?match_method=ad_click_id` lists them, and Setup › Inbound
Log says which parameter found the click and how many clicks carry it, or
why none was found. Only
`gpb.php` does this; the pixels and `pb.php` read the SubID and cookies as
before.

## Postback authentication

Click ids count up. Without authentication, anyone who can guess one can send
`gpb.php?subid=<id>` and record a sale, or `&status=reversed` and take one
back. So each account has two credentials:

- a **postback token**, 32 hex characters, which every pixel and postback URL
  Setup hands out carries as `p202_token`;
- a **signing secret**, 64 hex characters, which no URL ever carries: a
  sender that can compute an HMAC keys `p202_sig` with it
  ([below](#signed-postbacks)).

The account's **mode** says what `pb.php`, `gpb.php`, `px.php`, `gpx.php` and
`upx.php` require of a request for its campaign or click:

| `mode` | What happens |
| ------ | ------------ |
| `off` | Every request is accepted, as before 1.9.80, whatever credentials it carries: refusing a stale or wrong token would stop no one (leaving it out passes) and would lose the conversions of URLs that still carry a replaced token. |
| `token` | The token or a valid signature is needed. |
| `signed` | Only a valid signature is accepted. The token alone is refused, with a message naming the signing secret. **A pixel cannot sign** (the visitor's browser loads it), so `px.php`, `gpx.php` and `upx.php` are refused too unless the request is signed. |
| `token`, `signed` | A wrong token or signature is refused. One account's token or secret opens nothing of another account's. |

A refusal is `403` with a JSON message, and nothing is recorded. `gpx.php` has
sent its image already, so it only logs the refusal.

Accounts that existed when the install upgraded to 1.9.80 start at `off`, so
the postbacks their networks already call keep converting: copy the URLs
again from Setup (they now carry the token), give them to your networks,
then choose `token`. An account made after 1.9.80 starts at `token`. Choose
`signed` only once every sender signs and no pixel is in use.

Both credentials are stored encrypted (AES-256-GCM, under a key kept in the
database's deployment secrets). The token is shown on Setup › Postback /
Pixel; both are shown on Account › API Integrations › Postback
authentication. The token is a URL parameter, so it lands in the logs of
whoever relays the request; rotate it if it leaks. **A pixel runs in the
visitor's browser, so its token can be read on the advertiser's page.** That
still stops anyone forging conversions blind by counting click ids, which is
the attack it is for; it does not stop someone who has seen the page. A
sender that can sign should sign, and `signed` mode keeps a token read off a
page from opening anything.

| Method | Path | Body | Permission and key scope |
| ------ | ---- | ---- | ---------- |
| `GET` | `/conversions/postback-auth` | — | `access_to_api_integrations`, `conversions:write` |
| `PUT` | `/conversions/postback-auth` | `{"mode": "token"}` (`off`, `token` or `signed`) | `access_to_api_integrations`, `conversions:write` |
| `GET` | `/conversions/postback-auth/readiness` | — | `access_to_api_integrations`, `conversions:write` |
| `POST` | `/conversions/postback-auth/rotate` | `{"credential": "token", "grace_hours": 168}` (`token` or `signing_secret`; 0–720 hours, default 168) | `access_to_api_integrations`, `conversions:write` |

The read hands out the token and the signing secret, which record and
reverse conversions, so it asks what the page asks and a key that may write
conversions. `GET /conversions/postback-code` answers the token
(`postback_token`) and the mode (`postback_auth_mode`) with the snippets,
under Setup's permission, to a key with `conversions:write` only. The mode
and the credentials it needs are saved together or not at all. The rotation
honors `Idempotency-Key`: a retry answers the first rotation instead of
rotating again (a replay shows the credentials as they are now, since the
recorded answer keeps none).

If a stored token or secret cannot be read (the key that encrypts them was
lost from the database, or a value was altered), a postback that presents it
is answered `500`, never let through, while one that presents nothing is
still decided by the mode, and `off` accepts both. Opening Account › API
Integrations, Setup › Postback / Pixel, any of the three routes above, or a
rotation replaces it with a new one, and the answer says so (`recovered`):
the old ones stopped working, so copy your URLs again.

A rotation makes a new token or signing secret; the old one keeps working
for `grace_hours` (`previous_token_valid_until`,
`previous_signing_secret_valid_until`) while your networks or signing
servers move to the new one. `0` stops it at once. Two of each work at most:
one an earlier rotation kept working is dropped.

### Signed postbacks

A sender that can compute an HMAC (your own server, a CRM webhook you
script) sends `p202_ts` and `p202_sig` instead of the token, keyed with the
signing secret, so no credential travels:

- `p202_ts`: Unix seconds; it must be within 300 seconds of the server's
  clock.
- `p202_sig`: lower-case hex HMAC-SHA256, keyed with the signing secret (its
  64 characters as text; not the URL token, which is refused as a key), of

  ```
  P202-POSTBACK-V1\n<endpoint>\n<canonical query>
  ```

  `<endpoint>` is `pb`, `gpb`, `px`, `gpx` or `upx`. `<canonical query>` is
  every parameter of the URL except `p202_sig` (`p202_ts` included): each
  name and value decoded, then percent-encoded as RFC 3986 says (PHP's
  `rawurlencode()`: letters, digits and `-._~` as they are, a space as
  `%20`), written `name=value`, sorted by name and then by value as bytes,
  and joined with `&`. A parameter sent twice is in it twice.

Inside the window the same signed request can be sent again; the conversion
is deduplicated as a network's retry is, by its transaction id (or once per
click without one). Send a transaction id.

```php
$secret = '…';                                   // signing_secret, from GET /conversions/postback-auth
$params = ['subid' => '123', 'amount' => '9.99', 'txid' => 'A-1', 'p202_ts' => (string) time()];
$pairs = [];
foreach ($params as $name => $value) {
    $pairs[] = [rawurlencode($name), rawurlencode($value)];
}
usort($pairs, fn ($a, $b) => strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1]));
$canonical = implode('&', array_map(fn ($p) => $p[0] . '=' . $p[1], $pairs));
$params['p202_sig'] = hash_hmac('sha256', "P202-POSTBACK-V1\ngpb\n" . $canonical, $secret);
$url = 'https://track.example.com/tracking202/static/gpb.php?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
```

For `subid=123&amount=9.99&txid=A-1&p202_ts=1800000000` to `gpb`, the string
signed is:

```
P202-POSTBACK-V1
gpb
amount=9.99&p202_ts=1800000000&subid=123&txid=A-1
```

A PurLink SubID's signature says which click and offer the SubID names; it
does not authenticate the postback, and the mode applies all the same.
Payment notifications (ClickBank, JVZoo, PayKickstart, Zaxaa) verify their
own signatures and are not affected.

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

**Where a postback goes.** This server sends a Postback pixel's URLs (and its
correction URLs) itself, on behalf of whoever saved the pixel and of anyone
who sends `gpb.php` a click id, so it sends them only to public addresses.
When a postback is sent, its host is resolved, and it is refused if any
address the host resolves to is private, loopback, link-local (where cloud
metadata answers), carrier-grade NAT, multicast, reserved or another range
that is not globally reachable, IPv4 or IPv6 (an IPv4 address carried inside
IPv6, `::ffff:10.0.0.1`, is judged as that address). The connection goes to
the address that passed, with the host pinned and no proxy, and each redirect
the network answers with (at most 5) is checked the same way before it is
followed. http:// and any port are fine. A refused postback is not sent: its
outbox row is `failed` at once with the reason in `last_error`, it is not
retried, the error log names the pixel and the host (never the URL's path or
query, which can hold the network's key), and `gpb.php` and `upx.php` answer
as they always have.

Saving refuses what shows without a lookup, on `pixel_code` or
`correction_url`: such an address written in the URL (in any spelling:
`2130706433` and `0177.0.0.1` are refused by name), a local name
(`localhost`, a single label, `.local`, `.internal`), a user name or password
before the host, a scheme other than http(s). A host made of tokens
(`http://[[c1]].network.example/`) is checked when it is filled. A URL the
pixel already holds (saved by an earlier version) is not refused when the
pixel is saved again, so `PUT` of a pixel's own `GET` body still works, but
it is refused when it is sent. Setup › Traffic Sources refuses the same, in
the same words.

An operator whose network really receives postbacks on their own network
admits it with the setting the export webhooks use, in `202-config.php`:

```php
define('P202_WEBHOOK_ALLOW_NETWORKS', '10.20.0.0/16');
```

Link-local addresses and the cloud metadata endpoints are never admitted, and
an entry that does not parse holds every postback (each is kept and retried)
until it is fixed.

A code is stored as sent (only trimmed) and fired as stored: a Raw pixel's
backslashes, `"a\\nb"` in a script included, read back byte for byte. The
account form keeps them too (it read a Raw pixel through `stripslashes()`,
so every save of the account lost a level of backslashes), stores the CRLF
line breaks a browser submits as `\n`, refuses a code with no type, and
removes a pixel whose code is cleared — the only pixel included, which it
used to leave firing. Deleting the last pixel here is `DELETE` on its id,
as for any other.

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
p202 conversion postback-auth get                            # mode, token, signing secret, how to sign
p202 conversion postback-auth readiness                      # who a stricter mode would refuse, from the last 7 days
p202 conversion postback-auth set --mode token               # off | token | signed
p202 conversion postback-auth rotate --credential signing_secret --grace-hours 168
p202 ppc-network variable create 3 --name 'Ad id' --parameter adid --placeholder '{ad_id}'
p202 ppc-account pixel create 4 --type-id 4 --code 'https://network.example/pb?click=[[subid]]'
```

See [the Go CLI guide](../cli/10-go-cli.md#setup-code-and-traffic-source-settings).
