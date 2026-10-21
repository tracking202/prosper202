package cmd

import (
	"fmt"
	"net/url"
	"regexp"
	"sort"
	"strings"
)

// networkSubIDQueryFields are the query fields documentation/tools--resources/
// 999-common-network-subid-parameters.md names for [[subid]], exactly as
// spelled there. A name alone does not say a field is ours (HitPath's sid is
// its campaign, CJ's is the click), so these are read only for a tracker
// whose campaign URL cannot say which field holds [[subid]]. Not listed:
// CAKE's s1 (reserved for a sub-publisher), Amazon's tag (the account), and
// the networks whose format is per account (Adrecord, Involve Asia, Pepperjam).
var networkSubIDQueryFields = []string{
	"s", "subid", "sid", // Prosper202's own examples; Admitad, CPAlead; CJ
	"subId",             // ADCELL
	"subacc", "subacc2", // AdCombo
	"subid1", "subid2", "subid3", "subid4", // Admitad, CPAlead
	"sub1", "sub2", "sub3", "sub4", "sub5", // Affise, Everflow
	"sub6", "sub7", "sub8", "sub9", "sub10", // Affise, Everflow
	"clickref", "clickref2", "clickref3", // Awin, Webgains
	"clickref4", "clickref5", "clickref6", // Awin
	"s2", "s3", "s4", "s5", // CAKE, ClickDealer, MaxBounty
	"extclid", "tid", // ClickBank
	"UniqueId",                                 // Commission Factory
	"fobs", "fobs2", "fobs3", "fobs4", "fobs5", // FlexOffers
	"c1", "c2", "c3", // HitPath
	"subId1", "subId2", "subId3", "SharedId", // impact.com
	"aff_sub1", "aff_sub2", "aff_sub3", "aff_sub4", "aff_sub5", // Offer18, TUNE
	"aff_sub",        // TUNE / HasOffers
	"data1", "data2", // Post Affiliate Pro
	"u1",                         // Rakuten Advertising
	"afftrack",                   // ShareASale (legacy)
	"p1", "p2", "p3", "p4", "p5", // Trackier
	"epi", // Tradedoubler
}

// networkSubIDPathFields are the path fields (segment prefixes) the same page
// names: Partnerize's /pubref:<value>/.
var networkSubIDPathFields = []string{"pubref:"}

// subidTokenRe is [[subid]] as the redirects fill it: in any case
// (TrafficSourcePixels::replaceTokens).
var subidTokenRe = regexp.MustCompile(`(?i)\[\[subid\]\]`)

// noClickSubid is what the redirects put in [[subid]] when they serve the
// cached link with no database to record the click (tracking202/redirect/).
const noClickSubid = "p202"

// clickIDRe is the shape of a click id gpb.php can match
// (Prosper202\Click\ClickId::parse).
var clickIDRe = regexp.MustCompile(`^[1-9][0-9]{0,18}$`)

// offerURL is one of a campaign's offer URL slots.
type offerURL struct {
	slot, url string // slot: aff_campaign_url, aff_campaign_url_2, ...
}

// slotFlag is the campaign update flag that writes the slot.
func (o offerURL) slotFlag() string {
	return "--" + strings.ReplaceAll(o.slot, "_", "-")
}

// subidField is one place in an offer URL: a query field, or a path segment
// that starts with prefix.
type subidField struct {
	query      string
	pathPrefix string
	pathIndex  int
}

func (f subidField) String() string {
	if f.query != "" {
		return f.query
	}
	if f.pathPrefix == "" {
		return fmt.Sprintf("path segment %d", f.pathIndex)
	}
	return "/" + f.pathPrefix
}

type subidErrorKind int

const (
	subidMissing   subidErrorKind = iota // the redirect carries no click id
	subidNoToken                         // the campaign's offer URL gives the network none
	subidNotBare                         // the field holds more than the click id
	subidAmbiguous                       // network fields disagree and nothing says which is ours
	subidNoClick                         // the redirect recorded no click
)

type subidError struct {
	kind subidErrorKind
	slot offerURL   // the offer URL slot the error is about, when there is one
	fits []offerURL // subidAmbiguous from the campaign: the slots that fit
	msg  string
}

func (e *subidError) Error() string { return e.msg }

// CategoryName: a click the server could not record is the server's; the
// rest are the campaign's setup, which the user corrects.
func (e *subidError) CategoryName() string {
	if e.kind == subidNoClick {
		return "server"
	}
	return "validation"
}

