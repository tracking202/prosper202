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

	batchCmd, _ := cmd.Flags().GetString("c")
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
		if handled, newProfile := handleBuiltin(line, state, activeProfile); handled {
			if newProfile != "" {
				activeProfile = newProfile
			}
			continue
		}

		// Execute as p202 command
		output, err := executeShellCommand(line)
		if err != nil {
			fmt.Fprintf(os.Stderr, "Error: %v\n", err)
			continue
		}
		if len(output) > 0 {
			// Store raw output as $_ if it's valid JSON
			storeResult(state, output)
			fmt.Print(string(output))
			if output[len(output)-1] != '\n' {
				fmt.Println()
			}
		}
	}
	return scanner.Err()
}

// runBatch executes a list of commands sequentially. If jsonOutput is set,
// emits JSONL (one JSON object per command). Returns an error if any command
// fails and stopOnError is true.
func runBatch(commands []string, state *shell.State, stopOnError bool) error {
	anyFailed := false
	for _, cmdStr := range commands {
		cmdStr = strings.TrimSpace(cmdStr)
		if cmdStr == "" {
			continue
		}

		output, err := executeShellCommand(cmdStr)
		if err != nil {
			anyFailed = true
			if jsonOutput {
				emitBatchResult(cmdStr, nil, err)
			} else {
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
			if len(output) > 0 {
				fmt.Print(string(output))
				if output[len(output)-1] != '\n' {
					fmt.Println()
				}
			}
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

// emitBatchResult writes a single JSONL line for batch output.
func emitBatchResult(command string, output []byte, err error) {
	result := map[string]interface{}{
		"command": command,
		"success": err == nil,
	}
	if err != nil {
		result["error"] = err.Error()
	} else if len(output) > 0 {
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
// Returns (handled, newProfileName). newProfileName is non-empty only if the profile changed.
func handleBuiltin(line string, state *shell.State, currentProfile string) (bool, string) {
	lower := strings.ToLower(strings.TrimSpace(line))

	switch {
	case lower == "exit" || lower == "quit":
		os.Exit(0)
		return true, ""

	case lower == "help":
		printShellHelp()
		return true, ""

	case lower == "vars":
		fmt.Print(state.FormatVarsList())
		return true, ""

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
		return true, ""

	case lower == "use":
		fmt.Printf("Active profile: %s\n", currentProfile)
		return true, ""

	case strings.HasPrefix(lower, "use "):
		newName := strings.TrimSpace(line[4:])
		newProfile, err := switchProfile(newName)
		if err != nil {
			fmt.Fprintf(os.Stderr, "Error: %v\n", err)
			return true, ""
		}
		fmt.Printf("Switched to profile: %s\n", newProfile)
		return true, newProfile

	case strings.HasPrefix(lower, "history"):
		fmt.Println("Command history is available via terminal line editing (up/down arrows).")
		return true, ""
	}

	// Check for variable assignment: $name = command...
	if strings.HasPrefix(line, "$") {
		eqIdx := strings.Index(line, "=")
		if eqIdx > 1 {
			varName := strings.TrimSpace(line[1:eqIdx])
			cmdStr := strings.TrimSpace(line[eqIdx+1:])
			if varName != "" && cmdStr != "" {
				output, err := executeShellCommand(cmdStr)
				if err != nil {
					fmt.Fprintf(os.Stderr, "Error: %v\n", err)
					return true, ""
				}
				if len(output) > 0 {
					trimmed := bytes.TrimSpace(output)
					if json.Valid(trimmed) {
						state.Set(varName, json.RawMessage(trimmed))
					} else {
						state.Set(varName, json.RawMessage(fmt.Sprintf("%q", string(trimmed))))
					}
					fmt.Print(string(output))
					if output[len(output)-1] != '\n' {
						fmt.Println()
					}
				}
				return true, ""
			}
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
						return true, ""
					}
				}
				fmt.Println(string(val))
			} else {
				fmt.Fprintf(os.Stderr, "Variable $%s not found\n", varName)
			}
			return true, ""
		}
	}

	return false, ""
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
// Cobra command tree, capturing stdout output.
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

	// Capture stdout
	oldStdout := os.Stdout
	r, w, err := os.Pipe()
	if err != nil {
		return nil, fmt.Errorf("failed to capture output: %w", err)
	}
	os.Stdout = w

	// Build a fresh command each time to avoid flag state leaking between invocations.
	// We set args on rootCmd and execute it.
	rootCmd.SetArgs(tokens)

	// Reset global flags to avoid leaking state between commands.
	// Cobra parses persistent flags into package-level vars (jsonOutput, csvOutput, etc.)
	// which would otherwise persist across shell commands.
	// Also save the config profile override so that "use <profile>" in the shell
	// isn't clobbered by PersistentPreRunE calling SetActiveOverride(profileName).
	savedJSON := jsonOutput
	savedCSV := csvOutput
	savedProfile := profileName
	savedGroup := groupName
	savedOverride := configpkg.GetActiveOverride()

	cmdErr := rootCmd.Execute()

	// Restore globals that may have been modified
	jsonOutput = savedJSON
	csvOutput = savedCSV
	profileName = savedProfile
	groupName = savedGroup
	configpkg.SetActiveOverride(savedOverride)

	// Read captured output
	w.Close()
	captured, _ := io.ReadAll(r)
	r.Close()
	os.Stdout = oldStdout

	if cmdErr != nil {
		// Still return any output produced before the error
		if len(captured) > 0 {
			fmt.Print(string(captured))
		}
		return nil, cmdErr
	}
	return captured, nil
}

// storeResult saves raw output as the $_ variable if it's valid JSON.
func storeResult(state *shell.State, output []byte) {
	trimmed := bytes.TrimSpace(output)
	if len(trimmed) == 0 {
		return
	}
	if json.Valid(trimmed) {
		state.SetLast(json.RawMessage(trimmed))
	}
}

func init() {
	shellCmd.Flags().StringP("c", "c", "", "Execute commands and exit (semicolon-separated)")
	shellCmd.Flags().String("script", "", "Read and execute commands from a file")
	shellCmd.Flags().Bool("stop-on-error", false, "Stop batch execution on first error")
	rootCmd.AddCommand(shellCmd)
}
