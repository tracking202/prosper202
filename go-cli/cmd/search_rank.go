package cmd

import (
	"fmt"
	"math"
	"sort"
	"strings"
	"unicode/utf8"
)

// The ranking behind `p202 search`: two rankers over the same words, fused.
//
//   - The summed ranker is Cloudflare's `cf cli search` (cf 1.0.0-beta.13):
//     MiniSearch's BM25+ per field (k 1.2, b 0.7, d 0.5) with prefix and
//     fuzzy matching and cf's field boosts (command 8, summary 5, description
//     2, context 0.5), every form of a word (the word, its synonyms, words it
//     begins) scored and summed, times the number of query words a document
//     matched. Each task (searchTasks) is a document of its own, merged with
//     its command's. Three things differ from MiniSearch 7.2.0's source: a
//     field's length is its words, where MiniSearch counts distinct words; a
//     field's average length is over the documents that have the field (cf's
//     documents all have its four); and fuzzy matching is only for a word the
//     index does not know -- a typo, rather than an ordinary word near a
//     different one.
//   - The BM25F ranker is one document per command, its tasks among its
//     fields; a word's evidence is combined across fields before one
//     saturation, and each word takes only its best form in each field. Summing
//     let one word count many times -- "breakdown" through two synonyms in four
//     fields, so `report groups` outranked the command named breakdown -- and
//     BM25F does not; it ranks long, noisy asks better.
//
// Reciprocal rank fusion (1/(60+rank), summed) of their top tens. On 30
// phrasings written and sealed (their hash recorded) before either ranker
// existed, the fusion put the right command first for 16 and in the top five
// for 26; the summed ranker alone 10 and 21; cf as published, without the
// task table, 11 and 20; the hand-weighted scorer this replaced 14 and 21.
// On the sets it was built against it does better (all 39 UI page names
// first, 12 of 12 ways of asking for Spy, 28 of 40 agent-eval asks first and
// 36 in the top five, 9 of 10 typos), and that gap is the measure of how much
// tuning to a set teaches about other wording: little. Words are the ceiling;
// a model reading `p202 commands --brief` chose right first for all 30.

type searchFieldID int

const (
	sfName searchFieldID = iota
	sfParent
	sfSummary
	sfDescription
	sfContext
	sfValues
	sfPage
	sfSection
	sfPhrase
	numSearchFields
)

// searchBoosts are the field weights both rankers use: cf's command (8),
// summary (5), description (2) and context (0.5), with flag values, UI
// pages, sections and task phrases added.
var searchBoosts = [numSearchFields]float64{
	sfName: 8, sfParent: 4, sfSummary: 5, sfDescription: 2, sfContext: 0.5,
	sfValues: 3, sfPage: 10, sfSection: 1, sfPhrase: 8,
}

// searchLengthNorm is BM25's b per field for the BM25F ranker: names, labels
// and values are not prose, so their length says nothing.
var searchLengthNorm = [numSearchFields]float64{
	sfSummary: 0.7, sfDescription: 0.7, sfContext: 0.7,
}

const (
	bm25K         = 1.2
	bm25B         = 0.7
	bm25D         = 0.5
	synonymWeight = 0.8
	prefixWeight  = 0.375
	fuzzyWeight   = 0.45
	fuzzyRatio    = 0.2
	maxFuzzy      = 6
	// groupingWeight is the share of a "breakdown" match a grouping cue
	// ("per", "by") adds without counting as a query word.
	groupingWeight = 0.5
	// wholeWeight scales the bonus the BM25F ranker gives a task whose page
	// or phrase the query names in full: the sum of its words' rarity.
	wholeWeight = 0.5
	fusionK     = 60.0
	fusedDepth  = 10
)

type searchDocKind int

const (
	docCommand searchDocKind = iota
	docTask
	docNotInCLI
)

// searchGroup is a page label or task phrase: its words, and the task's
// command line it stands for.
type searchGroup struct {
	run    string
	tokens []string
}

type flagValue struct{ flag, value string }

