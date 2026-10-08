package cmd

import (
	"fmt"
	"io"
	"math"
	"os"
	"strings"
	"unicode"

	"github.com/spf13/cobra"
)

// `p202 search <words>` finds the command for a task: an offline index over
// the same records `p202 commands --json` prints, ranked by search_rank.go.
// Like Cloudflare's `cf cli search` it hands back candidates, best first,
// for the caller to check with --help or --dry-run; unlike it, each carries
// how much of the query it matched, and good_match says whether the first
// is a confident answer.

const (
	// confidentCoverage is the share of the query's words the first result
	// must match to be a confident answer. On 137 development queries (the
	// UI's page names, ways of asking for Spy, the agent-eval asks, phrasings
	// written before tuning, typos, the queries search_test pins) 90 first
	// results reach it and 84 of those are right; 30 right answers fall below
	// it, which is why it is a flag and not a refusal. Sets sealed until
	// measured hold it less well: 13 right of 19 on 30 phrasings, 24 of 30 on
	// 36 tasks an agent phrased, where 2 of the 4 tasks the CLI cannot do
	// also reached it. The
	// score says little: fusion scores by rank, and the median right and
	// wrong first results score 0.0328 and 0.0325. Requiring the first to
	// beat the runner-up's coverage kept 35 of the 92: many right answers tie
	// with a neighbour that also matches every word.
	confidentCoverage  = 0.75
	defaultSearchLimit = 5
)

// weakMatchHint is what a search with no confident answer says to do next.
const weakMatchHint = "None of these? `p202 commands --brief` lists every command with the web UI page it does."

// searchStopwords carry no meaning for finding a command. They are held
// stemmed (searchStopwordSet), since a query word is stemmed before it is
// compared: "this" is "thi" by then, and was kept as a search term.
var searchStopwords = []string{
	"a", "an", "the", "by", "per", "of", "for", "to", "in", "on", "at", "with", "from", "into", "as", "about",
	"my", "me", "i", "we", "our", "us", "you", "your", "it", "its", "they", "them", "their", "there",
	"how", "do", "does", "did", "done", "can", "what", "which", "is", "are", "was", "were", "be", "been",
	"has", "have", "had", "will", "would", "should", "could", "please", "just", "so", "if", "then",
	"and", "or", "all", "each", "every", "any", "some", "want", "need", "that", "this", "p202",
}

var searchStopwordSet = func() map[string]bool {
	out := map[string]bool{}
	for _, w := range searchStopwords {
		out[stemWord(w)] = true
	}
	return out
}()

// searchSynonymGroups are words an operator uses for the same thing. Each
// word in a group also finds the others.
var searchSynonymGroups = [][]string{
	{"referrer", "referer"},
	{"traffic", "click", "visit"},
	{"offer", "campaign"},
	{"dead", "broken", "retired", "unreachable"},
	{"undo", "revert", "rollback", "restore"},
	{"link", "url"},
	{"conversion", "lead"},
	{"revenue", "income"},
	{"profit", "net"},
	{"cost", "spend"},
	{"delete", "remove"},
	{"create", "add", "new"},
	// "change my timezone", "edit a campaign": the words people use for an
	// update. "change" is also the staged-changes command's name, which a
	// direct match still ranks above a synonym.
	{"update", "edit", "modify", "change", "set"},
	{"server", "instance", "install"},
	{"breakdown", "group", "dimension"},
	{"geo", "country"},
	{"lp", "landing"},
	{"source", "ppc"},
	{"login", "signin"},
}

var searchSynonyms = func() map[string][]string {
	out := map[string][]string{}
	for _, group := range searchSynonymGroups {
		for _, w := range group {
			for _, other := range group {
				if other != w {
					out[stemWord(w)] = append(out[stemWord(w)], stemWord(other))
				}
			}
		}
	}
	return out
}()

// searchCompounds join two words people write apart, or with a hyphen,
// into one: "real time", "real-time" and "realtime" are one word, where
// "time" alone matched every command with a --time-from flag. The pair is
// read after stemming ("sub ids" is "subid"), in the query and in every
// indexed text alike.
var searchCompounds = map[[2]string]string{
	{"real", "time"}: "realtime",
	{"sign", "in"}:   "signin",
	{"log", "in"}:    "login",
	{"sub", "id"}:    "subid",
	{"e", "mail"}:    "email",
	{"web", "hook"}:  "webhook",
	{"post", "back"}: "postback",
}

