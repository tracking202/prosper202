package cmd

import (
	"bufio"
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"strings"

	"p202/internal/api"
	configpkg "p202/internal/config"
	"p202/internal/shell"

	"github.com/spf13/cobra"
)

var shellCmd = &cobra.Command{
	Use:   "shell",
	Short: "Interactive shell with session state and batch execution",
	Long: `Start an interactive shell for running multiple commands in a persistent session.

Features:
  - Session state: command results stored in $_ (last result)
  - Profile switching: "use <profile>" changes active profile without restarting
  - Batch mode: -c "cmd1; cmd2" or piped stdin for AI agent integration
  - Non-fatal errors: errors display but don't exit the session

Requires a valid API key. Access is verified against the server capabilities endpoint.

Examples:
  p202 shell                              # interactive mode
  p202 shell -c "campaign list; health"   # batch: run commands and exit
  echo "health" | p202 shell              # batch: read from stdin
  p202 shell --script commands.txt        # batch: read from file`,
	RunE: runShell,
}

func runShell(cmd *cobra.Command, args []string) error {
	if err := verifyShellAccess(); err != nil {
		return err
	}

	batchCmd, _ := cmd.Flags().GetString("command")
	scriptFile, _ := cmd.Flags().GetString("script")
	stopOnError, _ := cmd.Flags().GetBool("stop-on-error")

	state := shell.NewState()

	// Batch mode: -c flag
	if batchCmd != "" {
		commands, err := shell.SplitCommands(batchCmd)
		if err != nil {
			return fmt.Errorf("parse error: %w", err)
		}
		return runBatch(commands, state, stopOnError)
	}

	// Batch mode: --script flag
	if scriptFile != "" {
		raw, err := os.ReadFile(scriptFile)
		if err != nil {
			return err
		}
		commands, err := shell.SplitCommands(string(raw))
		if err != nil {
			return fmt.Errorf("parse error in %s: %w", scriptFile, err)
		}
		return runBatch(commands, state, stopOnError)
	}

	// Batch mode: piped stdin (not a terminal)
	if !isTerminal(os.Stdin) {
		return runStdinBatch(os.Stdin, state, stopOnError)
	}

	// Interactive mode
	return runInteractive(state)
}

// verifyShellAccess checks that the user has a valid API key and that
// the server grants shell access via the capabilities endpoint.
func verifyShellAccess() error {
	client, err := api.NewFromConfig()
	if err != nil {
		return fmt.Errorf("shell requires a configured API key: %w", err)
	}

	if !client.SupportsCapability("shell") {
		// Distinguish "the server said no" from "we couldn't ask the server".
		if capErr := client.CapabilitiesError(); capErr != nil {
			return fmt.Errorf("could not verify shell access: %w", capErr)
		}
		return fmt.Errorf("shell access is not enabled for this API key. Contact support to upgrade your plan")
	}
	return nil
}

func isTerminal(f *os.File) bool {
	fi, err := f.Stat()
	if err != nil {
		return false
	}
	return (fi.Mode() & os.ModeCharDevice) != 0
}

// runInteractive runs the REPL loop with a prompt, reading from stdin line by line.
func runInteractive(state *shell.State) error {
	scanner := bufio.NewScanner(os.Stdin)
	// Allow long pasted lines; the default 64K token limit is easy to hit.
	scanner.Buffer(make([]byte, 0, 64*1024), 10*1024*1024)
	activeProfile := currentProfileName()

	fmt.Println("p202 interactive shell. Type 'help' for commands, 'exit' to quit.")
	for {
		fmt.Printf("[%s]> ", activeProfile)
		if !scanner.Scan() {
			fmt.Println()
			break
		}
		line := strings.TrimSpace(scanner.Text())
		if line == "" {
			continue
		}

		// Handle built-in commands
		if handled, newProfile, exit := handleBuiltin(line, state, activeProfile); handled {
			if exit {
				return nil
			}
			if newProfile != "" {
				activeProfile = newProfile
			}
			continue
		}

		// Execute as p202 command
		output, err := executeShellCommand(line)
		if err != nil {
			printOutput(output) // partial output produced before the error
			fmt.Fprintf(os.Stderr, "Error: %v\n", err)
			continue
		}
		storeResult(state, output)
		printOutput(output)
	}
	return scanner.Err()
}