type rankDoc struct {
	key      string
	kind     searchDocKind
	cmd      *commandInfo
	notInCLI *taskInfo
	run      string // a task document's command line
	fields   [numSearchFields]map[string]int
	length   [numSearchFields]int
	has      [numSearchFields]bool
	groups   []searchGroup
	values   []flagValue
	// slots maps a flag to the list of values it takes: flags taking the
	// same list are one choice (report breakdown's --group-by is an alias
	// for --breakdown), and setting one sets them all.
	slots map[string]string
	// labels say where a word of a field came from, for "matched".
	labels [numSearchFields]map[string]matchLabel
	// order is each field's words as first seen: the vocabulary is walked in
	// index order, as MiniSearch walks it.
	order [numSearchFields][]string
}

type matchLabel struct {
	text       string
	structural bool
}

type rankCorpus struct {
	docs  []rankDoc
	avg   [numSearchFields]float64
	vocab map[string]bool
	words []string // vocab in index order, for prefix and fuzzy expansion
	// fieldDF counts the documents holding a word in a field (the summed
	// ranker's idf, as MiniSearch computes it).
	fieldDF [numSearchFields]map[string]int
	// bm25f marks the one-document-per-command corpus (avg over every
	// document; idf over every field).
	bm25f bool
}

func (d *rankDoc) add(f searchFieldID, toks []string, label func(tok string) matchLabel) {
	if d.fields[f] == nil {
		d.fields[f] = map[string]int{}
		d.labels[f] = map[string]matchLabel{}
	}
	d.has[f] = true
	for _, t := range toks {
		if d.fields[f][t] == 0 {
			d.order[f] = append(d.order[f], t)
		}
		d.fields[f][t]++
		d.length[f]++
		if _, ok := d.labels[f][t]; !ok && label != nil {
			d.labels[f][t] = label(t)
		}
	}
}

func fixedLabel(text string, structural bool) func(string) matchLabel {
	return func(string) matchLabel { return matchLabel{text: text, structural: structural} }
}

// commandFields indexes what both rankers read of a command: its name and
// aliases, parents, descriptions, the flags (names, help, the groups above
// it) and the values its flags take.
func commandFields(d *rankDoc, c *commandInfo, shorts map[string]string, splitName bool) {
	words := strings.Fields(c.Path)[1:]
	leaf := words[len(words)-1]
	if splitName {
		d.add(sfName, searchTokens(leaf), fixedLabel(fmt.Sprintf("command name %q", leaf), true))
		for _, a := range c.Aliases {
			d.add(sfName, searchTokens(a), fixedLabel(fmt.Sprintf("alias %q", a), true))
		}
		d.add(sfParent, searchTokens(strings.Join(words[:len(words)-1], " ")), func(tok string) matchLabel {
			return matchLabel{text: fmt.Sprintf("under %q", strings.Join(words[:len(words)-1], " ")), structural: true}
		})
	} else {
		// MiniSearch's command field, as cf builds it: every word of the
		// path, and the aliases.
		label := func(tok string) matchLabel {
			if containsString(searchTokens(leaf), tok) {
				return matchLabel{text: fmt.Sprintf("command name %q", leaf), structural: true}
			}
			for _, a := range c.Aliases {
				if containsString(searchTokens(a), tok) {
					return matchLabel{text: fmt.Sprintf("alias %q", a), structural: true}
				}
			}
			return matchLabel{text: fmt.Sprintf("under %q", strings.Join(words[:len(words)-1], " ")), structural: true}
		}
		d.add(sfName, searchTokens(strings.Join(append(append([]string{}, words...), c.Aliases...), " ")), label)
		d.add(sfParent, nil, nil)
	}
	d.add(sfSummary, searchTokens(c.Short), fixedLabel("description", false))
	long := c.Long
	if long == "" && !splitName {
		long = c.Short
	}
	d.add(sfDescription, searchTokens(long), fixedLabel("long description", false))

	var context []string
	contextLabels := map[string]matchLabel{}
	for i := 0; i < len(words)-1; i++ {
		parent := "p202 " + strings.Join(words[:i+1], " ")
		context = append(context, shorts[parent])
		for _, t := range searchTokens(shorts[parent]) {
			if _, ok := contextLabels[t]; !ok {
				contextLabels[t] = matchLabel{text: fmt.Sprintf("%q's description", parent), structural: false}
			}
		}
	}
	for _, f := range c.Flags {
		context = append(context, f.Name+" "+f.Usage)
		for _, t := range searchTokens(f.Name) {
			if l, ok := contextLabels[t]; !ok || !l.structural {
				contextLabels[t] = matchLabel{text: "flag --" + f.Name, structural: true}
			}
		}
		for _, t := range searchTokens(f.Usage) {
			if _, ok := contextLabels[t]; !ok {
				contextLabels[t] = matchLabel{text: "--" + f.Name + " help", structural: false}
			}
		}
	}
	d.add(sfContext, searchTokens(strings.Join(context, " ")), func(tok string) matchLabel { return contextLabels[tok] })

	var values []string
	valueLabels := map[string]matchLabel{}
	d.slots = map[string]string{}
	for _, f := range c.Flags {
		if len(f.AllowedValues) > 0 {
			d.slots[f.Name] = strings.Join(f.AllowedValues, ",")
		}
	}
	for _, f := range c.Flags {
		vs := append(append([]string{}, f.AllowedValues...), sortedKeys(f.ValueAliases)...)
		for _, v := range vs {
			values = append(values, v)
			d.values = append(d.values, flagValue{flag: f.Name, value: v})
			for _, t := range searchTokens(v) {
				if _, ok := valueLabels[t]; !ok {
					valueLabels[t] = matchLabel{text: fmt.Sprintf("--%s accepts %s", f.Name, v), structural: true}
				}
			}
		}
	}
	d.add(sfValues, searchTokens(strings.Join(values, " ")), func(tok string) matchLabel { return valueLabels[tok] })
}

