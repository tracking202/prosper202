package eval

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"time"
)

// shimScript is installed first on PATH for every command the runner
// executes. Any process that invokes `p202` hits it: when P202_EVAL_CMDLOG
// is set (the agent phase) the invocation is appended to that file, then the
// real binary runs. That is what makes command capture agent-agnostic — an
// agent needs no adapter beyond shelling out to p202 as usual.
const shimScript = `#!/bin/sh
if [ -n "$P202_EVAL_CMDLOG" ]; then
  printf 'p202 %s\n' "$*" >> "$P202_EVAL_CMDLOG"
fi
exec "$P202_EVAL_REAL_BIN" "$@"
`

// Runner executes eval cases against a pluggable agent command.
type Runner struct {
	// P202Bin is the real p202 binary the PATH shim execs (and that
	// setup/state/check commands resolve `p202` to).
	P202Bin string
	// AgentCmd runs once per case through `sh -c`. It receives the ask on
	// stdin and in P202_EVAL_ASK, must run its p202 calls through the
	// `p202` on PATH, and print the agent's reply to stdout, exiting 0.
	AgentCmd string
	// JudgeCmd, when set, grades each case's rubric: it receives a JSON
	// object {id, ask, rubric, reply, commands} on stdin and must print a
	// verdict line starting with PASS or FAIL.
	JudgeCmd string
	// Timeout bounds one agent invocation (default 5m). Setup, state, and
	// check commands get the same bound individually.
	Timeout time.Duration
	// Stderr receives progress lines and the agent's own stderr.
	Stderr io.Writer
}

// Run executes every case in order and returns one Result per case. An error
// is returned only for run-level problems (bad configuration); per-case
// problems become StatusError results so one broken case cannot hide the
// rest of the suite.
func (r *Runner) Run(cases []Case) ([]Result, error) {
	if runtime.GOOS == "windows" {
		return nil, fmt.Errorf("eval run needs a POSIX shell for the agent command and the p202 capture shim; run it under WSL on Windows")
	}
	if strings.TrimSpace(r.AgentCmd) == "" {
		return nil, fmt.Errorf("no agent command configured")
	}
	bin, err := filepath.Abs(r.P202Bin)
	if err != nil {
		return nil, fmt.Errorf("resolving p202 binary path %q: %w", r.P202Bin, err)
	}
	if info, err := os.Stat(bin); err != nil || info.IsDir() {
		return nil, fmt.Errorf("p202 binary not found at %s", bin)
	}
	if r.Stderr == nil {
		r.Stderr = io.Discard
	}
	if r.Timeout <= 0 {
		r.Timeout = 5 * time.Minute
	}

	shimDir, err := os.MkdirTemp("", "p202-eval-shim-")
	if err != nil {
		return nil, fmt.Errorf("creating shim directory: %w", err)
	}
	defer func() {
		if rmErr := os.RemoveAll(shimDir); rmErr != nil {
			fmt.Fprintf(os.Stderr, "warning: could not remove shim dir %s: %v\n", shimDir, rmErr)
		}
	}()
	if err := os.WriteFile(filepath.Join(shimDir, "p202"), []byte(shimScript), 0o700); err != nil {
		return nil, fmt.Errorf("writing p202 shim: %w", err)
	}

	baseEnv := append(os.Environ(),
		"PATH="+shimDir+string(os.PathListSeparator)+os.Getenv("PATH"),
		"P202_EVAL_REAL_BIN="+bin,
	)

	results := make([]Result, 0, len(cases))
	for _, c := range cases {
		results = append(results, r.runCase(c, baseEnv, shimDir))
	}
	return results, nil
}

