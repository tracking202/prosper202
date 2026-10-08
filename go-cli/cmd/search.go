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
// name, aliases, descriptions, examples, flags, allowed flag values, and the
// tasks they run (searchTasks: the web UI pages that do the same, and the
// words people use for it): an offline index over the same records
// `p202 commands --json` prints.

// Field weights: what a word matching there says about the command.
const (
	weightName    = 10.0 // the command's own name ("breakdown")
	weightPage    = 10.0 // a web UI page the command's task does ("Spy")
	weightAlias   = 8.0
	weightPhrase  = 8.0 // words people use for the command's task ("live clicks")
	weightShort   = 6.0
	weightValue   = 6.0 // a value a flag accepts ("browser")
	weightFlag    = 5.0 // a flag's name
	weightParent  = 3.0 // a parent command's name ("report")
	weightSection = 3.0 // the UI section a task's page is under ("Analyze")
	weightUsage   = 2.0 // a flag's help text
	// A page or phrase counts as a whole: a query that names only part of
	// it ("report" of "attribution report") is no more evidence than prose.
	weightPartialTask = 2.0
	weightLong        = 1.5
	weightExample     = 1.0

	synonymFactor   = 0.8 // a match through searchSynonyms
	maxBreadthBonus = 1.5 // most a word's other matches in one command add
	prefixFactor    = 0.5 // the word is a prefix of the indexed one
	// coverBonus is added per word of a page or phrase the query names in
	// full, so the longest exact description of the task wins.
	coverBonus = 2.5

	// A result is a good match (searchResult.good) when it scores at least
	// this; otherwise search says so and names the closest.
	goodMatchScore    = 6.0
	goodMatchCoverage = 0.5
	closestShown      = 3
)

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

// searchField is one indexed piece of a command.
type searchField struct {
	weight float64
	tokens map[string]bool
	label  string // how a match here is reported
	flag   string // the flag a value field belongs to
	value  string // the value (or value alias) itself
	// structural: a name, alias, flag or flag value rather than prose.
	structural bool
	// task is the task a page, section or phrase field belongs to. Such a
	// field counts in full only when the query names all of it (wholeTask).
	task      *taskInfo
	wholeTask bool // a page or phrase, rather than a page's section
}

type searchDoc struct {
	cmd    commandInfo
	fields []searchField
	// notInCLI is set on the entry for a web UI page (or task) no command
	// does; it ranks like a command, and a query it answers best is told
	// where the task is done instead.
	notInCLI *taskInfo
}

func tokenSet(text string) map[string]bool {
	set := map[string]bool{}
	for _, t := range searchTokens(text) {
		set[t] = true
	}
	return set
}

func meaningfulSet(text string) map[string]bool {
	set := map[string]bool{}
	for _, t := range meaningfulTokens(text) {
		set[t] = true
	}
	return set
}

// splitPage separates a page under a section ("Analyze › Text Ads") into
// its section and label; a top-level page has no section.
func splitPage(page string) (section, label string) {
	if i := strings.Index(page, "›"); i >= 0 {
		return strings.TrimSpace(page[:i]), strings.TrimSpace(page[i+len("›"):])
	}
	return "", page
}

// addTaskFields indexes a task's pages and phrases on d.
func (d *searchDoc) addTaskFields(task *taskInfo) {
	for _, page := range task.UIPages {
		section, label := splitPage(page)
		d.fields = append(d.fields, searchField{weight: weightPage, tokens: meaningfulSet(label), structural: true,
			label: fmt.Sprintf("UI page %q", page), task: task, wholeTask: true})
		if section != "" {
			d.fields = append(d.fields, searchField{weight: weightSection, tokens: meaningfulSet(section), structural: true,
				label: fmt.Sprintf("UI section %q", section), task: task})
		}
	}
	for _, phrase := range task.Phrases {
		d.fields = append(d.fields, searchField{weight: weightPhrase, tokens: meaningfulSet(phrase), structural: true,
			label: fmt.Sprintf("task %q", phrase), task: task, wholeTask: true})
	}
}