// stemWord folds the plural forms a query and a description may disagree
// on: links -> link, entries -> entry, ips -> ip. Three letters is long
// enough: "IPs" and "ads" are plurals, and a short word that is not one
// ("ios", "sms") folds the same way on both sides, so it still matches
// itself.
func stemWord(w string) string {
	switch {
	case len(w) > 4 && strings.HasSuffix(w, "ies"):
		return w[:len(w)-3] + "y"
	case len(w) >= 3 && strings.HasSuffix(w, "s") && !strings.HasSuffix(w, "ss"):
		return w[:len(w)-1]
	}
	return w
}

// searchTokens splits text into lower-case, stemmed words, with compounds
// joined.
func searchTokens(text string) []string {
	fields := strings.FieldsFunc(strings.ToLower(text), func(r rune) bool {
		return !unicode.IsLetter(r) && !unicode.IsDigit(r)
	})
	stems := make([]string, len(fields))
	for i, f := range fields {
		stems[i] = stemWord(f)
	}
	out := make([]string, 0, len(stems))
	for i := 0; i < len(stems); i++ {
		if i+1 < len(stems) {
			if joined, ok := searchCompounds[[2]string{stems[i], stems[i+1]}]; ok {
				out = append(out, joined)
				i++
				continue
			}
		}
		out = append(out, stems[i])
	}
	return out
}

// meaningfulTokens are the words of text a query would search for: no
// stopwords, no single letters (the "s" of "what's"), no repeats.
func meaningfulTokens(text string) []string {
	var out []string
	seen := map[string]bool{}
	for _, t := range searchTokens(text) {
		if len(t) < 2 || searchStopwordSet[t] || seen[t] {
			continue
		}
		seen[t] = true
		out = append(out, t)
	}
	return out
}

// splitPage separates a page under a section ("Analyze › Text Ads") into
// its section and label; a top-level page has no section.
func splitPage(page string) (section, label string) {
	if i := strings.Index(page, "›"); i >= 0 {
		return strings.TrimSpace(page[:i]), strings.TrimSpace(page[i+len("›"):])
	}
	return "", page
}

// searchQueryTerms turns the query into the words searched for.
func searchQueryTerms(words []string) []string {
	terms, _ := parseSearchQuery(words)
	return terms
}

// groupingCues ask for a breakdown ("clicks per campaign", "stats by
// country") without naming it; they add groupingTerm as a bonus word.
var groupingCues = map[string]bool{"per": true, "by": true, "grouped": true}

const groupingTerm = "breakdown"

// parseSearchQuery returns the words searched for and whether the query
// asked for a grouping.
func parseSearchQuery(words []string) ([]string, bool) {
	query := strings.Join(words, " ")
	grouping := false
	for _, t := range searchTokens(query) {
		if groupingCues[t] {
			grouping = true
		}
	}
	terms := meaningfulTokens(query)
	return terms, grouping && !containsString(terms, groupingTerm)
}

type searchResult struct {
	Command string  `json:"command"`
	Short   string  `json:"short"`
	Score   float64 `json:"score"`
	// Coverage is the share of the query's words the command matched.
	Coverage float64  `json:"coverage"`
	Matched  []string `json:"matched"`
	Try      string   `json:"try,omitempty"`
}

// searchAnswer is what a search prints: candidates, best first, and whether
// the first is a confident answer.
type searchAnswer struct {
	Query string   `json:"query"`
	Terms []string `json:"terms"`
	// UnknownTerms are the query's words no command's text has, as written,
	// as a synonym or as the start of a word: the commands lack the thing or
	// call it something else ("approve" where they say apply), or, from a
	// person, it is a typo. Not a verdict: on 40 tasks an agent phrased, it
	// named a word in 6 the CLI does under another name and in none of the 4
	// it does not do.
	UnknownTerms []string `json:"unknown_terms"`
	GoodMatch    bool     `json:"good_match"`
	// Hint is the next step when the first result is not a confident answer.
	// A table prints it below the list; an agent gets JSON, so it is here too,
	// or an agent holding weak candidates is never pointed at the catalog.
	Hint    string         `json:"hint,omitempty"`
	Results []searchResult `json:"results"`

	// notInCLI is the UI page (or task) no command does, when the query
	// names it.
	notInCLI *taskInfo
}