// runBatch executes a list of commands sequentially. Shell built-ins
// (use, vars, $name = ..., exit, ...) work the same as in interactive mode.
// If jsonOutput is set, emits JSONL (one JSON object per command). Returns
// an error if any command fails and stopOnError is true.
func runBatch(commands []string, state *shell.State, stopOnError bool) error {
	anyFailed := false
	activeProfile := currentProfileName()
	for _, cmdStr := range commands {
		cmdStr = strings.TrimSpace(cmdStr)
		if cmdStr == "" {
			continue
		}

		var handled, exit bool
		var newProfile string
		if jsonOutput {
			// Capture built-in output so it doesn't corrupt the JSONL stream.
			builtinOut := captureStdout(func() {
				handled, newProfile, exit = handleBuiltin(cmdStr, state, activeProfile)
			})
			if handled {
				emitBatchResult(cmdStr, builtinOut, nil)
			}
		} else {
			handled, newProfile, exit = handleBuiltin(cmdStr, state, activeProfile)
		}
		if handled {
			if exit {
				break
			}
			if newProfile != "" {
				activeProfile = newProfile
			}
			continue
		}

		output, err := executeShellCommand(cmdStr)
		if err != nil {
			anyFailed = true
			if jsonOutput {
				emitBatchResult(cmdStr, output, err)
			} else {
				printOutput(output) // partial output produced before the error
				fmt.Fprintf(os.Stderr, "Error [%s]: %v\n", cmdStr, err)
			}
			if stopOnError {
				return fmt.Errorf("stopped on error: %v", err)
			}
			continue
		}

		if jsonOutput {
			emitBatchResult(cmdStr, output, nil)
		} else {
			printOutput(output)
		}
		storeResult(state, output)
	}

	if anyFailed {
		return fmt.Errorf("one or more commands failed")
	}
	return nil
}

// runStdinBatch reads commands from a reader (one per line, or semicolon-separated).
func runStdinBatch(r io.Reader, state *shell.State, stopOnError bool) error {
	raw, err := io.ReadAll(r)
	if err != nil {
		return fmt.Errorf("failed to read stdin: %w", err)
	}
	commands, err := shell.SplitCommands(string(raw))
	if err != nil {
		return fmt.Errorf("parse error: %w", err)
	}
	return runBatch(commands, state, stopOnError)
}

// emitBatchResult writes a single JSONL line for batch output. Partial
// output produced before an error is included alongside the error.
func emitBatchResult(command string, output []byte, err error) {
	result := map[string]interface{}{
		"command": command,
		"success": err == nil,
	}
	if err != nil {
		result["error"] = err.Error()
	}
	if len(output) > 0 {
		var parsed interface{}
		if json.Unmarshal(output, &parsed) == nil {
			result["data"] = parsed
		} else {
			result["output"] = strings.TrimSpace(string(output))
		}
	}
	line, _ := json.Marshal(result)
	fmt.Println(string(line))
}

