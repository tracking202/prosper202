package cmd

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"strings"

	"p202/internal/api"
	configpkg "p202/internal/config"

	"github.com/spf13/cobra"
	"github.com/spf13/pflag"
)

var jsonOutput bool
var csvOutput bool
var quietOutput bool
var ndjsonOutput bool
var wideOutput bool
var rawHeaders bool
var fieldsFlag string
var profileName string
var groupName string
var stagedWrites bool

// rootLong is the root help text; Execute appends the alias list. The
// last lines tell an agent how to find a command without walking --help.
const rootLong = "p202 is a command-line tool for managing a Prosper202 tracking instance.\n" +
	"Designed for both human operators and AI agents.\n\n" +
	"Finding a command: `p202 commands --brief` lists every command on one line with\n" +
	"the web UI page it does (about 6,000 tokens; an agent reads it and chooses).\n" +
	"`p202 search <what you want to do>` ranks commands, flags and flag values by your\n" +
	"words; `p202 commands --json` lists every command and flag (with allowed values)."

var rootCmd = &cobra.Command{
	Use:           "p202",
	Short:         "Prosper202 CLI",
	Long:          rootLong, // alias list appended dynamically in Execute()
	SilenceErrors: true,
	SilenceUsage:  true,
	PersistentPreRunE: func(cmd *cobra.Command, args []string) error {
		activeCommandPath = cmd.CommandPath()
		configpkg.SetActiveOverride(profileName)
		api.SetStagedMode(stagedWrites)
		if err := resolveOutputMode(parsedOutputFlags()); err != nil {
			return err
		}
		// Before any command reads its flags: an explicitly empty value
		// would otherwise read as "not given" (empty_flags.go).
		if err := refuseEmptyStringFlags(cmd); err != nil {
			return err
		}
		return refuseInvalidEnumFlags(cmd)
	},
}

func buildAliasHelp() string {
	var parts []string
	for _, cmd := range rootCmd.Commands() {
		for _, alias := range cmd.Aliases {
			parts = append(parts, fmt.Sprintf("%s (%s)", alias, cmd.Name()))
		}
	}
	if len(parts) == 0 {
		return ""
	}
	return "\n\nUI-friendly aliases: " + strings.Join(parts, ", ") + ". Original names also work."
}

// outputHelp is the output paragraph of `p202 --help`.
const outputHelp = "\n\nOutput: tables for people. When an AI agent runs p202 (any of AI_AGENT,\n" +
	"CLAUDECODE, GEMINI_CLI, CODEX_SANDBOX, CODEX_SANDBOX_NETWORK_DISABLED,\n" +
	"CODEX_THREAD_ID or CURSOR_AGENT is set), commands print compact one-line JSON\n" +
	"and errors print as a JSON envelope on stderr. A format flag always wins:\n" +
	"--json (pretty), --ndjson, --csv, -q, --table. Without one, P202_OUTPUT\n" +
	"(json, table, ndjson or csv), then `p202 config set-default output.format\n" +
	"<format>`, decide. `p202 config show` says which format is in use and why."

func Execute() {
	rootCmd.Long = rootLong + outputHelp + buildAliasHelp()
	err := unknownSubcommandError(rootCmd, os.Args[1:])
	if err == nil {
		err = rootCmd.Execute()
	}
	if err != nil {
		recoverCommandContext(os.Args[1:])
		printError(os.Stderr, err)
		os.Exit(exitCodeForError(err))
	}
}

