package cmd

import (
	"fmt"
	"strings"

	"p202/internal/api"
	"p202/internal/config"
	"p202/internal/output"

	"github.com/spf13/cobra"
)

var configCmd = &cobra.Command{
	Use:   "config",
	Short: "Manage CLI configuration",
}

var configSetURLCmd = &cobra.Command{
	Use:   "set-url <url>",
	Short: "Set the Prosper202 instance URL",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		cfg, err := config.Load()
		if err != nil {
			return err
		}
		cfg.URL = strings.TrimRight(args[0], "/")
		if err := cfg.Save(); err != nil {
			return err
		}
		fmt.Printf("URL set to: %s\n", cfg.URL)
		return nil
	},
}

var configSetKeyCmd = &cobra.Command{
	Use:   "set-key <api-key>",
	Short: "Set the API key",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		cfg, err := config.Load()
		if err != nil {
			return err
		}
		cfg.APIKey = args[0]
		if err := cfg.Save(); err != nil {
			return err
		}
		fmt.Printf("API key set (%s)\n", cfg.MaskedKey())
		return nil
	},
}

var configShowCmd = &cobra.Command{
	Use:   "show",
	Short: "Show current configuration",
	RunE: func(cmd *cobra.Command, args []string) error {
		cfg, err := config.Load()
		if err != nil {
			return err
		}
		if jsonOutput {
			data := fmt.Sprintf(`{"url": %q, "api_key": %q, "config_path": %q}`,
				cfg.URL, cfg.MaskedKey(), config.Path())
			output.Render([]byte(data), true)
		} else {
			fmt.Printf("Config file: %s\n", config.Path())
			fmt.Printf("URL:         %s\n", cfg.URL)
			fmt.Printf("API key:     %s\n", cfg.MaskedKey())
		}
		return nil
	},
}

var configTestCmd = &cobra.Command{
	Use:   "test",
	Short: "Test connection to the Prosper202 instance",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("system/health", nil)
		if err != nil {
			return fmt.Errorf("connection failed: %w", err)
		}
		fmt.Println("Connection successful!")
		output.Render(data, jsonOutput)
		return nil
	},
}

func init() {
	configCmd.AddCommand(configSetURLCmd, configSetKeyCmd, configShowCmd, configTestCmd)
	rootCmd.AddCommand(configCmd)
}