// taskFieldsOf indexes tasks' pages, sections and phrases on d, and records
// their groups.
func taskFieldsOf(d *rankDoc, tasks []taskInfo, withSections bool) {
	for _, t := range tasks {
		var grps []searchGroup
		for _, p := range t.UIPages {
			section, label := splitPage(p)
			if !withSections {
				label = p // a page with no command is named whole
			}
			toks := meaningfulTokens(label)
			d.add(sfPage, toks, fixedLabel(fmt.Sprintf("UI page %q", p), true))
			grps = append(grps, searchGroup{run: t.Run, tokens: toks})
			if withSections && section != "" {
				d.add(sfSection, meaningfulTokens(section), fixedLabel(fmt.Sprintf("UI section %q", section), true))
			}
		}
		for _, ph := range t.Phrases {
			toks := meaningfulTokens(ph)
			d.add(sfPhrase, toks, fixedLabel(fmt.Sprintf("task %q", ph), true))
			grps = append(grps, searchGroup{run: t.Run, tokens: toks})
		}
		d.groups = append(d.groups, grps...)
	}
}

// buildSummedCorpus is the summed ranker's index: a document per command, one
// per task, one per page with no command.
func buildSummedCorpus(tree commandTree) *rankCorpus {
	shorts := map[string]string{}
	for _, c := range tree.Commands {
		shorts[c.Path] = c.Short
	}
	corpus := &rankCorpus{}
	for i := range tree.Commands {
		c := &tree.Commands[i]
		if !searchable(c) {
			continue
		}
		d := rankDoc{key: c.Path, kind: docCommand, cmd: c}
		commandFields(&d, c, shorts, false)
		corpus.docs = append(corpus.docs, d)
		for _, t := range c.Tasks {
			td := rankDoc{key: c.Path, kind: docTask, cmd: c, run: t.Run}
			for _, f := range []searchFieldID{sfPage, sfSection, sfPhrase} {
				td.add(f, nil, nil) // present though empty: they count in each field's average
			}
			taskFieldsOf(&td, []taskInfo{t}, true)
			corpus.docs = append(corpus.docs, td)
		}
	}
	for i := range tree.NotInCLI {
		t := &tree.NotInCLI[i]
		d := rankDoc{key: notInCLIKey(t), kind: docNotInCLI, notInCLI: t}
		d.add(sfPage, nil, nil)
		d.add(sfPhrase, nil, nil)
		taskFieldsOf(&d, []taskInfo{*t}, false)
		corpus.docs = append(corpus.docs, d)
	}
	corpus.finish()
	return corpus
}