func (r *Runner) runCase(c Case, baseEnv []string, shimDir string) Result {
	start := time.Now()
	result := Result{ID: c.ID, Priority: c.Priority}
	finish := func() Result {
		result.DurationMs = time.Since(start).Milliseconds()
		return result
	}

	if c.Skip != "" {
		fmt.Fprintf(r.Stderr, "SKIP %s: %s\n", c.ID, c.Skip)
		result.Status = StatusSkip
		result.Failures = []string{"skipped: " + c.Skip}
		return finish()
	}
	fmt.Fprintf(r.Stderr, "RUN  %s\n", c.ID)

	// Cleanup runs whatever happened after setup began; failures there are
	// reported but do not change the grade.
	defer func() {
		for _, cmd := range c.Cleanup {
			if _, stderr, err := r.shell(cmd, baseEnv, ""); err != nil {
				fmt.Fprintf(r.Stderr, "cleanup %s: %q failed: %v\n%s", c.ID, cmd, err, stderr)
			}
		}
	}()

	for _, cmd := range c.Setup {
		if _, stderr, err := r.shell(cmd, baseEnv, ""); err != nil {
			result.Status = StatusError
			result.Failures = append(result.Failures, fmt.Sprintf("setup %q failed: %v: %s", cmd, err, firstLine(stderr)))
			return finish()
		}
	}

	stateBefore := map[string]string{}
	for _, cmd := range c.Expected.StateUnchanged {
		out, stderr, err := r.shell(cmd, baseEnv, "")
		if err != nil {
			result.Status = StatusError
			result.Failures = append(result.Failures, fmt.Sprintf("state_unchanged %q failed before the turn: %v: %s", cmd, err, firstLine(stderr)))
			return finish()
		}
		stateBefore[cmd] = out
	}

	cmdLog := filepath.Join(shimDir, "cmdlog-"+sanitizeID(c.ID)+".log")
	_ = os.Remove(cmdLog)
	agentEnv := append(append([]string{}, baseEnv...),
		"P202_EVAL_CMDLOG="+cmdLog,
		"P202_EVAL_ASK="+c.Ask,
		"P202_EVAL_CASE_ID="+c.ID,
	)
	reply, agentStderr, agentErr := r.shell(r.AgentCmd, agentEnv, c.Ask)
	if agentStderr != "" {
		fmt.Fprint(r.Stderr, agentStderr)
	}
	if agentErr != nil {
		result.Status = StatusError
		result.Failures = append(result.Failures, fmt.Sprintf("agent command failed: %v: %s", agentErr, firstLine(agentStderr)))
		return finish()
	}

	commands := readCommandLog(cmdLog)
	result.Commands = len(commands)

	stateAfter := map[string]string{}
	stateErrs := map[string]string{}
	for _, cmd := range c.Expected.StateUnchanged {
		out, stderr, err := r.shell(cmd, baseEnv, "")
		if err != nil {
			stateErrs[cmd] = fmt.Sprintf("%v: %s", err, firstLine(stderr))
			continue
		}
		stateAfter[cmd] = out
	}

	checkOutputs := map[string]string{}
	checkErrs := map[string]string{}
	for _, chk := range c.Expected.Checks {
		out, stderr, err := r.shell(chk.Run, baseEnv, "")
		if err != nil {
			checkErrs[chk.Run] = fmt.Sprintf("%v: %s", err, firstLine(stderr))
			continue
		}
		checkOutputs[chk.Run] = out
	}

	result.Failures = grade(c.Expected, commands, reply, stateBefore, stateAfter, stateErrs, checkOutputs, checkErrs)

	// The rubric half: judged when a judge is configured, otherwise the
	// case cannot fully pass and says so instead of passing silently.
	if strings.TrimSpace(c.Expected.Rubric) != "" {
		if strings.TrimSpace(r.JudgeCmd) == "" {
			if len(result.Failures) == 0 {
				result.Status = StatusNeedsJudge
				return finish()
			}
		} else {
			verdict, err := r.judge(c, reply, commands, baseEnv)
			result.Judge = verdict
			if err != nil {
				result.Status = StatusError
				result.Failures = append(result.Failures, "judge failed: "+err.Error())
				return finish()
			}
			if !strings.HasPrefix(strings.ToUpper(strings.TrimSpace(verdict)), "PASS") {
				result.Failures = append(result.Failures, "rubric: "+firstLine(verdict))
			}
		}
	}

	if len(result.Failures) == 0 {
		result.Status = StatusPass
	} else {
		result.Status = StatusFail
	}
	return finish()
}

func (r *Runner) judge(c Case, reply string, commands []string, env []string) (string, error) {
	input, err := json.Marshal(map[string]interface{}{
		"id":       c.ID,
		"ask":      c.Ask,
		"rubric":   c.Expected.Rubric,
		"reply":    reply,
		"commands": commands,
	})
	if err != nil {
		return "", fmt.Errorf("encoding judge input: %w", err)
	}
	out, stderr, err := r.shell(r.JudgeCmd, env, string(input))
	if err != nil {
		return "", fmt.Errorf("%w: %s", err, firstLine(stderr))
	}
	verdict := strings.TrimSpace(out)
	if verdict == "" {
		return "", fmt.Errorf("judge printed no verdict")
	}
	upper := strings.ToUpper(verdict)
	if !strings.HasPrefix(upper, "PASS") && !strings.HasPrefix(upper, "FAIL") {
		return verdict, fmt.Errorf("judge verdict must start with PASS or FAIL, got %q", firstLine(verdict))
	}
	return verdict, nil
}

// shell runs one command line through `sh -c` with the given environment and
// stdin, bounded by the runner's timeout.
func (r *Runner) shell(command string, env []string, stdin string) (string, string, error) {
	ctx, cancel := context.WithTimeout(context.Background(), r.Timeout)
	defer cancel()

	cmd := exec.CommandContext(ctx, "sh", "-c", command)
	cmd.Env = env
	cmd.Stdin = strings.NewReader(stdin)
	var stdout, stderr bytes.Buffer
	cmd.Stdout = &stdout
	cmd.Stderr = &stderr

	// Run the command in its own process group and kill the whole group on
	// timeout. Killing only `sh` leaves grandchildren alive holding the
	// inherited stdout pipe, and because Stdout is a buffer, Wait blocks on
	// the copy until EOF — so an agent that backgrounds a child would hang
	// the run forever despite the timeout. WaitDelay is the backstop for a
	// process that escapes the group (setsid of its own).
	setProcessGroup(cmd)
	cmd.Cancel = func() error { return killProcessGroup(cmd) }
	cmd.WaitDelay = 5 * time.Second

	err := cmd.Run()
	if ctx.Err() == context.DeadlineExceeded {
		err = fmt.Errorf("timed out after %s", r.Timeout)
	}
	return stdout.String(), stderr.String(), err
}

func readCommandLog(path string) []string {
	raw, err := os.ReadFile(path)
	if err != nil {
		return nil // the agent ran no p202 commands
	}
	var commands []string
	for _, line := range strings.Split(string(raw), "\n") {
		if trimmed := strings.TrimSpace(line); trimmed != "" {
			commands = append(commands, trimmed)
		}
	}
	return commands
}

func sanitizeID(id string) string {
	var b strings.Builder
	for _, r := range id {
		if (r >= 'a' && r <= 'z') || (r >= 'A' && r <= 'Z') || (r >= '0' && r <= '9') || r == '-' || r == '_' {
			b.WriteRune(r)
		} else {
			b.WriteRune('-')
		}
	}
	return b.String()
}

func firstLine(s string) string {
	s = strings.TrimSpace(s)
	if i := strings.IndexByte(s, '\n'); i >= 0 {
		return s[:i]
	}
	return s
}
