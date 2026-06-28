package cmd

import (
	"fmt"
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

var rootCmd = &cobra.Command{
	Use:           "p202",
	Short:         "Prosper202 CLI",
	Long: "p202 is a command-line tool for managing a Prosper202 tracking instance.\n" +
		"Designed for both human operators and AI agents.",  // alias list appended dynamically in Execute()
	SilenceErrors: true,
	SilenceUsage:  true,
	PersistentPreRunE: func(cmd *cobra.Command, args []string) error {
		configpkg.SetActiveOverride(profileName)
		if jsonOutput && csvOutput {
			return fmt.Errorf("--json and --csv cannot be used together")
		}
		return nil
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

func Execute() {
	rootCmd.Long = "p202 is a command-line tool for managing a Prosper202 tracking instance.\n" +
		"Designed for both human operators and AI agents." + buildAliasHelp()
	if err := rootCmd.Execute(); err != nil {
		if category := api.ErrorCategory(err); category != "" {
			fmt.Fprintf(os.Stderr, "Error [%s]: %v\n", category, err)
		} else {
			fmt.Fprintln(os.Stderr, "Error:", err)
		}
		if hint := api.HintFor(err); hint != "" {
			fmt.Fprintf(os.Stderr, "Hint: %s\n", hint)
		}
		os.Exit(exitCodeForError(err))
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
	rootCmd.SetGlobalNormalizationFunc(normalizeFlagName)
	rootCmd.PersistentFlags().BoolVar(&jsonOutput, "json", false, "Output raw JSON instead of tables")
	rootCmd.PersistentFlags().BoolVar(&csvOutput, "csv", false, "Output as CSV instead of tables")
	rootCmd.PersistentFlags().BoolVarP(&quietOutput, "quiet", "q", false, "Print only ids, one per line (for scripting)")
	rootCmd.PersistentFlags().BoolVar(&ndjsonOutput, "ndjson", false, "Output newline-delimited JSON (one row per line)")
	rootCmd.PersistentFlags().BoolVar(&wideOutput, "wide", false, "Show all columns at full width (no truncation)")
	rootCmd.PersistentFlags().BoolVar(&rawHeaders, "raw-headers", false, "Use raw API field names as table headers")
	rootCmd.PersistentFlags().StringVar(&fieldsFlag, "fields", "", "Comma-separated columns to show, in order")
	rootCmd.PersistentFlags().StringVar(&profileName, "profile", "", "Use a named configuration profile")
	rootCmd.PersistentFlags().StringVar(&groupName, "group", "", "Use a tag group of profiles for multi-profile commands")
}

// resetAllFlags restores every flag in the command tree to its default value
// and clears its Changed state. Needed when the same command tree is executed
// more than once in a process (the interactive shell, tests), since Cobra
// retains parsed flag values between Execute() calls.
func resetAllFlags(cmd *cobra.Command) {
	resetFlagSet := func(fs *pflag.FlagSet) {
		fs.VisitAll(func(f *pflag.Flag) {
			_ = fs.Set(f.Name, f.DefValue)
			f.Changed = false
		})
	}

	resetFlagSet(cmd.PersistentFlags())
	resetFlagSet(cmd.Flags())
	for _, c := range cmd.Commands() {
		resetAllFlags(c)
	}
}

// confirmPrompt asks a yes/no question and reads the answer from stdin.
// The prompt goes to stderr so it stays visible when stdout is captured
// (interactive shell) or piped, and never pollutes data output.
func confirmPrompt(format string, args ...interface{}) bool {
	fmt.Fprintf(os.Stderr, format+" [y/N] ", args...)
	var answer string
	_, _ = fmt.Scanln(&answer)
	answer = strings.ToLower(strings.TrimSpace(answer))
	return answer == "y" || answer == "yes"
}