// buildBM25FCorpus is the BM25F ranker's index: one document per command,
// its tasks among its fields, and one per page with no command.
func buildBM25FCorpus(tree commandTree) *rankCorpus {
	shorts := map[string]string{}
	for _, c := range tree.Commands {
		shorts[c.Path] = c.Short
	}
	corpus := &rankCorpus{bm25f: true}
	for i := range tree.Commands {
		c := &tree.Commands[i]
		if !searchable(c) {
			continue
		}
		d := rankDoc{key: c.Path, kind: docCommand, cmd: c}
		commandFields(&d, c, shorts, true)
		taskFieldsOf(&d, c.Tasks, true)
		corpus.docs = append(corpus.docs, d)
	}
	for i := range tree.NotInCLI {
		t := &tree.NotInCLI[i]
		d := rankDoc{key: notInCLIKey(t), kind: docNotInCLI, notInCLI: t}
		taskFieldsOf(&d, []taskInfo{*t}, false)
		corpus.docs = append(corpus.docs, d)
	}
	corpus.finish()
	return corpus
}

// searchable leaves out groups, which run nothing, and search itself, whose
// help quotes the synonyms and so would match every query that uses one.
func searchable(c *commandInfo) bool {
	return c.Runnable && c.Path != "p202 search"
}

func notInCLIKey(t *taskInfo) string {
	return "(not in cli) " + strings.Join(append(append([]string{}, t.UIPages...), t.Phrases...), " / ")
}

func (c *rankCorpus) finish() {
	c.vocab = map[string]bool{}
	var total, count [numSearchFields]int
	for f := range c.fieldDF {
		c.fieldDF[f] = map[string]int{}
	}
	for _, d := range c.docs {
		for f := searchFieldID(0); f < numSearchFields; f++ {
			if !d.has[f] {
				continue
			}
			total[f] += d.length[f]
			count[f]++
			for t := range d.fields[f] {
				c.fieldDF[f][t]++
			}
		}
	}
	// The vocabulary in index order, as MiniSearch walks it.
	for _, d := range c.docs {
		for f := searchFieldID(0); f < numSearchFields; f++ {
			for _, t := range d.order[f] {
				if !c.vocab[t] {
					c.vocab[t] = true
					c.words = append(c.words, t)
				}
			}
		}
	}
	for f := range c.avg {
		n := count[f]
		if c.bm25f {
			n = len(c.docs)
		}
		if n > 0 {
			c.avg[f] = float64(total[f]) / float64(n)
		}
	}
}

func runeLen(s string) int { return utf8.RuneCountInString(s) }

// prefixFormWeight is MiniSearch's weight for an indexed word the query word
// begins: it falls slowly with the letters the query left off.
func prefixFormWeight(q, v string) float64 {
	lv, lq := float64(runeLen(v)), float64(runeLen(q))
	return prefixWeight * lv / (lv + 0.3*(lv-lq))
}

// fuzzyDistance is how many edits a query word may be from an indexed one:
// a fifth of its letters, rounded, at most maxFuzzy.
func fuzzyDistance(q string) int {
	return int(math.Min(maxFuzzy, math.Round(float64(runeLen(q))*fuzzyRatio)))
}

// known reports whether the index has the word, a synonym of it, or a word
// it begins; fuzzy matching is only for a word that is none of these.
func (c *rankCorpus) known(q string) bool {
	if c.vocab[q] {
		return true
	}
	for _, s := range searchSynonyms[q] {
		if c.vocab[s] {
			return true
		}
	}
	for _, v := range c.words {
		if v != q && strings.HasPrefix(v, q) {
			return true
		}
	}
	return false
}

type rankHit struct {
	score float64
	terms map[string]bool
}

func (h *rankHit) addTerm(q string) {
	if h.terms == nil {
		h.terms = map[string]bool{}
	}
	h.terms[q] = true
}

// bm25Plus is MiniSearch's per-field score.
func bm25Plus(tf, n, total int, fieldLen int, avg float64) float64 {
	idf := math.Log(1 + (float64(total-n)+0.5)/(float64(n)+0.5))
	if avg == 0 {
		avg = 1
	}
	return idf * (bm25D + float64(tf)*(bm25K+1)/(float64(tf)+bm25K*(1-bm25B+bm25B*float64(fieldLen)/avg)))
}

