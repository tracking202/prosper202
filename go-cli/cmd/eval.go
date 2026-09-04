package cmd

import (
	"encoding/json"
	"fmt"
	"os"
	"strings"
	"time"

	"p202/internal/eval"

	"github.com/spf13/cobra"
)

var evalCmd = &cobra.Command{
	Use:   "eval",
	Short: "Behavioral evals for an agent that operates this instance",
}

var evalRunCmd = &cobra.Command{
	Use:   "run",
	Short: "Run eval cases against a pluggable agent command",
	Long: "Run behavioral snapshot evals: for each case the runner performs the setup\n" +
		"commands, hands the ask to --agent-cmd (stdin and $P202_EVAL_ASK), captures\n" +
		"every `p202` invocation the agent makes (a PATH shim — no adapter needed),\n" +
		"re-reads instance state, and grades the case's expectations. Cases follow\n" +
		"the shape in .claude/skills/p202-agent-evals/SKILL.md; a starter file ships\n" +
		"in tests/fixtures/agent-eval/cases/.\n\n" +
		"The agent command must read the ask, drive `p202` (the one on PATH — that\n" +
		"is the capture shim), print the agent's reply to stdout, and exit 0.\n" +
		"Rubric grading is optional: --judge-cmd receives {id, ask, rubric, reply,\n" +
		"commands} as JSON on stdin and must print a line starting with PASS or\n" +
		"FAIL; without it, rubric cases report needs_judge instead of pass.\n\n" +
		"Exit codes: 0 when nothing failed; 5 (partial_failure) when cases failed\n" +
		"or errored — results are still on stdout.",
	Args: cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		casesPath, _ := cmd.Flags().GetString("cases")
		if strings.TrimSpace(casesPath) == "" {
			return validationError("--cases is required").
				WithHint("Point it at a case file or directory; tests/fixtures/agent-eval/cases/ ships a starter, and the p202-agent-evals skill documents the shape.")
		}
		agentCmd, _ := cmd.Flags().GetString("agent-cmd")
		if strings.TrimSpace(agentCmd) == "" {
			return validationError("--agent-cmd is required").
				WithHint("Pass the shell command that runs your agent: it reads the ask (stdin or $P202_EVAL_ASK), drives `p202`, prints the agent's reply to stdout, and exits 0.")
		}

		cases, err := eval.LoadCases(casesPath)
		if err != nil {
			return withHint(fmt.Errorf("loading cases: %w", err),
				"Case files are JSON (an array, or {\"cases\":[...]}); the p202-agent-evals skill documents every field.")
		}

		cases, err = filterCases(cmd, cases)
		if err != nil {
			return err
		}

		p202Bin, _ := cmd.Flags().GetString("p202-bin")
		if strings.TrimSpace(p202Bin) == "" {
			self, err := os.Executable()
			if err != nil {
				return validationError("cannot locate the p202 binary: %v", err).
					WithHint("Pass it explicitly with --p202-bin.")
			}
			p202Bin = self
		}

		timeoutSecs, _ := cmd.Flags().GetInt("timeout")
		if timeoutSecs < 1 {
			return validationError("--timeout must be at least 1 second")
		}
		judgeCmd, _ := cmd.Flags().GetString("judge-cmd")

		runner := &eval.Runner{
			P202Bin:  p202Bin,
			AgentCmd: agentCmd,
			JudgeCmd: judgeCmd,
			Timeout:  time.Duration(timeoutSecs) * time.Second,
			Stderr:   os.Stderr,
		}
		results, err := runner.Run(cases)
		if err != nil {
			return withHint(fmt.Errorf("eval run: %w", err),
				"Check --p202-bin points at a p202 binary and that a POSIX `sh` is available.")
		}

		summary := eval.Summarize(results)
		encoded, err := json.Marshal(map[string]interface{}{
			"data":    results,
			"summary": summary,
		})
		if err != nil {
			return fmt.Errorf("encoding results: %w", err)
		}
		render(encoded)

		if summary.NeedsJudge > 0 && strings.TrimSpace(judgeCmd) == "" {
			fmt.Fprintf(os.Stderr, "Note: %d case(s) have a rubric and need --judge-cmd to fully pass.\n", summary.NeedsJudge)
		}
		if failed := summary.Fail + summary.Error; failed > 0 {
			return partialFailureError("%d of %d eval cases failed", failed, summary.Total).
				WithHint("Each failing result's `failures` lines name the expectation that broke; rerun one case with --only <id> while iterating.")
		}
		return nil
	},
}

// filterCases applies --only and --priority. Selecting nothing is an error —
// a silently empty run would read as a pass.
func filterCases(cmd *cobra.Command, cases []eval.Case) ([]eval.Case, error) {
	only, _ := cmd.Flags().GetString("only")
	priorities, _ := cmd.Flags().GetString("priority")

	if strings.TrimSpace(only) != "" {
		wanted := map[string]bool{}
		for _, id := range strings.Split(only, ",") {
			if trimmed := strings.TrimSpace(id); trimmed != "" {
				wanted[trimmed] = true
			}
		}
		var kept []eval.Case
		for _, c := range cases {
			if wanted[c.ID] {
				kept = append(kept, c)
				delete(wanted, c.ID)
			}
		}
		if len(wanted) > 0 {
			missing := make([]string, 0, len(wanted))
			for id := range wanted {
				missing = append(missing, id)
			}
			return nil, validationError("--only names unknown case id(s): %s", strings.Join(missing, ", ")).
				WithHint("Case ids come from the `id` field of the case files.")
		}
		cases = kept
	}

	if strings.TrimSpace(priorities) != "" {
		wanted := map[string]bool{}
		for _, p := range strings.Split(priorities, ",") {
			if trimmed := strings.TrimSpace(strings.ToLower(p)); trimmed != "" {
				wanted[trimmed] = true
			}
		}
		var kept []eval.Case
		for _, c := range cases {
			if wanted[c.Priority] {
				kept = append(kept, c)
			}
		}
		cases = kept
	}

	if len(cases) == 0 {
		return nil, validationError("the filters selected no cases").
			WithHint("Loosen --only/--priority; an empty run must not read as a pass.")
	}
	return cases, nil
}

func init() {
	evalRunCmd.Flags().String("cases", "", "Case file or directory of *.json case files (required)")
	evalRunCmd.Flags().String("agent-cmd", "", "Shell command that runs the agent under test (required)")
	evalRunCmd.Flags().String("judge-cmd", "", "Shell command that grades rubrics (stdin: JSON; stdout: PASS/FAIL verdict)")
	evalRunCmd.Flags().Int("timeout", 300, "Per-command timeout in seconds (agent, setup, checks)")
	evalRunCmd.Flags().String("p202-bin", "", "Real p202 binary the capture shim execs (default: this binary)")
	evalRunCmd.Flags().String("only", "", "Comma-separated case ids to run")
	evalRunCmd.Flags().String("priority", "", "Comma-separated priorities to run (critical,high,medium,low)")

	evalCmd.AddCommand(evalRunCmd)
	rootCmd.AddCommand(evalCmd)
}
