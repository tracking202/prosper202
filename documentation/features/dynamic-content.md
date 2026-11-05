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
- `t202SubidOrig`, `t202SourceId` and the `t202Settings` options are not
  restored; an element named `t202SubidOrig` gets `t202Subid`.