// summedTerm adds one form of a query word to every document holding it,
// field by field (MiniSearch's termResults).
func (c *rankCorpus) summedTerm(form string, weight float64, q string, hits map[int]*rankHit) {
	for f := searchFieldID(0); f < numSearchFields; f++ {
		n := c.fieldDF[f][form]
		if n == 0 || searchBoosts[f] == 0 {
			continue
		}
		for i := range c.docs {
			d := &c.docs[i]
			tf := d.fields[f][form]
			if tf == 0 {
				continue
			}
			h := hits[i]
			if h == nil {
				h = &rankHit{}
				hits[i] = h
			}
			h.score += weight * searchBoosts[f] * bm25Plus(tf, n, len(c.docs), d.length[f], c.avg[f])
			h.addTerm(q)
		}
	}
}

// summedSearch is MiniSearch's search over the summed corpus: each query word,
// its synonyms, the words it begins, and (for an unknown word) the words a
// fuzzy match reaches, each scored and summed.
func (c *rankCorpus) summedSearch(terms []string, prefix, fuzzy bool) map[int]*rankHit {
	hits := map[int]*rankHit{}
	for _, q := range terms {
		c.summedTerm(q, 1, q, hits)
		for _, s := range searchSynonyms[q] {
			c.summedTerm(s, synonymWeight, q, hits)
		}
		done := map[string]bool{q: true}
		if prefix {
			for _, v := range c.words {
				if v != q && strings.HasPrefix(v, q) {
					done[v] = true
					c.summedTerm(v, prefixFormWeight(q, v), q, hits)
				}
			}
		}
		known := c.vocab[q] || len(done) > 1
		for _, s := range searchSynonyms[q] {
			known = known || c.vocab[s]
		}
		if fuzzy && !known {
			if maxd := fuzzyDistance(q); maxd > 0 {
				for _, v := range c.words {
					if done[v] {
						continue
					}
					if dd := editDistance(q, v, maxd); dd > 0 && dd <= maxd {
						lv := float64(runeLen(v))
						c.summedTerm(v, fuzzyWeight*lv/(lv+float64(dd)), q, hits)
					}
				}
			}
		}
	}
	return hits
}

// forms are the words a query word reaches in the BM25F ranker, each with
// its weight, best weight kept.
func (c *rankCorpus) forms(q string) map[string]float64 {
	out := map[string]float64{q: 1}
	for _, s := range searchSynonyms[q] {
		out[s] = math.Max(out[s], synonymWeight)
	}
	for _, v := range c.words {
		if v != q && strings.HasPrefix(v, q) {
			out[v] = math.Max(out[v], prefixFormWeight(q, v))
		}
	}
	if !c.known(q) {
		if maxd := fuzzyDistance(q); maxd > 0 {
			for _, v := range c.words {
				if _, ok := out[v]; ok {
					continue
				}
				if dd := editDistance(q, v, maxd); dd > 0 && dd <= maxd {
					lv := float64(runeLen(v))
					out[v] = fuzzyWeight * lv / (lv + float64(dd))
				}
			}
		}
	}
	return out
}

// idf is the BM25F ranker's rarity of a word: the documents holding any of
// its forms in any field.
func (c *rankCorpus) idf(forms map[string]float64) (float64, int) {
	n := 0
	for _, d := range c.docs {
		found := false
		for f := searchFieldID(0); f < numSearchFields && !found; f++ {
			for t := range forms {
				if d.fields[f][t] > 0 {
					found = true
					break
				}
			}
		}
		if found {
			n++
		}
	}
	return math.Log(1 + (float64(len(c.docs)-n)+0.5)/(float64(n)+0.5)), n
}