func runSearch(tree commandTree, words []string, limit int) searchAnswer {
	terms, grouping := parseSearchQuery(words)
	answer := searchAnswer{Query: strings.Join(words, " "), Terms: terms, UnknownTerms: []string{}, Results: []searchResult{}}
	if len(terms) == 0 {
		return answer
	}
	summed, bm25f := buildSummedCorpus(tree), buildBM25FCorpus(tree)
	terms, answer.UnknownTerms = foldTerms(bm25f, terms)
	answer.Terms = terms
	fused := fuseRankings(rankSummed(summed, terms, grouping), rankBM25F(bm25f, terms, grouping), terms)
	docs := map[string]*rankDoc{}
	for i := range bm25f.docs {
		docs[bm25f.docs[i].key] = &bm25f.docs[i]
	}
	// "No command does this" is a refusal, so it answers only a query that
	// names the page or phrase in full: "is the server up" reached the
	// upgrade entry through a synonym (server → install upgrade) and a prefix
	// (up → upgrade), and was told the CLI cannot do what `system health`
	// does.
	if len(fused) > 0 && fused[0].notInCLI != nil && namesAGroup(docs[fused[0].key], termSetOf(terms)) {
		answer.notInCLI = fused[0].notInCLI
		return answer
	}
	for _, f := range fused {
		if f.notInCLI != nil || f.cmd == nil {
			continue // a page with no command is an answer only when it is the best one
		}
		answer.Results = append(answer.Results, searchResult{
			Command:  f.key,
			Short:    f.cmd.Short,
			Score:    math.Round(f.fused*1e4) / 1e4,
			Coverage: math.Round(f.coverage*100) / 100,
			Matched:  matchedReasons(bm25f, docs[f.key], terms),
			Try:      f.try,
		})
		if len(answer.Results) == limit {
			break
		}
	}
	answer.GoodMatch = len(answer.Results) > 0 && answer.Results[0].Coverage >= confidentCoverage
	if !answer.GoodMatch {
		answer.Hint = weakMatchHint
	}
	return answer
}

// foldTerms puts an inflected word the index does not know into the form it
// does ("imported" → import), drops a repeat that makes, and lists the words
// that still match nothing. Both corpora index the same text, so either one's
// vocabulary answers.
func foldTerms(c *rankCorpus, terms []string) (folded, unknown []string) {
	unknown = []string{}
	seen := map[string]bool{}
	for _, t := range terms {
		if !c.known(t) {
			if l := c.lemmaOf(t); l != "" {
				t = l
			} else {
				unknown = append(unknown, t)
			}
		}
		if !seen[t] {
			seen[t] = true
			folded = append(folded, t)
		}
	}
	return folded, unknown
}

// namesAGroup reports whether the query names one of d's pages or phrases in
// full.
func namesAGroup(d *rankDoc, terms map[string]bool) bool {
	if d == nil {
		return false
	}
	for _, g := range d.groups {
		if coveredBy(g.tokens, terms) {
			return true
		}
	}
	return false
}

// searchError is the failure for a search that has no answer to hand back:
// nothing matched, or the query names a page no command does.
func searchError(a searchAnswer) error {
	if t := a.notInCLI; t != nil {
		if len(t.UIPages) > 0 {
			return validationError("No p202 command does what the web UI's %q page does: %s.", t.UIPages[0], t.Instead).WithHint("%s", t.Hint)
		}
		return validationError("No p202 command for %q: %s.", a.Query, t.Instead).WithHint("%s", t.Hint)
	}
	if len(a.UnknownTerms) > 0 {
		return validationError("No command matches %q: no command mentions %s.", a.Query, strings.Join(a.UnknownTerms, ", ")).
			WithHint("Read `p202 commands --brief` (every command on one line, with the web UI page it does) and choose, or describe the task in other words.")
	}
	return validationError("No command matches %q.", a.Query).
		WithHint("Read `p202 commands --brief` (every command on one line, with the web UI page it does) and choose, or describe the task in other words.")
}

// quietWeakError is --quiet's answer when the first result is not a
// confident one: --quiet prints command paths alone, so a script that takes
// its first line would run a guess.
func quietWeakError(a searchAnswer) error {
	var names []string
	for _, r := range a.Results {
		names = append(names, fmt.Sprintf("`%s` (%s)", r.Command, r.Short))
	}
	return validationError("No confident match for %q; --quiet prints a command only for one.", a.Query).
		WithHint("The candidates: %s. Run it without --quiet (or with --json) to see what each matched, or read `p202 commands --brief` and choose.", strings.Join(names, "; "))
}