func buildSearchIndex(tree commandTree) []searchDoc {
	docs := make([]searchDoc, 0, len(tree.Commands)+len(tree.NotInCLI))
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
		for i := range c.Tasks {
			d.addTaskFields(&c.Tasks[i])
		}
		docs = append(docs, d)
	}
	for i := range tree.NotInCLI {
		t := &tree.NotInCLI[i]
		name := strings.Join(append(append([]string{}, t.UIPages...), t.Phrases...), " / ")
		d := searchDoc{cmd: commandInfo{Path: name, Short: t.Instead}, notInCLI: t}
		d.addTaskFields(t)
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
	Command string   `json:"command"`
	Short   string   `json:"short"`
	Score   float64  `json:"score"`
	Matched []string `json:"matched"`
	Try     string   `json:"try,omitempty"`

	coverage   float64
	structural bool // a word matched a name, alias, flag, flag value, or a page or phrase in full
	notInCLI   *taskInfo
}

// good reports whether r is a match worth acting on: a high enough score
// over at least half the words, found in the command's structure or in
// more than one word of its prose.
func (r searchResult) good() bool {
	return r.Score >= goodMatchScore && r.coverage >= goodMatchCoverage && (r.structural || len(r.Matched) > 1)
}

// searchAnswer is what a successful search prints. A search that finds no
// good match is an error (searchError) and prints nothing on stdout.
type searchAnswer struct {
	Query     string         `json:"query"`
	Terms     []string       `json:"terms"`
	GoodMatch bool           `json:"good_match"`
	Results   []searchResult `json:"results"`

	// closest are the best results when none is good, for the error's hint;
	// notInCLI is the page or task no command does, when the query named it.
	closest  []searchResult
	notInCLI *taskInfo
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

// coveredBy reports whether every word of a page or phrase field is one of
// the query's terms, or a synonym of one, with at least one word as written:
// "realtime traffic" names "realtime clicks", but "click" alone does not name
// the Visitors page through a synonym, nor "referrer" the Referers page.
func coveredBy(f searchField, terms map[string]bool) bool {
	if len(f.tokens) == 0 {
		return false
	}
	exact := false
	for tok := range f.tokens {
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

// fieldWeight is a field's weight for this query: a page or phrase the query
// names only in part counts as prose; a page's section counts only beside
// its page.
func fieldWeight(f searchField, covered map[*taskInfo]bool, whole bool) (float64, bool) {
	if f.task == nil {
		return f.weight, f.structural
	}
	if f.wholeTask {
		if whole {
			return f.weight, true
		}
		return weightPartialTask, false
	}
	if covered[f.task] {
		return f.weight, true
	}
	return weightPartialTask, false
}

// termMatch finds the field term matches best in d, and the field's
// total matches in d (breadth).
func termMatch(d searchDoc, term string, whole map[int]bool, covered map[*taskInfo]bool) (best, breadth float64, field searchField, structural bool, word string) {
	for i, f := range d.fields {
		factor, w := fieldMatch(f, term)
		if factor == 0 {
			continue
		}
		weight, st := fieldWeight(f, covered, whole[i])
		s := weight * factor
		breadth += s
		// On a tie a flag value wins: it is what the caller can pass.
		if s > best || (s == best && f.flag != "" && field.flag == "") {
			best, field, structural, word = s, f, st, w
		}
	}
	return best, breadth, field, structural, word
}

// scoreDoc scores d for the terms: per term, the best field's weight plus a
// little for the other fields it matches, plus coverBonus per word of the
// longest page or phrase the query names in full, times the squared share
// of terms matched. A grouping cue adds half of groupingTerm's match
// without counting toward the share.
func scoreDoc(d searchDoc, terms []string, grouping bool) (searchResult, bool) {
	res := searchResult{Command: d.cmd.Path, Short: d.cmd.Short, notInCLI: d.notInCLI}
	termSet := map[string]bool{}
	for _, t := range terms {
		termSet[t] = true
	}
	// The pages and phrases the query names in full, and the task among
	// them it names at the greatest length: the one its command line is.
	whole := map[int]bool{}
	covered := map[*taskInfo]bool{}
	var chosen *taskInfo
	bonus := 0.0
	for i, f := range d.fields {
		if !f.wholeTask || !coveredBy(f, termSet) {
			continue
		}
		whole[i] = true
		covered[f.task] = true
		if b := coverBonus * float64(len(f.tokens)); b > bonus {
			bonus, chosen = b, f.task
		}
	}

	matched := 0
	var tryFlags []string
	tryValues := map[string]string{}
	note := func(term string, field searchField, structural bool, word string) {
		reason := field.label
		if !structural {
			reason = fmt.Sprintf("%s mentions %q", field.label, word)
		}
		if word != term {
			reason += fmt.Sprintf(" (for %q)", term)
		}
		if !containsString(res.Matched, reason) {
			res.Matched = append(res.Matched, reason)
		}
		if structural {
			res.structural = true
		}
	}
	for _, term := range terms {
		best, breadth, field, structural, word := termMatch(d, term, whole, covered)
		if best == 0 {
			continue
		}
		matched++
		res.Score += best + min(0.1*(breadth-best), maxBreadthBonus)
		note(term, field, structural, word)
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
	res.Score += bonus
	res.coverage = float64(matched) / float64(len(terms))
	res.Score *= res.coverage * res.coverage
	if grouping {
		if best, _, field, structural, word := termMatch(d, groupingTerm, whole, covered); best > 0 {
			res.Score += 0.5 * best
			note("per/by", field, structural, word)
		}
	}
	res.Score = float64(int(res.Score*100+0.5)) / 100

	// The command line to try: the named task's own, with any other flag
	// value the query named; else the command with those values.
	try := d.cmd.Path
	set := map[string]bool{}
	if chosen != nil && chosen.Run != "" {
		try = chosen.Run
		set = taskFlags(chosen.Run)
	}
	for _, f := range tryFlags {
		if !set[f] {
			try += " --" + f + " " + tryValues[f]
		}
	}
	if try != d.cmd.Path && d.notInCLI == nil {
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
	if len(results) > 0 && results[0].notInCLI != nil && results[0].good() {
		answer.notInCLI = results[0].notInCLI
		return answer
	}
	// A page with no command is an answer only when it is the best one;
	// below a command it is noise.
	var commands []searchResult
	for _, r := range results {
		if r.notInCLI == nil {
			commands = append(commands, r)
		}
	}
	if len(commands) == 0 || !commands[0].good() {
		answer.closest = commands[:min(len(commands), closestShown)]
		return answer
	}
	answer.GoodMatch = true
	answer.Results = commands[:min(len(commands), limit)]
	return answer
}

// searchError is the failure for a search with no good match: nothing on
// stdout, so a script or an agent that reads the first line or the exit
// status cannot take a guess for the answer; the hint names the closest
// commands and where to look instead.
func searchError(a searchAnswer) error {
	if t := a.notInCLI; t != nil {
		if len(t.UIPages) > 0 {
			return validationError("No p202 command does what the web UI's %q page does: %s.", t.UIPages[0], t.Instead).WithHint("%s", t.Hint)
		}
		return validationError("No p202 command for %q: %s.", a.Query, t.Instead).WithHint("%s", t.Hint)
	}
	if len(a.closest) == 0 {
		return validationError("No command matches %q.", a.Query).
			WithHint("Describe the task in other words, or run `p202 commands` for every command (`--json` lists every flag and allowed value).")
	}
	var names []string
	for _, r := range a.closest {
		names = append(names, fmt.Sprintf("`%s` (%s)", r.Command, r.Short))
	}
	return validationError("No command matches %q well.", a.Query).
		WithHint("The closest, none of them a confident match: %s. Describe the task in other words, or run `p202 commands` for every command (`--json` lists every flag and allowed value).", strings.Join(names, "; "))
}

var searchCmd = &cobra.Command{
	Use:   "search <what you want to do>",
	Short: "Find the command for a task: ranks commands, flags, flag values and UI pages by your words (offline)",
	Long: "Searches every command's name, aliases, description, examples, flags, the values\n" +
		"its flags accept, and the tasks it runs: the web UI page that does the same (\"Spy\",\n" +
		"\"Update CPC\") and the words people use for it (\"realtime traffic\"). Lists the best\n" +
		"matches with why each matched and a command line to try. Plural forms, common\n" +
		"synonyms (referrer/referer, offer/campaign, dead/broken, undo/revert, link/url) and\n" +
		"split words (real time, real-time) match.\n\n" +
		"When nothing matches well it fails (exit 1, nothing on stdout) and the hint names\n" +
		"the closest commands, so a script never runs a guess; a UI page with no command\n" +
		"is answered with where it is done instead. No server is contacted.",
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
			return validationError("--limit must be at least 1, got %d", limit).WithHint("The default is 10.")
		}
		if csvOutput {
			return validationError("p202 search has no CSV form").WithHint("Use --json, or --quiet for command paths only.")
		}
		answer := runSearch(buildCommandTree(cmd.Root()), args, limit)
		if !answer.GoodMatch {
			return searchError(answer)
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
