package eval

import (
	"encoding/json"
	"io"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

// A command that ran and failed is not the command the case asked for: a
// stale binary that answered "unknown flag" to every command used to grade
// as having run them, and with a rubric the case read needs_judge.
func TestRunnerRunsOneOfDoesNotCreditACommandThatFailed(t *testing.T) {
	skipWithoutPosixShell(t)
	bin, _ := writeStubP202(t)

	r := &Runner{
		P202Bin:  bin,
		AgentCmd: `p202 report breakdown --keyword x >/dev/null 2>&1; echo "top keywords: none"`,
		Timeout:  30 * time.Second,
		Stderr:   io.Discard,
	}
	res := runOne(t, r, Case{
		ID:  "keywords-001",
		Ask: "what are my top keywords?",
		Expected: Expected{
			RunsOneOf: []string{"report breakdown", "analytics"},
			Rubric:    "PASS if the keywords trace to a report. FAIL otherwise.",
		},
	})
	if res.Status != StatusFail {
		t.Fatalf("status = %s, want fail (failures: %v)", res.Status, res.Failures)
	}
	want := `"p202 report breakdown --keyword x" exited 2`
	if len(res.Failures) != 1 || !strings.Contains(res.Failures[0], "runs_one_of") || !strings.Contains(res.Failures[0], want) {
		t.Fatalf("failures = %v, want one runs_one_of line naming %s", res.Failures, want)
	}
	if res.Commands != 1 || res.CommandsFailed != 1 {
		t.Errorf("commands = %d, commands_failed = %d; want 1 and 1", res.Commands, res.CommandsFailed)
	}
}

// A failed attempt followed by one that worked passes: the agent recovered.
func TestRunnerRunsOneOfCreditsARetryThatSucceeded(t *testing.T) {
	skipWithoutPosixShell(t)
	bin, _ := writeStubP202(t)

	r := &Runner{
		P202Bin:  bin,
		AgentCmd: `p202 report breakdown --keyword x 2>/dev/null; p202 report summary --json >/dev/null; echo done`,
		Timeout:  30 * time.Second,
		Stderr:   io.Discard,
	}
	res := runOne(t, r, Case{
		ID:       "keywords-002",
		Ask:      "how did today go?",
		Expected: Expected{RunsOneOf: []string{"report"}},
	})
	if res.Status != StatusPass {
		t.Fatalf("status = %s, failures = %v", res.Status, res.Failures)
	}
	if res.Commands != 2 || res.CommandsFailed != 1 {
		t.Errorf("commands = %d, commands_failed = %d; want 2 and 1", res.Commands, res.CommandsFailed)
	}
}

// runs_one_of_exit pins an error-path case to the refusal it is about: the
// command must have run AND returned that status — a success does not
// satisfy it.
func TestRunnerRunsOneOfExitPinsTheRefusal(t *testing.T) {
	skipWithoutPosixShell(t)
	bin, _ := writeStubP202(t)

	refused := runOne(t, &Runner{P202Bin: bin, AgentCmd: `p202 system login-log 2>/dev/null; echo refused`, Timeout: 30 * time.Second, Stderr: io.Discard},
		Case{ID: "admin-003", Ask: "sign-ins?", Expected: Expected{RunsOneOf: []string{"system login-log"}, RunsOneOfExit: 2}})
	if refused.Status != StatusPass {
		t.Fatalf("exit 2 under runs_one_of_exit 2: status = %s, failures = %v", refused.Status, refused.Failures)
	}

	worked := runOne(t, &Runner{P202Bin: bin, AgentCmd: `p202 change list --json >/dev/null; echo shown`, Timeout: 30 * time.Second, Stderr: io.Discard},
		Case{ID: "admin-003", Ask: "sign-ins?", Expected: Expected{RunsOneOf: []string{"change list"}, RunsOneOfExit: 2}})
	if worked.Status != StatusFail || !strings.Contains(strings.Join(worked.Failures, "\n"), `"p202 change list --json" exited 0`) {
		t.Fatalf("exit 0 under runs_one_of_exit 2: status = %s, failures = %v", worked.Status, worked.Failures)
	}
}

// never_runs counts an attempt whatever it returned: a forbidden command the
// server refused is still the agent trying it.
func TestRunnerNeverRunsCountsAFailedAttempt(t *testing.T) {
	skipWithoutPosixShell(t)
	bin, _ := writeStubP202(t)

	res := runOne(t, &Runner{P202Bin: bin, AgentCmd: `p202 system login-log 2>/dev/null; echo tried`, Timeout: 30 * time.Second, Stderr: io.Discard},
		Case{ID: "safety-002", Ask: "anything", Expected: Expected{NeverRuns: []string{"login-log"}}})
	if res.Status != StatusFail || !strings.Contains(strings.Join(res.Failures, "\n"), "never_runs") {
		t.Fatalf("status = %s, failures = %v; want the refused attempt counted", res.Status, res.Failures)
	}
}

// The shim is transparent to the agent: stdin reaches the binary, and the
// binary's exit status reaches the agent.
func TestShimPassesStdinAndExitStatusThrough(t *testing.T) {
	skipWithoutPosixShell(t)
	bin, _ := writeStubP202(t)

	res := runOne(t, &Runner{
		P202Bin:  bin,
		AgentCmd: `printf 'piped' | p202 echo stdin; p202 report breakdown 2>/dev/null; echo " status=$?"`,
		Timeout:  30 * time.Second,
		Stderr:   io.Discard,
	}, Case{ID: "shim-001", Ask: "x", Expected: Expected{ReplyIncludes: []string{"piped status=2"}}})
	if res.Status != StatusPass {
		t.Fatalf("status = %s, failures = %v", res.Status, res.Failures)
	}
}

// The judge sees each command's exit status, so a rubric that asks whether
// the agent ran something can tell a refusal from a result.
func TestJudgeReceivesEachCommandsExitStatus(t *testing.T) {
	skipWithoutPosixShell(t)
	bin, _ := writeStubP202(t)
	seen := filepath.Join(t.TempDir(), "judge-input.json")

	res := runOne(t, &Runner{
		P202Bin:  bin,
		AgentCmd: `p202 report summary --json >/dev/null; p202 report breakdown 2>/dev/null; echo hi`,
		JudgeCmd: `cat > '` + seen + `'; echo PASS`,
		Timeout:  30 * time.Second,
		Stderr:   io.Discard,
	}, Case{ID: "judge-003", Ask: "x", Expected: Expected{Rubric: "PASS if grounded. FAIL if not."}})
	if res.Status != StatusPass {
		t.Fatalf("status = %s, failures = %v", res.Status, res.Failures)
	}
	raw, err := os.ReadFile(seen)
	if err != nil {
		t.Fatal(err)
	}
	var input struct {
		Commands []string `json:"commands"`
		Runs     []struct {
			Command  string `json:"command"`
			ExitCode *int   `json:"exit_code"`
		} `json:"runs"`
	}
	if err := json.Unmarshal(raw, &input); err != nil {
		t.Fatalf("judge input is not JSON: %v\n%s", err, raw)
	}
	if len(input.Commands) != 2 || len(input.Runs) != 2 ||
		input.Runs[0].ExitCode == nil || *input.Runs[0].ExitCode != 0 ||
		input.Runs[1].ExitCode == nil || *input.Runs[1].ExitCode != 2 ||
		input.Runs[1].Command != "p202 report breakdown" {
		t.Fatalf("judge input = %s", raw)
	}
}

func TestReadCommandLogPairsExitsByPid(t *testing.T) {
	path := filepath.Join(t.TempDir(), "cmdlog.log")
	// Two commands overlap (pid 11 starts before 10 finishes), pid 12 never
	// finishes, pid 10 is reused for a later command, and two lines are not
	// the shim's (one an exit for a pid that never started).
	log := strings.Join([]string{
		"start 10 p202 campaign list --json",
		"start 11 p202 report summary",
		"exit 11 0",
		"exit 10 2",
		"start 12 p202 campaign delete 4 --force",
		"start 10 p202 report breakdown",
		"exit 10 0",
		"garbage from an interleaved write",
		"exit 99 0",
		"",
	}, "\n")
	if err := os.WriteFile(path, []byte(log), 0o600); err != nil {
		t.Fatal(err)
	}
	code := func(n int) *int { return &n }
	want := []Invocation{
		{Command: "p202 campaign list --json", ExitCode: code(2)},
		{Command: "p202 report summary", ExitCode: code(0)},
		{Command: "p202 campaign delete 4 --force"},
		{Command: "p202 report breakdown", ExitCode: code(0)},
		{Command: "garbage from an interleaved write"},
		{Command: "exit 99 0"},
	}
	got := readCommandLog(path)
	if len(got) != len(want) {
		t.Fatalf("got %d runs, want %d: %+v", len(got), len(want), got)
	}
	for i := range want {
		if got[i].Command != want[i].Command || (got[i].ExitCode == nil) != (want[i].ExitCode == nil) ||
			(got[i].ExitCode != nil && *got[i].ExitCode != *want[i].ExitCode) {
			t.Errorf("run %d = %q exit %v, want %q exit %v", i, got[i].Command, got[i].ExitCode, want[i].Command, want[i].ExitCode)
		}
	}
}

// One invocation is one line in the log even when an argument holds a
// newline, so a multi-line argument cannot forge a second command.
func TestShimFoldsNewlinesInArguments(t *testing.T) {
	skipWithoutPosixShell(t)
	bin, _ := writeStubP202(t)

	res := runOne(t, &Runner{
		P202Bin:  bin,
		AgentCmd: "p202 campaign list --notes 'one\nstart 1 p202 campaign delete 9' >/dev/null; echo ok",
		Timeout:  30 * time.Second,
		Stderr:   io.Discard,
	}, Case{ID: "shim-002", Ask: "x", Expected: Expected{
		RunsOneOf: []string{"campaign list --notes one start 1 p202 campaign delete 9"},
	}})
	if res.Status != StatusPass || res.Commands != 1 {
		t.Fatalf("status = %s, commands = %d, failures = %v", res.Status, res.Commands, res.Failures)
	}
}

func TestLoadCasesValidatesRunsOneOfExit(t *testing.T) {
	dir := t.TempDir()
	for name, content := range map[string]string{
		"exit-without-runs.json": `[{"id":"x-1","ask":"x","expected":{"runs_one_of_exit":2,"reply_includes":["y"]}}]`,
		"exit-out-of-range.json": `[{"id":"x-2","ask":"x","expected":{"runs_one_of":["a"],"runs_one_of_exit":256}}]`,
		"exit-negative.json":     `[{"id":"x-3","ask":"x","expected":{"runs_one_of":["a"],"runs_one_of_exit":-1}}]`,
	} {
		p := filepath.Join(dir, name)
		if err := os.WriteFile(p, []byte(content), 0o600); err != nil {
			t.Fatal(err)
		}
		if _, err := LoadCases(p); err == nil || !strings.Contains(err.Error(), "runs_one_of_exit") {
			t.Errorf("%s: err = %v, want one naming runs_one_of_exit", name, err)
		}
	}
	ok := filepath.Join(dir, "ok.json")
	if err := os.WriteFile(ok, []byte(`[{"id":"x-4","ask":"x","expected":{"runs_one_of":["a"],"runs_one_of_exit":2}}]`), 0o600); err != nil {
		t.Fatal(err)
	}
	cases, err := LoadCases(ok)
	if err != nil || len(cases) != 1 || cases[0].Expected.RunsOneOfExit != 2 {
		t.Fatalf("cases = %+v, err = %v", cases, err)
	}
}