var searchCmd = &cobra.Command{
	Use:   "search <what you want to do>",
	Short: "Find the command for a task: ranks commands, flags, flag values and UI pages by your words (offline)",
	Long: "Searches every command's name, aliases, description, examples, flags, the values\n" +
		"its flags accept, and the tasks it runs: the web UI page that does the same (\"Spy\",\n" +
		"\"Update CPC\") and the words people use for it (\"realtime traffic\"). Lists the best\n" +
		"matches (5 by default), best first, each with why it matched, the share of your\n" +
		"words it matched (coverage) and a command line to try. Plural forms, common\n" +
		"synonyms (referrer/referer, offer/campaign, dead/broken, undo/revert, link/url),\n" +
		"inflected forms (imported, charged) and split words (real time, real-time) match.\n" +
		"Misspellings are not corrected: a word no command mentions is listed above the\n" +
		"results (unknown_terms in JSON); the commands lack it or call it something else.\n" +
		"No server is contacted.\n\n" +
		"good_match says whether the first is a confident answer (it matched at least three\n" +
		"quarters of your words); a table says so above the list when it is not, and\n" +
		"--quiet prints a command only for a confident answer, so a script never runs a\n" +
		"guess. It fails (exit 1) only when nothing matches, or when the words name a web UI\n" +
		"page no command does, and then it says where that is done instead.\n\n" +
		"Search matches words, not meaning: on 36 tasks as an agent phrased them, written\n" +
		"before this version was measured, the first result was right for 27 and the first\n" +
		"five held the right command for 34. A model reading `p202 commands --brief` chose\n" +
		"right first for 34: an agent choosing a command does better reading the catalog.",
	Example: "  p202 search breakdown by browser\n  p202 search realtime traffic\n  p202 search dead links --json\n  p202 search undo url change",
	Args: func(cmd *cobra.Command, args []string) error {
		if len(searchQueryTerms(args)) == 0 {
			return validationError("search needs words that describe the task").
				WithHint("Say what you want to do, e.g. `p202 search clicks per campaign`; `p202 commands` lists every command.")
		}
		return nil
	},
	RunE: func(cmd *cobra.Command, args []string) error {
		limit, _ := cmd.Flags().GetInt("limit")
		if limit < 1 {
			return validationError("--limit must be at least 1, got %d", limit).WithHint("The default is %d.", defaultSearchLimit)
		}
		if csvOutput {
			return validationError("p202 search has no CSV form").WithHint("Use --json, or --quiet for command paths only.")
		}
		answer := runSearch(buildCommandTree(cmd.Root()), args, limit)
		if answer.notInCLI != nil || len(answer.Results) == 0 {
			return searchError(answer)
		}
		if quietOutput && !jsonOutput && !ndjsonOutput && !answer.GoodMatch {
			return quietWeakError(answer)
		}
		return writeSearchAnswer(os.Stdout, answer)
	},
}

func writeSearchAnswer(w io.Writer, a searchAnswer) error {
	switch {
	case jsonOutput || ndjsonOutput:
		return writeJSONNoEscape(w, a)
	case quietOutput:
		for _, r := range a.Results {
			fmt.Fprintln(w, r.Command)
		}
		return nil
	}
	if len(a.UnknownTerms) > 0 {
		fmt.Fprintf(w, "No command mentions: %s.\n", strings.Join(a.UnknownTerms, ", "))
	}
	if !a.GoodMatch {
		fmt.Fprintf(w, "No confident match for %q (the first matches %.0f%% of its words); the closest:\n\n", a.Query, a.Results[0].Coverage*100)
		defer fmt.Fprintln(w, "\n"+a.Hint)
	}
	for _, r := range a.Results {
		fmt.Fprintf(w, "%s — %s\n", r.Command, r.Short)
		fmt.Fprintf(w, "    matched: %s\n", strings.Join(r.Matched, "; "))
		if r.Try != "" {
			fmt.Fprintf(w, "    try: %s\n", r.Try)
		}
	}
	return nil
}

func init() {
	searchCmd.Flags().Int("limit", defaultSearchLimit, "Most results to show")
	rootCmd.AddCommand(searchCmd)
}