// bm25fSearch scores each document per query word: the word's best form in
// each field, weighted and length-normalised, summed over fields, then
// saturated once.
func (c *rankCorpus) bm25fSearch(terms []string) map[int]*rankHit {
	hits := map[int]*rankHit{}
	for _, q := range terms {
		fm := c.forms(q)
		idf, n := c.idf(fm)
		if n == 0 {
			continue
		}
		for i := range c.docs {
			d := &c.docs[i]
			tfp := 0.0
			for f := searchFieldID(0); f < numSearchFields; f++ {
				best := 0.0
				for t, w := range fm {
					if tf := d.fields[f][t]; tf > 0 && w*float64(tf) > best {
						best = w * float64(tf)
					}
				}
				if best == 0 {
					continue
				}
				avg := c.avg[f]
				if avg == 0 {
					avg = 1
				}
				b := searchLengthNorm[f]
				tfp += searchBoosts[f] * best / (1 - b + b*float64(d.length[f])/avg)
			}
			if tfp > 0 {
				h := hits[i]
				if h == nil {
					h = &rankHit{}
					hits[i] = h
				}
				h.score += idf * tfp * (bm25K + 1) / (tfp + bm25K)
				h.addTerm(q)
			}
		}
	}
	return hits
}

// editDistance is Levenshtein's, in runes, giving up past max.
func editDistance(a, b string, max int) int {
	ra, rb := []rune(a), []rune(b)
	if d := len(ra) - len(rb); d > max || -d > max {
		return max + 1
	}
	prev := make([]int, len(rb)+1)
	for j := range prev {
		prev[j] = j
	}
	for i := 1; i <= len(ra); i++ {
		cur := make([]int, len(rb)+1)
		cur[0] = i
		best := cur[0]
		for j := 1; j <= len(rb); j++ {
			cost := 1
			if ra[i-1] == rb[j-1] {
				cost = 0
			}
			cur[j] = min(prev[j]+1, cur[j-1]+1, prev[j-1]+cost)
			best = min(best, cur[j])
		}
		if best > max {
			return max + 1
		}
		prev = cur
	}
	return prev[len(rb)]
}

// rankedCommand is one ranker's answer for a command (or a page with no
// command).
type rankedCommand struct {
	key      string
	score    float64
	try      string
	terms    map[string]bool
	cmd      *commandInfo
	notInCLI *taskInfo
	doc      *rankDoc // the BM25F document, for "matched"
}

// coveredBy reports whether every word of a page or phrase is one of the
// query's words, or a synonym of one, with at least one word as written:
// "realtime traffic" names "realtime clicks", but "click" alone does not name
// the Visitors page through a synonym, nor "referrer" the Referers page.
func coveredBy(group []string, terms map[string]bool) bool {
	if len(group) == 0 {
		return false
	}
	exact := false
	for _, tok := range group {
		if terms[tok] {
			exact = true
			continue
		}
		found := false
		for _, syn := range searchSynonyms[tok] {
			if terms[syn] {
				found = true
				break
			}
		}
		if !found {
			return false
		}
	}
	return exact
}

// namedFirst reports whether group a should supply the command line over b:
// the longer of two named groups, and of two as long, the one the query
// names first ("which IP addresses clicked on my keyword" is a breakdown by
// IP, not by keyword).
func namedFirst(a []string, b []string, terms []string) bool {
	if len(a) != len(b) {
		return len(a) > len(b)
	}
	return firstPosition(a, terms) < firstPosition(b, terms)
}

func firstPosition(group, terms []string) int {
	best := len(terms)
	for i, t := range terms {
		if containsString(group, t) && i < best {
			best = i
		}
	}
	return best
}

// tryLine is the command line to offer: the named task's line, then a flag
// value for each query word it does not already set, one flag per word.
func tryLine(key, run string, values []flagValue, slots map[string]string, terms []string, termSet map[string]bool) string {
	base := run
	if base == "" {
		base = key
	}
	set := taskFlags(base)
	setSlots := map[string]bool{}
	for f := range set {
		if s := slots[f]; s != "" {
			setSlots[s] = true
		}
	}
	// One flag per word, and a word the task's line already sets a flag to
	// is spent: another flag that takes the same word is not set to it too.
	used := map[string]bool{}
	fields := strings.Fields(run)
	for i := 0; i+1 < len(fields); i++ {
		if strings.HasPrefix(fields[i], "--") && !strings.HasPrefix(fields[i+1], "-") {
			for _, t := range searchTokens(fields[i+1]) {
				used[t] = true
			}
		}
	}
	for _, t := range terms {
		if used[t] {
			continue
		}
		for _, fv := range values {
			vt := searchTokens(fv.value)
			if set[fv.flag] || setSlots[slots[fv.flag]] || len(vt) == 0 || !containsString(vt, t) {
				continue
			}
			all := true
			for _, x := range vt {
				all = all && termSet[x]
			}
			if !all {
				continue
			}
			base += " --" + fv.flag + " " + fv.value
			set[fv.flag] = true
			if s := slots[fv.flag]; s != "" {
				setSlots[s] = true
			}
			for _, x := range vt {
				used[x] = true
			}
			break
		}
	}
	if base == key {
		return ""
	}
	return base
}