// handleBuiltin checks for shell-specific commands that are not Cobra subcommands.
// Returns (handled, newProfileName, exit). newProfileName is non-empty only if
// the profile changed; exit is true when the session should end.
func handleBuiltin(line string, state *shell.State, currentProfile string) (bool, string, bool) {
	lower := strings.ToLower(strings.TrimSpace(line))

	switch {
	case lower == "exit" || lower == "quit":
		// Signal the caller to stop instead of os.Exit, so deferred cleanup runs.
		return true, "", true

	case lower == "help":
		printShellHelp()
		return true, "", false

	case lower == "vars":
		fmt.Print(state.FormatVarsList())
		return true, "", false

	case strings.HasPrefix(lower, "unset "):
		name := strings.TrimSpace(line[6:])
		name = strings.TrimPrefix(name, "$")
		if name == "" {
			fmt.Fprintln(os.Stderr, "Usage: unset <variable>")
		} else if state.Delete(name) {
			fmt.Printf("Deleted $%s\n", name)
		} else {
			fmt.Fprintf(os.Stderr, "Variable $%s not found\n", name)
		}
		return true, "", false

	case lower == "use":
		fmt.Printf("Active profile: %s\n", currentProfile)
		return true, "", false

	case strings.HasPrefix(lower, "use "):
		newName := strings.TrimSpace(line[4:])
		newProfile, err := switchProfile(newName)
		if err != nil {
			fmt.Fprintf(os.Stderr, "Error: %v\n", err)
			return true, "", false
		}
		fmt.Printf("Switched to profile: %s\n", newProfile)
		return true, newProfile, false

	case strings.HasPrefix(lower, "history"):
		fmt.Println("Command history is available via terminal line editing (up/down arrows).")
		return true, "", false
	}

	// Check for variable assignment: $name = command...
	if strings.HasPrefix(line, "$") {
		if eqIdx := strings.Index(line, "="); eqIdx >= 0 {
			varName := strings.TrimSpace(line[1:eqIdx])
			cmdStr := strings.TrimSpace(line[eqIdx+1:])
			if varName == "" || strings.ContainsAny(varName, " \t") {
				fmt.Fprintln(os.Stderr, "Syntax error: expected $name = command")
				return true, "", false
			}
			if cmdStr == "" {
				fmt.Fprintf(os.Stderr, "Syntax error: assignment to $%s requires a command\n", varName)
				return true, "", false
			}
			output, err := executeShellCommand(cmdStr)
			if err != nil {
				printOutput(output) // partial output produced before the error
				fmt.Fprintf(os.Stderr, "Error: %v\n", err)
				return true, "", false
			}
			if value, ok := normalizeValue(output); ok {
				state.Set(varName, value)
			}
			printOutput(output)
			return true, "", false
		}

		// Variable reference: $name (just print it)
		varName := strings.TrimSpace(line[1:])
		if varName != "" && !strings.Contains(varName, " ") {
			if val, ok := state.Get(varName); ok {
				var parsed interface{}
				if json.Unmarshal(val, &parsed) == nil {
					pretty, err := json.MarshalIndent(parsed, "", "  ")
					if err == nil {
						fmt.Println(string(pretty))
						return true, "", false
					}
				}
				fmt.Println(string(val))
			} else {
				fmt.Fprintf(os.Stderr, "Variable $%s not found\n", varName)
			}
			return true, "", false
		}
	}

	return false, "", false
}

func printShellHelp() {
	fmt.Print(`Shell commands:
  help                Show this help
  use [profile]       Show or switch the active profile
  vars                List stored variables
  unset $name         Delete a stored variable
  $name = command     Run command and store result in $name
  $name               Print stored variable
  exit / quit         Exit the shell

All p202 commands work directly (without the p202 prefix):
  campaign list       List campaigns
  report summary      Run a summary report
  config show         Show current config
  ...

Batch mode (for AI agents):
  p202 shell -c "cmd1; cmd2; cmd3"
  echo "cmd1\ncmd2" | p202 shell
  p202 shell --script file.txt
`)
}

// switchProfile validates and activates a profile for the current shell session.
// This uses the in-memory override, not persisted config.
func switchProfile(name string) (string, error) {
	name = strings.TrimSpace(name)
	if name == "" {
		return "", fmt.Errorf("profile name is required")
	}

	cfg, err := configpkg.Load()
	if err != nil {
		return "", err
	}
	if _, exists := cfg.Profiles[name]; !exists {
		return "", fmt.Errorf("profile %q not found. available: %s", name, strings.Join(cfg.ProfileNames(), ", "))
	}

	configpkg.SetActiveOverride(name)
	return name, nil
}

// currentProfileName returns the name of the currently active profile.
func currentProfileName() string {
	_, name, err := configpkg.LoadProfileWithName("")
	if err != nil {
		return "default"
	}
	return name
}

