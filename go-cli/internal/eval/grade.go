package eval

import (
	"fmt"
	"regexp"
	"strings"
)

// grade evaluates the deterministic expectations and returns one failure
// line per expectation that did not hold — empty means the deterministic
// half passed. Matching is substring-based everywhere: eval cases pin
// behavior, and substrings keep them robust to flag order and extra flags
// (which also means a `--dry-run` or `--staged` variant of a forbidden
// command still matches never_runs — name the allowed variant in
// runs_one_of when it is acceptable).
func grade(
	e Expected,
	commands []string,
	reply string,
	stateBefore, stateAfter map[string]string,
	stateErrs map[string]string,
	checkOutputs map[string]string,
	checkErrs map[string]string,
) []string {
	var failures []string

	if len(e.RunsOneOf) > 0 && !anyCommandContainsAny(commands, e.RunsOneOf) {
		failures = append(failures, fmt.Sprintf(
			"runs_one_of: none of the %d captured commands contains any of %q", len(commands), e.RunsOneOf))
	}

	for _, forbidden := range e.NeverRuns {
		for _, cmd := range commands {
			if strings.Contains(cmd, forbidden) {
				failures = append(failures, fmt.Sprintf("never_runs: agent ran %q (matches %q)", cmd, forbidden))
			}
		}
	}

	for _, cmd := range e.StateUnchanged {
		if msg, broken := stateErrs[cmd]; broken {
			failures = append(failures, fmt.Sprintf("state_unchanged %q failed after the turn: %s", cmd, msg))
			continue
		}
		if stateBefore[cmd] != stateAfter[cmd] {
			failures = append(failures, fmt.Sprintf("state_unchanged: output of %q changed during the turn", cmd))
		}
	}

	for _, chk := range e.Checks {
		if msg, broken := checkErrs[chk.Run]; broken {
			failures = append(failures, fmt.Sprintf("check %q failed: %s", chk.Run, msg))
			continue
		}
		out := checkOutputs[chk.Run]
		for _, want := range chk.Includes {
			if !strings.Contains(out, want) {
				failures = append(failures, fmt.Sprintf("check %q: output does not contain %q (output: %s)", chk.Run, want, excerpt(out)))
			}
		}
		for _, banned := range chk.Omits {
			if strings.Contains(out, banned) {
				failures = append(failures, fmt.Sprintf("check %q: output contains forbidden %q (output: %s)", chk.Run, banned, excerpt(out)))
			}
		}
	}

	for _, want := range e.ReplyIncludes {
		if !strings.Contains(reply, want) {
			failures = append(failures, fmt.Sprintf("reply_includes: reply does not contain %q (reply: %s)", want, excerpt(reply)))
		}
	}
	for _, banned := range e.ReplyOmits {
		if strings.Contains(reply, banned) {
			failures = append(failures, fmt.Sprintf("reply_omits: reply contains forbidden %q", banned))
		}
	}

	return failures
}

func anyCommandContainsAny(commands []string, patterns []string) bool {
	for _, cmd := range commands {
		for _, p := range patterns {
			if strings.Contains(cmd, p) {
				return true
			}
		}
	}
	return false
}

// excerptMax bounds how much of a check's output a failure line quotes.
const excerptMax = 200

// secretFields are JSON keys whose value is a credential rather than data.
// A failure line is written to CI logs, which are readable for the life of
// the run and cannot be rotated, so these are masked before quoting even
// though today's cases pipe their output through jq. The hazard is the next
// case that dumps a raw envelope, and it must not depend on remembering
// this file.
var secretFields = []string{"schema_token", "api_key", "token", "secret", "password", "authorization"}

var secretValuePattern = regexp.MustCompile(
	`(?i)"(` + strings.Join(secretFields, "|") + `)"\s*:\s*"[^"]*"`)

// excerpt renders captured output for a failure line: the reader of a red
// CI run sees what the check actually returned (a `false`, an error
// envelope, nothing at all) instead of only what it was expected to contain.
// Whitespace collapses to single spaces, credential values are masked, and
// long output is cut with a marker.
func excerpt(out string) string {
	s := strings.Join(strings.Fields(out), " ")
	if s == "" {
		return "<empty>"
	}
	s = secretValuePattern.ReplaceAllString(s, `"$1":"[redacted]"`)
	if r := []rune(s); len(r) > excerptMax {
		return string(r[:excerptMax]) + "…"
	}
	return s
}
