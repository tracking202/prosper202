package cmd

import (
	"fmt"
	"os"

	"p202/internal/api"
	configpkg "p202/internal/config"

	"github.com/spf13/cobra"
)

var jsonOutput bool
var csvOutput bool
var profileName string
var groupName string

var rootCmd = &cobra.Command{
	Use:           "p202",
	Short:         "Prosper202 CLI",
	Long:          "p202 is a command-line tool for managing a Prosper202 tracking instance.\nDesigned for both human operators and AI agents.",
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

func Execute() {
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
