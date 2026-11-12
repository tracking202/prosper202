# Dynamic content

The landing page code fills elements on your page with what it knows about
the visit: the visitor's country, the keyword, a `c1` value, and so on. A
lead or order form can carry them as hidden fields.

Pages built for paid 1.9.56 keep working; see
[Upgrading from 1.9.56](#upgrading-from-1956).

## Marking an element

Either of these works, on any element:

```html
Welcome, reader from <span name="t202Country" t202Default="your country">your country</span>
<span t202attribute="t202City" t202Default="your city"></span>
<input type="hidden" name="lead_t202C1">
```

- **`name`** matches when it *contains* a segment's name: `lead_t202C1` gets
  `t202C1`. When it contains more than one (`t202CountryCode` contains
  `t202Country`), the longest wins.
- **`t202attribute`** matches a segment's name exactly.
- **Form fields** (`input`, `textarea`) get the value; a `select` picks the
  option whose value or text is the value, and keeps its choice when none is.
  Other elements get it as text, never as HTML.
- Radio, checkbox, submit and other button inputs keep their own value, and
  a `<button>` matched by its `name` keeps its label (one marked with
  `t202attribute` is filled).
- **`t202Default`** is shown when the segment has no value.
- Fields named `t202p13n_…` belong to personalization and are left alone.

## Segments

| Segment | Value |
|---|---|
| `t202Country`, `t202CountryCode`, `t202Region`, `t202City`, `t202Postal` | Where the visitor is (GeoIP) |
| `t202Browser`, `t202OS`, `t202Device`, `t202ISP` | The visitor's browser, system, device and ISP |
| `t202IP` | The visitor's IP address, only when your privacy setting tracks this visitor in full and the visitor has not refused tracking |
| `t202DayOfWeek`, `t202DayOfWeekShort`, `t202MonthOfYear`, `t202MonthOfYearShort`, `t202DayOfMonth`, `t202Year`, `t202YearShort` | Today, in your account's time zone (`Tuesday`, `Tue`, `November`, `Nov`, `3`, `2026`, `26`) |
| `t202kw` / `t202Kw`, `t202c1`…`t202c4` / `t202C1`…`t202C4` | The page URL's `t202kw` and `c1`–`c4` |
| `t202utm_source`… / `t202Utm_source`… | The page URL's `utm_source`, `utm_medium`, `utm_term`, `utm_content`, `utm_campaign` |
| `t202GCLID`, `t202GBRAID`, `t202WBRAID`, `t202TTCLID`, `t202FBCLID`, `t202MSCLKID` | The page URL's ad click ids |
| `t202Pci`, `t202Id` | The page URL's `pci` and `t202id` |
| `t202Referrer` | The page's referrer |
| `t202Random` | A random seven-digit number, one per pageview |
| `t202Subid` | The click's SubID, once the click is recorded; only when your privacy setting lets the click's cookies be set and the visitor has not refused tracking |

## From a script: `dcs`

`window.dcs.t202DataObj` holds every value. As in 1.9.56:

- `dcs.addDataObj({name: value})` adds or replaces values. Nothing is shown
  until you render them.
- `dcs.renderChanges(['name', …])` shows those values in their elements.
- `dcs.updateDataObj({name: value})` changes values the object already has
  and shows them. A change to `t202C1`…`t202C4` or `t202Kw` (any case) also
  changes the click this pageview recorded, so your reports show the value
  the page set, for example after a form step.

### Changing the recorded click

Only c1–c4 and the keyword can be changed, and only on the click the
pageview recorded: when the click is recorded, the page is given a signed
proof for that click, and the change is sent with it. Nothing changes when
the visitor has refused tracking, when your privacy setting holds the visitor
back, or when the click has since moved to another landing page. An empty
value leaves the click's value as it is. Values are cut to what the click
stores (350 characters, the keyword 150).

The traffic source (`t202Id`), the UTM values and the referrer stay as they
were recorded; changing them on the page says so once in the browser console.

PurLinks use these values for their tokens (`[[c1]]` takes `t202C1`); call
`updatePurLink()` after changing them. See [PurLinks](purlinks.md).

### Setting values before the visit is recorded (`t202Settings`)

A page can set values, or name the URL parameter a value is read from, in
`window.t202Settings`, as in 1.9.56:

```html
<script>
window.t202Settings = {
    t202C1: 'homepage',
    t202UrlVars: {t202Kw: 'q', t202C2: 'sub_id'}
};
</script>
```

Here `c1` is `homepage`, the keyword is read from `?q=…` and `c2` from
`?sub_id=…`. A parameter named in `t202UrlVars` wins when the URL has it with
a value; otherwise the value set in `t202Settings` is used, and otherwise the
usual parameter (`t202kw`, `c2`).

The keyword, c1–c4, the UTM values and the ad click ids (`t202GCLID`,
`t202GBRAID`, `t202WBRAID`, `t202TTCLID`, `t202MSCLKID`, `t202FBCLID`) are
recorded on the click with these values, shown on the page and used in
PurLinks. `t202Pubid` is recorded on the click and used in PurLinks
(`[[t202pubid]]`); no element shows it. The click is recorded with them from
the start, with no second request. Another key the data object already has
(`t202Country`) is shown on the page only. A key it does not have is ignored,
as in 1.9.56; add one with `dcs.addDataObj()`. `t202Subid`, `t202SubidOrig`,
`t202SourceId` and `t202IP` cannot be set.

What the page sets and is not used is named once in the browser console: a
key that is ignored or cannot be set, a value that is not text or a number,
a `t202UrlVars` entry that names no parameter, a `t202UseSubidOrig` that is
not true or false, a `t202ExpireSubidOrig` that is not a number of days, and
a setting that cannot be read.

Put the script anywhere in the page's HTML: the settings are read once, when
the page has finished loading and before the visit is sent. Settings added
later are not read; use `dcs.updateDataObj()` for those.

### The visitor's first click and the traffic source account

Once the visit is recorded, two more keys are filled, as in 1.9.56:

- `t202SubidOrig` is the SubID of the visitor's first visit to your site. As
  in 1.9.56, every landing page with the script keeps it, in a cookie on your
  landing page's site (`_t202SubidOrig`), for
  `t202Settings.t202ExpireSubidOrig` days (7 when left out), so an entry page
  that shows nothing keeps it for the order page that does. On a first visit
  it equals `t202Subid`. Every page reads it, from an element or from its own
  script.
- `t202SourceId` is the id of the click's traffic source account, the value
  `[[sourceid]]` takes in a campaign URL. It is empty for a click with no
  traffic source account.

`t202GetSubid()` and `t202GetSubidOrig()` return the two SubIDs, and an empty
string until the visit is recorded. All of these stay empty for a visitor who
has refused tracking or whom your privacy setting holds back, and
`p202.consent(false)` clears them and removes the cookie.

## Upgrading from 1.9.56

- `name`, `t202attribute`, form fields and the `dcs` methods work as before,
  with the 1.9.56 key names.
- **`updateDataObj()` changes the recorded click's `c1`–`c4` and keyword
  again**, with no change to your page. In 1.9.56 it also rewrote the UTM
  values, the referrer and the traffic source, cost and payout
  (`redirect/u.php`), trusting the click id the browser sent. 1.9.80 changes
  only the click the pageview recorded (see above) and keeps the rest as
  recorded. `redirect/u.php` still answers, but a request without the
  pageview's proof, such as a page that calls it directly, changes nothing.
- Values are shown as text. A `t202Default` holding HTML shows the markup.
- `t202Settings` works again: values, `t202UrlVars`, and for PurLinks
  `t202UseSubidOrig` and `t202ExpireSubidOrig` ([PurLinks](purlinks.md)).
  `t202UrlVars` now applies to any key listed above; in 1.9.56 it applied only
  to a key the settings also set. `t202Id` set this way changes the page only.
- `t202SubidOrig`, `t202SourceId`, `t202GetSubid()` and `t202GetSubidOrig()`
  are back. They are empty until the visit is recorded; 1.9.56 knew them when
  the script loaded.
