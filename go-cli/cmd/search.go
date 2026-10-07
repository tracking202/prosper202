package cmd

import (
	"fmt"
	"io"
	"os"
	"sort"
	"strings"
	"unicode"

	"github.com/spf13/cobra"
)

// `p202 search <words>` ranks commands by how well the words match their
// name, aliases, descriptions, examples, flags and allowed flag values: an
// offline index over the same records `p202 commands --json` prints.

// Field weights: what a word matching there says about the command.
const (
	weightName    = 10.0 // the command's own name ("breakdown")
	weightAlias   = 8.0
	weightShort   = 6.0
	weightValue   = 6.0 // a value a flag accepts ("browser")
	weightFlag    = 5.0 // a flag's name
	weightParent  = 3.0 // a parent command's name ("report")
	weightUsage   = 2.0 // a flag's help text
	weightLong    = 1.5
	weightExample = 1.0

	synonymFactor   = 0.8 // a match through searchSynonyms
	maxBreadthBonus = 1.5 // most a word's other matches in one command add
	prefixFactor    = 0.5 // the word is a prefix of the indexed one

	// A result is a good match (searchResult.good) when it scores at least
	// this; otherwise search says so and shows the closest.
	goodMatchScore    = 6.0
	goodMatchCoverage = 0.5
	closestShown      = 3
)

// searchStopwords carry no meaning for finding a command.
var searchStopwords = map[string]bool{
	"a": true, "an": true, "the": true, "by": true, "per": true, "of": true, "for": true, "to": true,
	"in": true, "on": true, "at": true, "with": true, "from": true, "my": true, "me": true, "i": true,
	"how": true, "do": true, "can": true, "what": true, "which": true, "is": true, "are": true,
	"and": true, "or": true, "all": true, "each": true, "every": true, "want": true, "need": true,
	"it": true, "that": true, "this": true, "into": true, "p202": true,
}

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

// stemWord folds the plural forms a query and a description may disagree
// on: links -> link, entries -> entry.
func stemWord(w string) string {
	switch {
	case len(w) > 4 && strings.HasSuffix(w, "ies"):
		return w[:len(w)-3] + "y"
	case len(w) > 3 && strings.HasSuffix(w, "s") && !strings.HasSuffix(w, "ss"):
		return w[:len(w)-1]
	}
	return w
}

// searchTokens splits text into lower-case, stemmed words.
func searchTokens(text string) []string {
	fields := strings.FieldsFunc(strings.ToLower(text), func(r rune) bool {
		return !unicode.IsLetter(r) && !unicode.IsDigit(r)
	})
	out := make([]string, 0, len(fields))
	for _, f := range fields {
		out = append(out, stemWord(f))
	}
	return out
}

// searchField is one indexed piece of a command.
type searchField struct {
	weight float64
	tokens map[string]bool
	label  string // how a match here is reported
	flag   string // the flag a value field belongs to
	value  string // the value (or value alias) itself
	// structural: a name, alias, flag or flag value rather than prose.
	structural bool
}

type searchDoc struct {
	cmd    commandInfo
	fields []searchField
}

func tokenSet(text string) map[string]bool {
	set := map[string]bool{}
	for _, t := range searchTokens(text) {
		set[t] = true
	}
	return set
}