// unknownSubcommandError refuses a word after a command group that names
// none of its subcommands ("p202 campaign lsit"). Cobra checks this only at
// the root; below it, it prints the group's help and exits 0, which reads as
// success. Returns nil when args resolve to a command, or to a group with no
// further words.
func unknownSubcommandError(root *cobra.Command, args []string) error {
	// Find's own error is for an unknown word at the root, which Cobra
	// reports itself when the command runs.
	cmd, rest, _ := root.Find(args)
	if cmd == nil || cmd == root || cmd.Runnable() || !cmd.HasSubCommands() {
		return nil
	}
	for i := 0; i < len(rest); i++ {
		a := rest[i]
		if a == "--" {
			return nil
		}
		if strings.HasPrefix(a, "-") {
			// A flag given as "--name value" takes the next word with it.
			if !strings.Contains(a, "=") {
				if f := cmd.Flag(strings.TrimLeft(a, "-")); f != nil && f.NoOptDefVal == "" {
					i++
				}
			}
			continue
		}
		msg := fmt.Sprintf("unknown command %q for %q", a, cmd.CommandPath())
		if cmd.SuggestionsMinimumDistance <= 0 {
			cmd.SuggestionsMinimumDistance = 2 // Cobra's own default, applied only at the root
		}
		if suggestions := cmd.SuggestionsFor(a); len(suggestions) > 0 {
			msg += "; did you mean " + strings.Join(suggestions, ", ") + "?"
		}
		return errors.New(msg)
	}
	return nil
}

// recoverCommandContext fills in what Cobra never got to set when it
// rejected the invocation before PersistentPreRunE ran (a wrong argument
// count, an unknown subcommand): the command path the error output names
// and hints with, and the output mode that selects the JSON envelope (from
// the raw arguments, P202_OUTPUT, the config default and agent detection).
// An agent still receives a structured, hinted error for the most common
// mistakes.
func recoverCommandContext(args []string) {
	if activeCommandPath == "" {
		if cmd, _, err := rootCmd.Find(args); err == nil && cmd != nil {
			activeCommandPath = cmd.CommandPath()
		} else {
			activeCommandPath = rootCmd.CommandPath()
		}
	}
	if jsonOutput || ndjsonOutput {
		return
	}
	// The error being reported matters more than a second one about flags.
	_ = resolveOutputMode(outputFlagsFromArgs(args))
}

// printError writes the failure to w. Under JSON output (--json, --ndjson,
// or JSON chosen for an agent) it is a single JSON envelope ({"error":
// {category, message, hint, exit_code, command, http_status,
// field_errors}}) so agents never parse prose; otherwise a
// human-readable "Error [category]: ..." line followed by a "Hint:" line
// when there is a recovery step to suggest.
func printError(w io.Writer, err error) {
	if jsonOutput || ndjsonOutput {
		// No HTML escaping: hints contain "<key>"-style placeholders that
		// agents should see verbatim.
		var buf bytes.Buffer
		enc := json.NewEncoder(&buf)
		enc.SetEscapeHTML(false)
		if mErr := enc.Encode(errorEnvelope(err)); mErr == nil {
			// Nothing to report a failed write to: this IS the error path.
			_, _ = w.Write(buf.Bytes())
			return
		}
	}
	if category := api.ErrorCategory(err); category != "" {
		fmt.Fprintf(w, "Error [%s]: %s\n", category, canonicalFlags(err.Error()))
	} else {
		fmt.Fprintln(w, "Error:", canonicalFlags(err.Error()))
	}
	if hint := hintFor(err); hint != "" {
		fmt.Fprintf(w, "Hint: %s\n", hint)
	}
}

func SetVersion(version string) {
	rootCmd.Version = version
}

// normalizeFlagName makes '-' and '_' interchangeable in every flag name, so
// --aff-campaign-id and --aff_campaign_id (and --sort-dir / --sort_dir) refer to
// the same flag. Names canonicalize to kebab-case (what help displays); the
// snake_case API-style spelling keeps working everywhere.
func normalizeFlagName(_ *pflag.FlagSet, name string) pflag.NormalizedName {
	return pflag.NormalizedName(strings.ReplaceAll(name, "_", "-"))
}

