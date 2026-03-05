package cmd

import (
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"strings"

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
	Long: "p202 is a command-line tool for managing a Prosper202 tracking instance.\n" +
		"Designed for both human operators and AI agents.",  // alias list appended dynamically in Execute()
	SilenceErrors: true,
	SilenceUsage:  true,
	PersistentPreRunE: func(cmd *cobra.Command, args []string) error {
		configpkg.SetActiveOverride(profileName)
		if jsonOutput && csvOutput {
			return fmt.Errorf("--json and --csv cannot be used together")
		}
		// Agent-mode implies --json
		if agentMode && !jsonOutput && !csvOutput {
			jsonOutput = true
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
		if jsonOutput || agentMode {
			fmt.Fprintln(os.Stdout, formatStructuredError(err))
		} else if category := api.ErrorCategory(err); category != "" {
			fmt.Fprintf(os.Stderr, "Error [%s]: %v\n", category, err)
		} else {
			fmt.Fprintln(os.Stderr, "Error:", err)
		}
		os.Exit(exitCodeForError(err))
	}
}

// formatStructuredError converts any error into a structured JSON error.
func formatStructuredError(err error) string {
	errObj := map[string]interface{}{
		"error": true,
	}

	code := "UNKNOWN_ERROR"
	category := "unknown"

	if cliErr, ok := err.(*CLIError); ok {
		category = cliErr.Category
		errObj["message"] = cliErr.Message
		code = strings.ToUpper(strings.ReplaceAll(category, " ", "_")) + "_ERROR"
	} else {
		errObj["message"] = err.Error()
	}

	// Check for API errors
	var apiErr *api.APIError
	if errors.As(err, &apiErr) {
		category = apiErr.CategoryName()
		code = strings.ToUpper(strings.ReplaceAll(category, " ", "_")) + "_ERROR"
		errObj["message"] = apiErr.Message
		if len(apiErr.FieldErrors) > 0 {
			errObj["field_errors"] = apiErr.FieldErrors
		}
		errObj["http_status"] = apiErr.Status
	}

	// Check for request errors (network, etc.)
	var reqErr *api.RequestError
	if errors.As(err, &reqErr) {
		category = reqErr.Kind
		code = strings.ToUpper(category) + "_ERROR"
	}

	errObj["code"] = code
	errObj["category"] = category
	errObj["exit_code"] = exitCodeForError(err)

	// Generate fix suggestions
	fix := suggestFix(err)
	if fix != "" {
		errObj["fix"] = fix
	}

	data, _ := json.MarshalIndent(errObj, "", "  ")
	return string(data)
}

func SetVersion(version string) {
	rootCmd.Version = version
}

func init() {
	rootCmd.PersistentFlags().BoolVar(&jsonOutput, "json", false, "Output raw JSON instead of tables")
	rootCmd.PersistentFlags().BoolVar(&csvOutput, "csv", false, "Output as CSV instead of tables")
	rootCmd.PersistentFlags().StringVar(&profileName, "profile", "", "Use a named configuration profile")
	rootCmd.PersistentFlags().StringVar(&groupName, "group", "", "Use a tag group of profiles for multi-profile commands")
	rootCmd.PersistentFlags().BoolVar(&agentMode, "agent-mode", false, "Restrict mutations, redact secrets, enable audit logging (for AI agent use)")
	rootCmd.PersistentFlags().StringVar(&fieldsFilter, "fields", "", "Comma-separated list of fields to include in output")
	rootCmd.PersistentFlags().IntVar(&maxFieldLength, "max-field-length", 0, "Truncate string fields longer than this (0 = no limit)")
	rootCmd.PersistentFlags().BoolVar(&idOnly, "id-only", false, "Output only the primary key value (for command chaining)")
	rootCmd.PersistentFlags().BoolVar(&dryRun, "dry-run", false, "Preview mutations without executing them")
}