func buildSearchIndex(tree commandTree) []searchDoc {
	docs := make([]searchDoc, 0, len(tree.Commands))
	for _, c := range tree.Commands {
		// search itself is left out: its help quotes the synonyms, so it
		// would match every query that uses one.
		segments := strings.Fields(c.Path)[1:]
		if !c.Runnable || (len(segments) == 1 && segments[0] == "search") {
			continue
		}
		name := segments[len(segments)-1]
		d := searchDoc{cmd: c}
		add := func(weight float64, text, label string, structural bool) {
			if text != "" {
				d.fields = append(d.fields, searchField{weight: weight, tokens: tokenSet(text), label: label, structural: structural})
			}
		}
		add(weightName, name, fmt.Sprintf("command name %q", name), true)
		for _, parent := range segments[:len(segments)-1] {
			add(weightParent, parent, fmt.Sprintf("under %q", parent), true)
		}
		for _, alias := range c.Aliases {
			add(weightAlias, alias, fmt.Sprintf("alias %q", alias), true)
		}
		add(weightShort, c.Short, "description", false)
		add(weightLong, c.Long, "long description", false)
		add(weightExample, c.Example, "example", false)
		for _, f := range c.Flags {
			add(weightFlag, f.Name, "flag --"+f.Name, true)
			add(weightUsage, f.Usage, "--"+f.Name+" help", false)
			for _, v := range f.AllowedValues {
				d.fields = append(d.fields, searchField{weight: weightValue, tokens: tokenSet(v), structural: true,
					label: fmt.Sprintf("--%s accepts %s", f.Name, v), flag: f.Name, value: v})
			}
			for _, alias := range sortedKeys(f.ValueAliases) {
				d.fields = append(d.fields, searchField{weight: weightValue, tokens: tokenSet(alias), structural: true,
					label: fmt.Sprintf("--%s accepts %s (= %s)", f.Name, alias, f.ValueAliases[alias]), flag: f.Name, value: alias})
			}
		}
		docs = append(docs, d)
	}
	return docs
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
	var terms []string
	grouping := false
	seen := map[string]bool{}
	for _, t := range searchTokens(strings.Join(words, " ")) {
		if groupingCues[t] {
			grouping = true
		}
		if searchStopwords[t] || seen[t] {
			continue
		}
		seen[t] = true
		terms = append(terms, t)
	}
	return terms, grouping && !seen[groupingTerm]
}

type searchResult struct {
	Command string   `json:"command"`
	Short   string   `json:"short"`
	Score   float64  `json:"score"`
	Matched []string `json:"matched"`
	Try     string   `json:"try,omitempty"`

	coverage   float64
	structural bool // a word matched a name, alias, flag or flag value, not only prose
}

// good reports whether r is a match worth acting on: a high enough score
// over at least half the words, found in the command's structure or in
// more than one word of its prose.
func (r searchResult) good() bool {
	return r.Score >= goodMatchScore && r.coverage >= goodMatchCoverage && (r.structural || len(r.Matched) > 1)
}

type searchAnswer struct {
	Query     string         `json:"query"`
	Terms     []string       `json:"terms"`
	GoodMatch bool           `json:"good_match"`
	Note      string         `json:"note,omitempty"`
	Results   []searchResult `json:"results"`
}

// fieldMatch is how strongly term matches a field: 1 for the word itself,
// less through a synonym or as a prefix, 0 for no match; and the word
// that matched.
func fieldMatch(f searchField, term string) (float64, string) {
	if f.tokens[term] {
		return 1, term
	}
	for _, syn := range searchSynonyms[term] {
		if f.tokens[syn] {
			return synonymFactor, syn
		}
	}
	if len(term) >= 4 {
		for tok := range f.tokens {
			if strings.HasPrefix(tok, term) {
				return prefixFactor, tok
			}
		}
	}
	return 0, ""
}

// termMatch finds the field term matches best in d, and the field's
// total matches in d (breadth).
func termMatch(d searchDoc, term string) (best float64, breadth float64, field searchField, word string) {
	for _, f := range d.fields {
		factor, w := fieldMatch(f, term)
		if factor == 0 {
			continue
		}
		s := f.weight * factor
		breadth += s
		// On a tie a flag value wins: it is what the caller can pass.
		if s > best || (s == best && f.flag != "" && field.flag == "") {
			best, field, word = s, f, w
		}
	}
	return best, breadth, field, word
}