func newSubidError(kind subidErrorKind, format string, args ...interface{}) *subidError {
	return &subidError{kind: kind, msg: fmt.Sprintf(format, args...)}
}

// subidMatch is the click id a redirect carries and the field it came from.
type subidMatch struct {
	ID, Field    string
	FromCampaign bool // the campaign's offer URL named the field
}

// subidFromCampaign reads the click id from loc, the redirect to one of
// offers, in the field that offer puts [[subid]] in. It never falls back to
// the network fields: the campaign's URLs are known, so a field they do not
// name is not ours whatever it is called. Every offer URL that fits loc is
// read, those without a readable [[subid]] too, and when they read it
// differently it refuses: nothing says which one the tracking link chose.
func subidFromCampaign(loc string, offers []offerURL) (subidMatch, error) {
	u, err := url.Parse(loc)
	if err != nil || loc == "" {
		return subidMatch{}, newSubidError(subidMissing, "could not read the redirect (%q)", loc)
	}
	var reads []slotRead
	var named []string
	var outside *offerURL
	for i, o := range offers {
		fields, readable := templateSubidFields(o.url)
		hasToken := subidTokenRe.MatchString(o.url)
		for _, f := range fields {
			named = append(named, f.String())
		}
		if hasToken && readable && len(fields) == 0 && outside == nil {
			outside = &offers[i]
		}
		if !readable {
			// Not a URL here, so it cannot be ruled out as the source.
			reads = append(reads, noTokenRead(o, "the campaign's %s cannot be read as a URL", "%s cannot be read as a URL"))
			continue
		}
		if !fitsTemplate(o.url, u) {
			continue
		}
		switch {
		case !hasToken:
			reads = append(reads, noTokenRead(o, "the campaign's %s has no [[subid]], so the network is given no click id to send back", "%s has no [[subid]]"))
		case len(fields) == 0:
			reads = append(reads, noTokenRead(o, "the campaign's %s puts [[subid]] outside its query and path, where a network does not read it", "%s puts [[subid]] outside its query and path"))
		default:
			reads = append(reads, readSlot(u, o, fields))
		}
	}
	switch {
	case len(reads) > 0:
		return oneReading(loc, reads)
	case len(named) > 0:
		return subidMatch{}, newSubidError(subidMissing, "the redirect (%q) is not one of the campaign's offer URLs with %s in it", loc, strings.Join(named, ", "))
	case outside != nil:
		e := newSubidError(subidNoToken, "the campaign's %s puts [[subid]] outside its query and path, where a network does not read it", outside.slot)
		e.slot = *outside
		return subidMatch{}, e
	}
	e := newSubidError(subidNoToken, "the campaign's offer URL has no [[subid]], so the network is given no click id to send back")
	if len(offers) > 0 {
		e.slot = offers[0]
	}
	return subidMatch{}, e
}

// noTokenRead is the reading of an offer URL that gives the network no
// [[subid]] it can read; msg and desc take its slot.
func noTokenRead(o offerURL, msg, desc string) slotRead {
	e := newSubidError(subidNoToken, msg, o.slot)
	e.slot = o
	return slotRead{slot: o, err: e, desc: fmt.Sprintf(desc, o.slot)}
}

// slotRead is what one offer URL that fits the redirect reads from it: the
// click id in match, or err.
type slotRead struct {
	slot  offerURL
	match subidMatch
	err   *subidError
	desc  string // for a refusal that names every reading
}

// readSlot reads the click id from u in the fields of o that hold [[subid]].
func readSlot(u *url.URL, o offerURL, fields []subidField) slotRead {
	var notBare *slotRead
	for _, f := range fields {
		v, ok := fieldValue(u, f)
		if !ok {
			continue
		}
		if clickIDRe.MatchString(v) {
			return slotRead{slot: o, match: subidMatch{ID: v, Field: f.String(), FromCampaign: true},
				desc: fmt.Sprintf("%s reads %s from %s", o.slot, v, f)}
		}
		if v == noClickSubid {
			return slotRead{slot: o, err: newSubidError(subidNoClick, "the redirect carries %s in %s, which the tracking link sends when it recorded no click (its database was unreachable)", noClickSubid, f),
				desc: fmt.Sprintf("%s reads %s from %s", o.slot, noClickSubid, f)}
		}
		if notBare == nil {
			e := newSubidError(subidNotBare, "the offer URL's %s carries %q, not the bare click id: a network sends the whole field back, and the postback matches only a click id", f, v)
			e.slot = o
			notBare = &slotRead{slot: o, err: e, desc: fmt.Sprintf("%s reads %q from %s, not a click id", o.slot, v, f)}
		}
	}
	if notBare != nil {
		return *notBare
	}
	return slotRead{slot: o, err: newSubidError(subidMissing, "the redirect has none of the fields %s puts [[subid]] in", o.slot),
		desc: fmt.Sprintf("%s finds none of its [[subid]] fields", o.slot)}
}