func sortRanked(out []rankedCommand) {
	sort.SliceStable(out, func(i, j int) bool {
		if out[i].score != out[j].score {
			return out[i].score > out[j].score
		}
		return out[i].key < out[j].key
	})
}

func termSetOf(terms []string) map[string]bool {
	s := map[string]bool{}
	for _, t := range terms {
		s[t] = true
	}
	return s
}

// rankSummed merges each command's document with its best task document,
// adds a grouping cue's share, and multiplies by the query words matched.
func rankSummed(c *rankCorpus, terms []string, grouping bool) []rankedCommand {
	hits := c.summedSearch(terms, true, true)
	var group map[int]*rankHit
	if grouping {
		group = c.summedSearch([]string{groupingTerm}, false, false)
	}
	termSet := termSetOf(terms)
	type entry struct {
		cmdHit, taskHit *rankHit
		run             string
		runGroup        []string
		g               float64
		doc             *rankDoc
		first           *rankDoc
	}
	entries := map[string]*entry{}
	var order []string
	for i := range c.docs { // documents in index order, so ties settle as the prototype's did
		h := hits[i]
		if h == nil {
			continue
		}
		d := &c.docs[i]
		e := entries[d.key]
		if e == nil {
			e = &entry{first: d}
			entries[d.key] = e
			order = append(order, d.key)
		}
		if d.kind == docCommand {
			e.cmdHit, e.doc = h, d
			continue
		}
		if e.taskHit == nil || h.score > e.taskHit.score {
			e.taskHit = h
		}
		for _, g := range d.groups {
			if coveredBy(g.tokens, termSet) && (e.runGroup == nil || namedFirst(g.tokens, e.runGroup, terms)) {
				e.run, e.runGroup = g.run, g.tokens
			}
		}
	}
	for i, h := range group {
		d := &c.docs[i]
		if e := entries[d.key]; e != nil && d.kind == docCommand && h.score > e.g {
			e.g = h.score
		}
	}
	var out []rankedCommand
	for _, key := range order {
		e := entries[key]
		raw := groupingWeight * e.g
		matched := map[string]bool{}
		for _, h := range []*rankHit{e.cmdHit, e.taskHit} {
			if h == nil {
				continue
			}
			raw += h.score
			for t := range h.terms {
				matched[t] = true
			}
		}
		r := rankedCommand{key: key, score: raw * float64(len(matched)), terms: matched, cmd: e.first.cmd, notInCLI: e.first.notInCLI}
		if e.doc != nil {
			r.try = tryLine(key, e.run, e.doc.values, e.doc.slots, terms, termSet)
		} else {
			r.try = e.run
		}
		out = append(out, r)
	}
	sortRanked(out)
	return out
}

// rankBM25F adds a grouping cue's share and a whole-label bonus, and
// multiplies by the query words matched.
func rankBM25F(c *rankCorpus, terms []string, grouping bool) []rankedCommand {
	hits := c.bm25fSearch(terms)
	var group map[int]*rankHit
	if grouping {
		group = c.bm25fSearch([]string{groupingTerm})
	}
	termSet := termSetOf(terms)
	var out []rankedCommand
	for i := range c.docs {
		h := hits[i]
		if h == nil {
			continue
		}
		d := &c.docs[i]
		raw := h.score
		if g := group[i]; g != nil {
			raw += groupingWeight * g.score
		}
		run, whole := "", 0.0
		var runGroup []string
		for _, g := range d.groups {
			if !coveredBy(g.tokens, termSet) {
				continue
			}
			rarity := 0.0
			for _, t := range g.tokens {
				idf, _ := c.idf(map[string]float64{t: 1})
				rarity += idf
			}
			whole = math.Max(whole, rarity)
			if runGroup == nil || namedFirst(g.tokens, runGroup, terms) {
				run, runGroup = g.run, g.tokens
			}
		}
		raw += wholeWeight * whole * (bm25K + 1)
		r := rankedCommand{key: d.key, score: raw * float64(len(h.terms)), terms: h.terms, cmd: d.cmd, notInCLI: d.notInCLI, doc: d}
		if d.kind == docCommand {
			r.try = tryLine(d.key, run, d.values, d.slots, terms, termSet)
		}
		out = append(out, r)
	}
	sortRanked(out)
	return out
}