// scoreDoc scores d for the terms: per term, the best field's weight plus a
// little for the other fields it matches, times the squared share of terms
// matched. A grouping cue adds half of groupingTerm's match without
// counting toward the share.
func scoreDoc(d searchDoc, terms []string, grouping bool) (searchResult, bool) {
	res := searchResult{Command: d.cmd.Path, Short: d.cmd.Short}
	matched := 0
	var tryFlags []string
	tryValues := map[string]string{}
	note := func(term string, field searchField, word string) {
		reason := field.label
		if !field.structural {
			reason = fmt.Sprintf("%s mentions %q", field.label, word)
		}
		if word != term {
			reason += fmt.Sprintf(" (for %q)", term)
		}
		if !containsString(res.Matched, reason) {
			res.Matched = append(res.Matched, reason)
		}
		if field.structural {
			res.structural = true
		}
	}
	for _, term := range terms {
		best, breadth, field, word := termMatch(d, term)
		if best == 0 {
			continue
		}
		matched++
		res.Score += best + min(0.1*(breadth-best), maxBreadthBonus)
		note(term, field, word)
		if field.flag != "" {
			if _, dup := tryValues[field.flag]; !dup {
				tryFlags = append(tryFlags, field.flag)
				tryValues[field.flag] = field.value
			}
		}
	}
	if matched == 0 {
		return res, false
	}
	res.coverage = float64(matched) / float64(len(terms))
	res.Score *= res.coverage * res.coverage
	if grouping {
		if best, _, field, word := termMatch(d, groupingTerm); best > 0 {
			res.Score += 0.5 * best
			note("per/by", field, word)
		}
	}
	res.Score = float64(int(res.Score*100+0.5)) / 100
	if len(tryFlags) > 0 {
		try := d.cmd.Path
		for _, f := range tryFlags {
			try += " --" + f + " " + tryValues[f]
		}
		res.Try = try
	}
	return res, true
}

func runSearch(tree commandTree, words []string, limit int) searchAnswer {
	terms, grouping := parseSearchQuery(words)
	answer := searchAnswer{Query: strings.Join(words, " "), Terms: terms, Results: []searchResult{}}
	var results []searchResult
	for _, d := range buildSearchIndex(tree) {
		if r, ok := scoreDoc(d, terms, grouping); ok {
			results = append(results, r)
		}
	}
	sort.SliceStable(results, func(i, j int) bool {
		if results[i].Score != results[j].Score {
			return results[i].Score > results[j].Score
		}
		return results[i].Command < results[j].Command
	})
	switch {
	case len(results) == 0:
		answer.Note = fmt.Sprintf("No command matches %q. `p202 commands` lists every command.", answer.Query)
		return answer
	case !results[0].good():
		answer.Note = fmt.Sprintf("No command matches %q well; these are the closest. `p202 commands` lists every command.", answer.Query)
		limit = min(limit, closestShown)
	default:
		answer.GoodMatch = true
	}
	if len(results) > limit {
		results = results[:limit]
	}
	answer.Results = results
	return answer
}

var searchCmd = &cobra.Command{
	Use:   "search <what you want to do>",
	Short: "Find the command for a task: ranks commands, flags and flag values by your words (offline)",
	Long: "Searches every command's name, aliases, description, examples, flags and the values\n" +
		"its flags accept, and lists the best matches with why each matched and, when a flag\n" +
		"value matched, a command line to try. Plural forms and common synonyms match\n" +
		"(referrer/referer, offer/campaign, dead/broken, undo/revert, link/url). When nothing\n" +
		"matches well it says so and shows the closest few. No server is contacted.",
	Example: "  p202 search breakdown by browser\n  p202 search dead links --json\n  p202 search undo url change",
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
			return validationError("--limit must be at least 1, got %d", limit).WithHint("The default is 10.")
		}
		if csvOutput {
			return validationError("p202 search has no CSV form").WithHint("Use --json, or --quiet for command paths only.")
		}
		answer := runSearch(buildCommandTree(cmd.Root()), args, limit)
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
	if a.Note != "" {
		fmt.Fprintln(w, a.Note)
		if len(a.Results) > 0 {
			fmt.Fprintln(w)
		}
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
	searchCmd.Flags().Int("limit", 10, "Most results to show")
	rootCmd.AddCommand(searchCmd)
}
