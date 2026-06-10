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
		os.Exit(exitCodeForError(err))
	}
}

func SetVersion(version string) {
	rootCmd.Version = version
}

func init() {
	rootCmd.PersistentFlags().BoolVar(&jsonOutput, "json", false, "Output raw JSON instead of tables")
	rootCmd.PersistentFlags().BoolVar(&csvOutput, "csv", false, "Output as CSV instead of tables")
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
