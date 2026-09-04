package eval

import (
	"fmt"
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
				failures = append(failures, fmt.Sprintf("check %q: output does not contain %q", chk.Run, want))
			}
		}
		for _, banned := range chk.Omits {
			if strings.Contains(out, banned) {
				failures = append(failures, fmt.Sprintf("check %q: output contains forbidden %q", chk.Run, banned))
			}
		}
	}

	for _, want := range e.ReplyIncludes {
		if !strings.Contains(reply, want) {
			failures = append(failures, fmt.Sprintf("reply_includes: reply does not contain %q", want))
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
