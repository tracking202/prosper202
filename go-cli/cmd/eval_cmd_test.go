package cmd

import (
	"encoding/json"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
)

func writeEvalFixture(t *testing.T) (stubBin string, casesFile string) {
	t.Helper()
	if runtime.GOOS == "windows" {
		t.Skip("eval runner needs a POSIX shell")
	}
	dir := t.TempDir()

	stubBin = filepath.Join(dir, "p202-real")
	stub := `#!/bin/sh
case "$1 $2" in
  "report summary") echo '{"data":{"total_net":12.5}}' ;;
  *) echo '{"data":[]}' ;;
esac
`
	if err := os.WriteFile(stubBin, []byte(stub), 0o700); err != nil {
		t.Fatal(err)
	}

	casesFile = filepath.Join(dir, "cases.json")
	cases := `{"cases":[
  {"id":"pass-1","priority":"high","ask":"profit today?","expected":{
    "runs_one_of":["report summary"],
    "reply_includes":["12.5"]}},
  {"id":"fail-1","priority":"critical","ask":"profit today?","expected":{
    "reply_includes":["this text never appears"]}}
]}`
	if err := os.WriteFile(casesFile, []byte(cases), 0o600); err != nil {
		t.Fatal(err)
	}
	return stubBin, casesFile
}

const evalTestAgent = `p202 report summary --json | tr -d '\n'; echo " (from a report)"`

func TestEvalRunReportsResultsAndPartialFailureExit(t *testing.T) {
	stubBin, casesFile := writeEvalFixture(t)
	tmp := t.TempDir()
	setTestHome(t, tmp)

	stdout, _, err := executeCommand("eval", "run",
		"--cases", casesFile,
		"--agent-cmd", evalTestAgent,
		"--p202-bin", stubBin,
		"--json")
	if err == nil {
		t.Fatal("expected a partial_failure error for the failing case")
	}
	if code := exitCodeForError(err); code != ExitPartialFailure {
		t.Errorf("exit code = %d, want %d", code, ExitPartialFailure)
	}
	if hint := hintFor(err); !strings.Contains(hint, "--only") {
		t.Errorf("hint should mention iterating with --only, got %q", hint)
	}

	var out struct {
		Data []struct {
			ID     string `json:"id"`
			Status string `json:"status"`
		} `json:"data"`
		Summary struct {
			Total int `json:"total"`
			Pass  int `json:"pass"`
			Fail  int `json:"fail"`
		} `json:"summary"`
	}
	if err := json.Unmarshal([]byte(stdout), &out); err != nil {
		t.Fatalf("stdout is not JSON: %v\n%s", err, stdout)
	}
	if out.Summary.Total != 2 || out.Summary.Pass != 1 || out.Summary.Fail != 1 {
		t.Errorf("summary = %+v", out.Summary)
	}
}

func TestEvalRunAllPassingExitsZero(t *testing.T) {
	stubBin, casesFile := writeEvalFixture(t)
	tmp := t.TempDir()
	setTestHome(t, tmp)

	stdout, _, err := executeCommand("eval", "run",
		"--cases", casesFile,
		"--only", "pass-1",
		"--agent-cmd", evalTestAgent,
		"--p202-bin", stubBin,
		"--json")
	if err != nil {
		t.Fatalf("eval run error: %v", err)
	}
	if !strings.Contains(stdout, `"pass": 1`) {
		t.Errorf("stdout should report one pass, got:\n%s", stdout)
	}
}

// The incident: a stale p202 answered every command with an unknown-flag
// error, and a case whose runs_one_of named that command — with a rubric,
// so no other check ran — read needs_judge, which does not fail the job. A
// command that exited non-zero no longer satisfies runs_one_of: the case
// fails, the run exits 5, and the failure names the command and its status.
func TestEvalRunAMatchingCommandThatFailedFailsTheCase(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("eval runner needs a POSIX shell")
	}
	dir := t.TempDir()
	setTestHome(t, t.TempDir())

	stale := filepath.Join(dir, "p202-stale")
	if err := os.WriteFile(stale, []byte("#!/bin/sh\necho \"Error [validation]: unknown flag: --keyword\" >&2\nexit 2\n"), 0o700); err != nil {
		t.Fatal(err)
	}
	casesFile := filepath.Join(dir, "cases.json")
	if err := os.WriteFile(casesFile, []byte(`[{"id":"keywords-1","priority":"high","ask":"top keywords?","expected":{
  "runs_one_of":["report breakdown"],
  "rubric":"PASS if the keywords come from the report. FAIL otherwise."}}]`), 0o600); err != nil {
		t.Fatal(err)
	}

	stdout, _, err := executeCommand("eval", "run",
		"--cases", casesFile,
		"--agent-cmd", `p202 report breakdown --keyword shoes 2>/dev/null; echo "no keywords"`,
		"--p202-bin", stale,
		"--json")
	if err == nil {
		t.Fatalf("expected a partial_failure error; stdout:\n%s", stdout)
	}
	if code := exitCodeForError(err); code != ExitPartialFailure {
		t.Errorf("exit code = %d, want %d", code, ExitPartialFailure)
	}
	var out struct {
		Data []struct {
			Status         string   `json:"status"`
			Failures       []string `json:"failures"`
			Commands       int      `json:"commands"`
			CommandsFailed int      `json:"commands_failed"`
		} `json:"data"`
		Summary struct {
			Fail       int `json:"fail"`
			NeedsJudge int `json:"needs_judge"`
		} `json:"summary"`
	}
	if err := json.Unmarshal([]byte(stdout), &out); err != nil {
		t.Fatalf("stdout is not JSON: %v\n%s", err, stdout)
	}
	if out.Summary.Fail != 1 || out.Summary.NeedsJudge != 0 || len(out.Data) != 1 {
		t.Fatalf("summary = %+v, data = %+v; want the case failed, not needs_judge", out.Summary, out.Data)
	}
	r := out.Data[0]
	if r.Status != "fail" || len(r.Failures) != 1 ||
		!strings.Contains(r.Failures[0], `"p202 report breakdown --keyword shoes" exited 2`) {
		t.Errorf("result = %+v, want one failure naming the command and exit 2", r)
	}
	if r.Commands != 1 || r.CommandsFailed != 1 {
		t.Errorf("commands = %d, commands_failed = %d; want 1 and 1", r.Commands, r.CommandsFailed)
	}
}

func TestEvalRunErrorContract(t *testing.T) {
	stubBin, casesFile := writeEvalFixture(t)
	tmp := t.TempDir()
	setTestHome(t, tmp)

	_, _, err := executeCommand("eval", "run", "--cases", casesFile)
	if err == nil {
		t.Fatal("expected an error without --agent-cmd")
	}
	if exitCodeForError(err) != ExitValidation {
		t.Errorf("exit code = %d, want %d", exitCodeForError(err), ExitValidation)
	}
	if hint := hintFor(err); !strings.Contains(hint, "$P202_EVAL_ASK") {
		t.Errorf("hint should describe the agent-command contract, got %q", hint)
	}

	_, _, err = executeCommand("eval", "run",
		"--cases", casesFile, "--agent-cmd", "echo hi", "--p202-bin", stubBin,
		"--only", "no-such-case")
	if err == nil || !strings.Contains(err.Error(), "no-such-case") {
		t.Fatalf("unknown --only id should be a named validation error, got %v", err)
	}
	if exitCodeForError(err) != ExitValidation {
		t.Errorf("exit code = %d, want %d", exitCodeForError(err), ExitValidation)
	}
}