// fusedCommand is a command in the fused ranking.
type fusedCommand struct {
	rankedCommand
	fused     float64
	coverage  float64
	bm25fRank int
}

// fuseRankings is reciprocal rank fusion of the two rankers' top tens. The
// BM25F ranker's command line wins when both offer one: it sets a flag once.
func fuseRankings(summed, bm25f []rankedCommand, terms []string) []fusedCommand {
	by := map[string]*fusedCommand{}
	var keys []string
	for _, list := range [][]rankedCommand{summed, bm25f} {
		for i, r := range list {
			if i >= fusedDepth {
				break
			}
			f := by[r.key]
			if f == nil {
				f = &fusedCommand{rankedCommand: r}
				f.terms = map[string]bool{} // its own: the ranker's map is not to grow
				by[r.key] = f
				keys = append(keys, r.key)
			}
			f.fused += 1 / (fusionK + float64(i+1))
			if f.try == "" {
				f.try = r.try
			}
			for t := range r.terms {
				f.terms[t] = true
			}
		}
	}
	for i, r := range bm25f {
		if i >= fusedDepth {
			break
		}
		f := by[r.key]
		if r.try != "" {
			f.try = r.try
		}
		f.doc = r.doc
		f.bm25fRank = i + 1
	}
	out := make([]fusedCommand, 0, len(keys))
	for _, k := range keys {
		f := by[k]
		f.coverage = float64(len(f.terms)) / float64(len(terms))
		out = append(out, *f)
	}
	// Two commands each ranked first by one ranker tie; the one matching
	// more of the query, then BM25F's (which weighs a page or phrase named
	// in full) goes first, not the one whose name sorts first.
	rank := func(f fusedCommand) int {
		if f.bm25fRank == 0 {
			return fusedDepth + 1
		}
		return f.bm25fRank
	}
	sort.SliceStable(out, func(i, j int) bool {
		if out[i].fused != out[j].fused {
			return out[i].fused > out[j].fused
		}
		if out[i].coverage != out[j].coverage {
			return out[i].coverage > out[j].coverage
		}
		if rank(out[i]) != rank(out[j]) {
			return rank(out[i]) < rank(out[j])
		}
		return out[i].key < out[j].key
	})
	return out
}

// matchedReasons says, for each query word, where the command matched it:
// the strongest structural field (a name, alias, flag, value, page or
// phrase) when there is one, else the strongest prose.
func matchedReasons(c *rankCorpus, d *rankDoc, terms []string) []string {
	if d == nil {
		return nil
	}
	var out []string
	for _, q := range terms {
		fm := c.forms(q)
		type pick struct {
			label matchLabel
			form  string
			score float64
		}
		var bestS, bestP *pick
		for f := searchFieldID(0); f < numSearchFields; f++ {
			for t, w := range fm {
				tf := d.fields[f][t]
				if tf == 0 {
					continue
				}
				s := searchBoosts[f] * w * float64(tf)
				l, ok := d.labels[f][t]
				if !ok || l.text == "" {
					continue
				}
				p := &pick{label: l, form: t, score: s}
				if l.structural {
					if bestS == nil || s > bestS.score || (s == bestS.score && t < bestS.form) {
						bestS = p
					}
				} else if bestP == nil || s > bestP.score || (s == bestP.score && t < bestP.form) {
					bestP = p
				}
			}
		}
		p := bestS
		if p == nil {
			p = bestP
		}
		if p == nil {
			continue
		}
		reason := p.label.text
		if !p.label.structural {
			reason = fmt.Sprintf("%s mentions %q", p.label.text, p.form)
		}
		if p.form != q {
			reason += fmt.Sprintf(" (for %q)", q)
		}
		if !containsString(out, reason) {
			out = append(out, reason)
		}
	}
	return out
}