// oneReading is the click id when every offer URL that fits the redirect
// reads the same one (or refuses it the same way), and an ambiguity refusal
// naming each reading when they differ.
func oneReading(loc string, reads []slotRead) (subidMatch, error) {
	first := reads[0]
	agree := true
	var fields []string
	for _, r := range reads {
		if (r.err == nil) != (first.err == nil) ||
			(r.err != nil && r.err.kind != first.err.kind) ||
			(r.err == nil && r.match.ID != first.match.ID) {
			agree = false
			break
		}
		if r.err == nil && !containsString(fields, r.match.Field) {
			fields = append(fields, r.match.Field)
		}
	}
	if agree {
		if first.err != nil {
			return subidMatch{}, first.err
		}
		m := first.match
		m.Field = strings.Join(fields, ",")
		return m, nil
	}
	descs := make([]string, 0, len(reads))
	slots := make([]offerURL, 0, len(reads))
	for _, r := range reads {
		descs = append(descs, r.desc)
		slots = append(slots, r.slot)
	}
	e := newSubidError(subidAmbiguous, "the redirect (%q) fits more than one of the campaign's offer URLs, and they read it differently (%s): nothing says which one the tracking link sent the click to", loc, strings.Join(descs, "; "))
	e.fits = slots
	return subidMatch{}, e
}

// anyTokenRe is a [[token]] of any name: the redirects fill the ones they
// know and leave the rest in place (TrafficSourcePixels::replaceTokens).
var anyTokenRe = regexp.MustCompile(`\[\[[^\[\]]*\]\]`)

// fitsTemplate reports whether loc can be the redirect to tmpl: tmpl's host,
// each of its path segments and each of its query values are in loc as they
// are in tmpl, except that a [[token]] in one may have been filled with
// anything. loc may carry query fields tmpl does not: dl.php appends the
// tracking link's own (setPrePopVars). The query is compared as written,
// since the redirect copies tmpl's text: url.Query would drop a pair holding
// a ";" or a malformed escape from both sides, and with it the comparison.
func fitsTemplate(tmpl string, loc *url.URL) bool {
	t, err := url.Parse(tmpl)
	if err != nil {
		return false
	}
	if !fitsFilled(t.Host, loc.Host, true) {
		return false
	}
	tsegs := strings.Split(t.EscapedPath(), "/")
	lsegs := strings.Split(loc.EscapedPath(), "/")
	if len(tsegs) != len(lsegs) {
		return false
	}
	for i := range tsegs {
		if !fitsFilled(tsegs[i], lsegs[i], false) {
			return false
		}
	}
	lq := rawQueryPairs(loc.RawQuery)
	for k, tvs := range rawQueryPairs(t.RawQuery) {
		lvs := lq[k]
		if len(lvs) < len(tvs) {
			return false
		}
		for i, tv := range tvs {
			if !fitsFilled(tv, lvs[i], false) {
				return false
			}
		}
	}
	return true
}

// rawQueryPairs splits a raw query into its values by key, on "&" alone and
// with nothing unescaped, so that no pair can fail to parse.
func rawQueryPairs(q string) map[string][]string {
	out := map[string][]string{}
	for _, pair := range strings.Split(q, "&") {
		if pair == "" {
			continue
		}
		k, v, _ := strings.Cut(pair, "=")
		out[k] = append(out[k], v)
	}
	return out
}

// fitsFilled reports whether got is part filled in: part's text exactly
// (in any case when fold), each [[token]] in it any run of text.
func fitsFilled(part, got string, fold bool) bool {
	tokens := anyTokenRe.FindAllStringIndex(part, -1)
	if tokens == nil {
		if fold {
			return strings.EqualFold(part, got)
		}
		return part == got
	}
	var b strings.Builder
	if fold {
		b.WriteString("(?i)")
	}
	b.WriteString("^")
	last := 0
	for _, m := range tokens {
		b.WriteString(regexp.QuoteMeta(part[last:m[0]]))
		b.WriteString("(?s:.*)")
		last = m[1]
	}
	b.WriteString(regexp.QuoteMeta(part[last:]))
	b.WriteString("$")
	re, err := regexp.Compile(b.String())
	return err == nil && re.MatchString(got)
}