func init() {
	flagRoot = rootCmd
	rootCmd.SetGlobalNormalizationFunc(normalizeFlagName)
	rootCmd.PersistentFlags().BoolVar(&jsonOutput, "json", false, "Output pretty-printed JSON instead of tables")
	rootCmd.PersistentFlags().BoolVar(&tableOutput, "table", false, "Output tables even when an AI agent would get JSON")
	rootCmd.PersistentFlags().BoolVar(&csvOutput, "csv", false, "Output as CSV instead of tables")
	rootCmd.PersistentFlags().BoolVarP(&quietOutput, "quiet", "q", false, "Print only ids, one per line (for scripting)")
	rootCmd.PersistentFlags().BoolVar(&ndjsonOutput, "ndjson", false, "Output newline-delimited JSON (one row per line)")
	rootCmd.PersistentFlags().BoolVar(&wideOutput, "wide", false, "Show all columns at full width (no truncation)")
	rootCmd.PersistentFlags().BoolVar(&rawHeaders, "raw-headers", false, "Use raw API field names as table headers")
	rootCmd.PersistentFlags().StringVar(&fieldsFlag, "fields", "", "Comma-separated columns to show, in order")
	rootCmd.PersistentFlags().StringVar(&profileName, "profile", "", "Use a named configuration profile")
	rootCmd.PersistentFlags().StringVar(&groupName, "group", "", "Use a tag group of profiles for multi-profile commands")
	rootCmd.PersistentFlags().BoolVar(&stagedWrites, "staged", false, "Stage writes for approval instead of executing them (server records a change id; see `p202 change`)")
}

// resetAllFlags restores every flag in the command tree to its default value
// and clears its Changed state. Needed when the same command tree is executed
// more than once in a process (the interactive shell, tests), since Cobra
// retains parsed flag values between Execute() calls.
func resetAllFlags(cmd *cobra.Command) {
	resetFlagSet := func(fs *pflag.FlagSet) {
		fs.VisitAll(func(f *pflag.Flag) {
			// A repeatable flag (--cf, --field, --item) cannot be reset
			// through Set: Set appends once the flag has been parsed, and
			// even the first Set stores its default's text "[]" as a value.
			// The next command then read a value nobody gave it.
			if sv, ok := f.Value.(pflag.SliceValue); ok && f.DefValue == "[]" {
				_ = sv.Replace(nil)
			} else {
				_ = fs.Set(f.Name, f.DefValue)
			}
			f.Changed = false
		})
	}

	resetFlagSet(cmd.PersistentFlags())
	resetFlagSet(cmd.Flags())
	for _, c := range cmd.Commands() {
		resetAllFlags(c)
	}
}

// confirmAction asks a yes/no question and reads the answer from stdin.
// The prompt goes to stderr so it stays visible when stdout is captured
// (interactive shell) or piped, and never pollutes data output.
//
// A "no" (or a bare Enter) is (false, nil): the caller cancels and exits 0,
// because the person answered. Reaching the end of stdin before any answer
// is an error instead: nobody could answer — an agent, a script, a pipe —
// and exiting 0 with nothing done read as success to every one of them.
// The error names the flag that answers the question in advance.
func confirmAction(cmd *cobra.Command, format string, args ...interface{}) (bool, error) {
	question := fmt.Sprintf(format, args...)
	fmt.Fprintf(os.Stderr, "%s [y/N] ", question)
	var answer string
	// fmt.Fscanln, not a bufio.Reader: the interactive shell reads its own
	// commands from the same stdin, and a buffered reader would swallow them.
	if _, err := fmt.Fscanln(os.Stdin, &answer); errors.Is(err, io.EOF) {
		fmt.Fprintln(os.Stderr)
		return false, unansweredConfirmationError(cmd, question)
	}
	answer = strings.ToLower(strings.TrimSpace(answer))
	return answer == "y" || answer == "yes", nil
}

// unansweredConfirmationError is the failure confirmAction reports when stdin
// ended before an answer: nothing was done, and the hint names how to answer
// ahead of time on this command.
func unansweredConfirmationError(cmd *cobra.Command, question string) error {
	var ways []string
	if cmd != nil && cmd.Flags().Lookup("force") != nil {
		ways = append(ways, "re-run with --force to go ahead without the question")
	}
	if cmd != nil && cmd.Flags().Lookup("dry-run") != nil {
		ways = append(ways, "--dry-run shows what it would do first")
	}
	hint := "Run it from a terminal to answer the question."
	if len(ways) > 0 {
		hint = capitalize(strings.Join(ways, "; ")) + "."
	}
	return validationError("%q needs a yes, and stdin ended before an answer (no terminal attached?); nothing was done", question).
		WithHint(hint)
}