// executeShellCommand parses a command line and dispatches it through the
// Cobra command tree, capturing stdout output. On error, any partial output
// produced before the failure is returned alongside the error; the caller
// decides how to surface it (printing it here would corrupt JSONL output).
func executeShellCommand(line string) ([]byte, error) {
	tokens, err := shell.TokenizeLine(line)
	if err != nil {
		return nil, fmt.Errorf("parse error: %w", err)
	}
	if len(tokens) == 0 {
		return nil, nil
	}

	// Prevent recursive shell invocation
	if tokens[0] == "shell" {
		return nil, fmt.Errorf("cannot run shell from within shell")
	}

	// Save session-level state. Cobra retains parsed flag values and their
	// Changed markers between Execute() calls, so a --force or --limit from
	// an earlier shell command would silently apply to later ones. Reset the
	// whole tree to defaults, then re-apply the session's persistent flags.
	savedJSON := jsonOutput
	savedCSV := csvOutput
	savedProfile := profileName
	savedGroup := groupName
	sessionOverride := configpkg.GetActiveOverride()

	resetAllFlags(rootCmd)
	jsonOutput = savedJSON
	csvOutput = savedCSV
	profileName = savedProfile
	groupName = savedGroup
	// PersistentPreRunE sets the active override from the --profile flag,
	// which would clobber "use <profile>". Route the session's profile
	// through the flag variable so the executed command actually uses it.
	if sessionOverride != "" {
		profileName = sessionOverride
	}

	rootCmd.SetArgs(tokens)

	var cmdErr error
	captured := captureStdout(func() {
		cmdErr = rootCmd.Execute()
	})

	// Restore session-level state the command's own flags may have modified.
	jsonOutput = savedJSON
	csvOutput = savedCSV
	profileName = savedProfile
	groupName = savedGroup
	configpkg.SetActiveOverride(sessionOverride)

	if cmdErr != nil {
		return captured, cmdErr
	}
	return captured, nil
}

// captureStdout runs fn with os.Stdout redirected to a pipe and returns what
// was written. The pipe is drained concurrently so writers never block on the
// kernel pipe buffer (~64KB), which would deadlock on large command output.
func captureStdout(fn func()) []byte {
	r, w, err := os.Pipe()
	if err != nil {
		// Can't capture; run uncaptured rather than fail the command.
		fn()
		return nil
	}

	oldStdout := os.Stdout
	os.Stdout = w

	done := make(chan []byte, 1)
	go func() {
		buf, _ := io.ReadAll(r)
		done <- buf
	}()

	fn()

	os.Stdout = oldStdout
	_ = w.Close()
	captured := <-done
	_ = r.Close()
	return captured
}

// printOutput writes command output to stdout, ensuring a trailing newline.
func printOutput(output []byte) {
	if len(output) == 0 {
		return
	}
	fmt.Print(string(output))
	if output[len(output)-1] != '\n' {
		fmt.Println()
	}
}

// normalizeValue converts command output into a JSON value for session
// variables: JSON output is stored as-is, anything else as a JSON string.
// Returns false for empty output.
func normalizeValue(output []byte) (json.RawMessage, bool) {
	trimmed := bytes.TrimSpace(output)
	if len(trimmed) == 0 {
		return nil, false
	}
	if json.Valid(trimmed) {
		return json.RawMessage(trimmed), true
	}
	quoted, err := json.Marshal(string(trimmed))
	if err != nil {
		return nil, false
	}
	return json.RawMessage(quoted), true
}

// storeResult saves command output as the $_ variable.
func storeResult(state *shell.State, output []byte) {
	if value, ok := normalizeValue(output); ok {
		state.SetLast(value)
	}
}

func init() {
	shellCmd.Flags().StringP("command", "c", "", "Execute commands and exit (semicolon-separated)")
	shellCmd.Flags().String("script", "", "Read and execute commands from a file")
	shellCmd.Flags().Bool("stop-on-error", false, "Stop batch execution on first error")
	rootCmd.AddCommand(shellCmd)
}