// templateSubidFields lists the fields of tmpl that hold [[subid]]; false
// when tmpl cannot be read as a URL.
func templateSubidFields(tmpl string) ([]subidField, bool) {
	u, err := url.Parse(tmpl)
	if err != nil {
		return nil, false
	}
	var out []subidField
	q := u.Query()
	keys := make([]string, 0, len(q))
	for k := range q {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, k := range keys {
		for _, v := range q[k] {
			if subidTokenRe.MatchString(v) {
				out = append(out, subidField{query: k})
				break
			}
		}
	}
	// A path field is named up to its colon (Partnerize's pubref:); text
	// between the name and [[subid]] is part of the value the network keeps.
	for i, seg := range strings.Split(u.EscapedPath(), "/") {
		loc := subidTokenRe.FindStringIndex(seg)
		if loc == nil {
			continue
		}
		prefix := ""
		if k := strings.IndexByte(seg[:loc[0]], ':'); k >= 0 {
			prefix = seg[:k+1]
		}
		out = append(out, subidField{pathPrefix: prefix, pathIndex: i})
	}
	return out, true
}

// fieldValue reads f from u: the first value of a query field, or the rest of
// the path segment at f's index after its prefix.
func fieldValue(u *url.URL, f subidField) (string, bool) {
	if f.query != "" {
		vs, ok := u.Query()[f.query]
		if !ok || len(vs) == 0 {
			return "", false
		}
		return vs[0], true
	}
	segs := strings.Split(u.EscapedPath(), "/")
	if f.pathIndex >= len(segs) || !strings.HasPrefix(segs[f.pathIndex], f.pathPrefix) {
		return "", false
	}
	v, err := url.PathUnescape(segs[f.pathIndex][len(f.pathPrefix):])
	if err != nil {
		return "", false
	}
	return v, true
}

// subidFromNetworkFields reads every network field of loc that holds a click
// id, for a tracker whose campaign URL cannot say which is ours. One id is
// the answer; two different ones are a question this cannot settle, so it
// is refused rather than guessed.
func subidFromNetworkFields(loc string) (subidMatch, error) {
	u, err := url.Parse(loc)
	if err != nil || loc == "" {
		return subidMatch{}, newSubidError(subidMissing, "could not read the redirect (%q)", loc)
	}
	found := map[string][]string{}
	var order []string
	add := func(id, field string) {
		if _, seen := found[id]; !seen {
			order = append(order, id)
		}
		found[id] = append(found[id], field)
	}
	// A pair the parser drops (a ";" separator, a malformed escape) may be a
	// field a network reads, holding another id.
	q, err := url.ParseQuery(u.RawQuery)
	if err != nil {
		return subidMatch{}, newSubidError(subidAmbiguous, "the redirect (%q) has a query pair that cannot be read (%v), which may hold another SubID field, and nothing says which one is [[subid]]", loc, err)
	}
	for _, name := range networkSubIDQueryFields {
		for _, v := range q[name] {
			if v == noClickSubid {
				return subidMatch{}, newSubidError(subidNoClick, "the redirect carries %s in %s, which the tracking link sends when it recorded no click (its database was unreachable)", noClickSubid, name)
			}
			if clickIDRe.MatchString(v) {
				add(v, name)
			}
		}
	}
	for _, seg := range strings.Split(u.EscapedPath(), "/") {
		for _, prefix := range networkSubIDPathFields {
			if !strings.HasPrefix(seg, prefix) {
				continue
			}
			if v, err := url.PathUnescape(seg[len(prefix):]); err == nil && clickIDRe.MatchString(v) {
				add(v, "/"+prefix)
			}
		}
	}
	switch len(order) {
	case 0:
		return subidMatch{}, newSubidError(subidMissing, "could not find a subid in the redirect (%q)", loc)
	case 1:
		return subidMatch{ID: order[0], Field: strings.Join(found[order[0]], ",")}, nil
	}
	parts := make([]string, 0, len(order))
	for _, id := range order {
		parts = append(parts, strings.Join(found[id], ",")+"="+id)
	}
	return subidMatch{}, newSubidError(subidAmbiguous, "the redirect carries different numbers in SubID fields (%s), and nothing says which one is [[subid]]", strings.Join(parts, "; "))
}
