# Split tests

A landing page can show each visitor one of a few variants (a headline, an
offer, a button) and record which one as the click's **C4**. Every report
grouped by C4 then shows each variant's clicks, conversions and revenue side
by side; a conversion counts toward the variant of the click it is credited
to.

## Setting one up

In **Setup › Landing Pages**, edit the page and open **Split test**:

1. List the variants, one a line: a name, then its weight.

   ```
   headline-a 50
   headline-b 50
   ```

   A name is 1 to 50 letters, digits, dots, dashes or underscores, starting
   with a letter or digit; it is what C4 stores. Two names that differ only
   in case are one C4 value, and are refused. A weight is a whole number from
   0 to 1000, and 1 when left out. A visitor gets a variant with a chance in
   proportion to its weight; a weight of 0 keeps the variant listed and shows
   it to no one.
2. Turn on **Run the split test on this page**. A test that is on needs two
   variants and a weight above 0.
3. Leave **Replace a C4 the visit already has** off unless you want the
   variant over a C4 the traffic source's link (or your page's
   `t202Settings.t202C4`) sends. Off, a visit that arrives with a C4 keeps it,
   and its click is not counted toward any variant.

The test is off for every page until you turn it on. It runs on simple and
advanced pages alike, wherever the page loads the landing page script.

## On the page

The landing page script picks the variant as it runs, before the pageview is
sent, and shows it to the page five ways:

| Where | What |
|---|---|
| `window.t202Variant` | The variant's name; `''` when the page runs no test |
| `p202.variant()` | The same, for a script that runs later |
| `<html data-p202-variant="headline-b">` | For CSS: `html[data-p202-variant="headline-b"] .headline-a { display: none; }` |
| The `p202:variant` event on `document` | `event.detail.variant`, sent once, for a listener added before the script runs |
| `dcs.t202DataObj.t202Variant` | An element with `t202attribute="t202Variant"` shows it ([Dynamic content](dynamic-content.md)) |

To keep the page from flashing the wrong variant, load the script in the
page's `<head>` and hide the variants with CSS until the attribute is set.

## The same visitor, next time

The variant is kept in the page site's local storage, under
`p202variant_<lpip>`, so a returning visitor sees the one they saw before.
It is kept only while the visitor consents: `p202.consent(false)`, or
`?p202_consent=0` on the URL, deletes it, and such a visitor gets a variant
for each view without one being kept. A kept variant that the test no longer
shows (renamed, removed, or at weight 0) is replaced with a new pick, and
turning the test off forgets it.

## What it does not do

- It does not say which variant is ahead: compare them in a report grouped
  by C4.
- With parallel tracking, the C4 the ad platform's tracking template sends
  is written over the click's after the page's pageview, as every C4 from
  the template is.
- When the test cannot be read (a database failure), the page runs as if it
  had none for that view, and the failure is in the server's error log; the
  visitor's kept variant is not forgotten.
