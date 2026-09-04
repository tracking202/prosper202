// Package eval runs behavioral snapshot evals for an AI agent that operates
// a Prosper202 instance through the p202 CLI.
//
// The runner owns everything deterministic: loading case files, per-case
// setup/cleanup, capturing which p202 commands the agent ran (via a PATH
// shim, so any agent that shells out to p202 is captured with no adapter
// work), re-reading instance state, and grading. The agent under test is a
// pluggable shell command; the optional rubric judge is another. Neither is
// assumed to be any particular model or product — this is a self-hosted
// platform and the suite must run against whatever agent a deployment
// wires in.
package eval

// Case is one behavioral snapshot eval, matching the shape documented in
// .claude/skills/p202-agent-evals/SKILL.md.
type Case struct {
	ID       string `json:"id"`
	Priority string `json:"priority,omitempty"` // critical | high | medium | low
	Skip     string `json:"skip,omitempty"`     // non-empty: skipped, with this reason

	// State is prose for the reader; Setup is the machine half — p202
	// commands run before the ask (each through `sh -c`). Cleanup runs
	// after grading regardless of outcome.
	State   string   `json:"state,omitempty"`
	Setup   []string `json:"setup,omitempty"`
	Cleanup []string `json:"cleanup,omitempty"`

	Ask      string   `json:"ask"`
	Expected Expected `json:"expected"`
	Notes    string   `json:"notes,omitempty"`
}

// Expected holds only the assertions a case is about; zero-valued fields
// assert nothing.
type Expected struct {
	// RunsOneOf passes when at least one command the agent ran contains
	// one of these substrings. NeverRuns fails on any command containing
	// one of these substrings (a `--dry-run` or `--staged` variant still
	// matches — name the allowed variant in RunsOneOf when it is fine).
	RunsOneOf []string `json:"runs_one_of,omitempty"`
	NeverRuns []string `json:"never_runs,omitempty"`

	// StateUnchanged commands run before and after the agent's turn; their
	// stdout must be byte-identical. Include --json so ordering is stable.
	StateUnchanged []string `json:"state_unchanged,omitempty"`

	// Checks run after the agent's turn and assert on their stdout.
	Checks []Check `json:"checks,omitempty"`

	// Substring assertions on the agent's reply (its stdout).
	ReplyIncludes []string `json:"reply_includes,omitempty"`
	ReplyOmits    []string `json:"reply_omits,omitempty"`

	// Rubric is the judged half: one PASS and one FAIL condition. Without
	// a judge command the case grades its deterministic half and reports
	// needs_judge instead of pass.
	Rubric string `json:"rubric,omitempty"`
}

// Check is one post-turn state assertion: run the command, assert on stdout.
type Check struct {
	Run      string   `json:"run"`
	Includes []string `json:"includes,omitempty"`
	Omits    []string `json:"omits,omitempty"`
}

// Case statuses.
const (
	StatusPass       = "pass"
	StatusFail       = "fail"
	StatusSkip       = "skip"
	StatusNeedsJudge = "needs_judge" // deterministic half passed; rubric awaits a judge
	StatusError      = "error"       // the case could not run (setup or agent invocation failed)
)

// Result is the graded outcome of one case.
type Result struct {
	ID         string   `json:"id"`
	Priority   string   `json:"priority,omitempty"`
	Status     string   `json:"status"`
	Failures   []string `json:"failures,omitempty"` // one line per failed expectation
	Judge      string   `json:"judge,omitempty"`    // the judge's verdict line, when one ran
	Commands   int      `json:"commands"`           // p202 invocations captured
	DurationMs int64    `json:"duration_ms"`
}

// Summary aggregates a run.
type Summary struct {
	Total            int            `json:"total"`
	Pass             int            `json:"pass"`
	Fail             int            `json:"fail"`
	Skip             int            `json:"skip"`
	NeedsJudge       int            `json:"needs_judge"`
	Error            int            `json:"error"`
	FailedByPriority map[string]int `json:"failed_by_priority,omitempty"`
}

// Summarize computes the aggregate for a set of results.
func Summarize(results []Result) Summary {
	s := Summary{Total: len(results), FailedByPriority: map[string]int{}}
	for _, r := range results {
		switch r.Status {
		case StatusPass:
			s.Pass++
		case StatusFail:
			s.Fail++
		case StatusSkip:
			s.Skip++
		case StatusNeedsJudge:
			s.NeedsJudge++
		case StatusError:
			s.Error++
		}
		if r.Status == StatusFail || r.Status == StatusError {
			p := r.Priority
			if p == "" {
				p = "unset"
			}
			s.FailedByPriority[p]++
		}
	}
	if len(s.FailedByPriority) == 0 {
		s.FailedByPriority = nil
	}
	return s
}
