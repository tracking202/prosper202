package cmd

import (
	"encoding/json"
	"fmt"
	"net/url"
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
		u, err := normalizeBaseURL(args[0])
		if err != nil {
			return err
		}
		cfg.URL = u
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
		apiKey := strings.TrimSpace(args[0])
		if err := validateAPIKey(apiKey); err != nil {
			return err
		}
		cfg.APIKey = apiKey
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
			obj := map[string]string{
				"url":         cfg.URL,
				"api_key":     cfg.MaskedKey(),
				"config_path": config.Path(),
			}
			data, _ := json.Marshal(obj)
			output.Render(data, true)
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
		if !jsonOutput {
			fmt.Println("Connection successful!")
		}
		render(data)
		return nil
	},
}

func init() {
	configCmd.AddCommand(configSetURLCmd, configSetKeyCmd, configShowCmd, configTestCmd)
	rootCmd.AddCommand(configCmd)
}

func normalizeBaseURL(raw string) (string, error) {
	trimmed := strings.TrimSpace(raw)
	if trimmed == "" {
		return "", fmt.Errorf("URL is required")
	}
	parsed, err := url.Parse(trimmed)
	if err != nil || parsed == nil {
		return "", fmt.Errorf("URL must be a valid http(s) URL")
	}
	if parsed.Scheme != "http" && parsed.Scheme != "https" {
		return "", fmt.Errorf("URL must use http or https")
	}
	if parsed.Host == "" {
		return "", fmt.Errorf("URL must include a host")
	}
	return strings.TrimRight(trimmed, "/"), nil
}

func validateAPIKey(key string) error {
	if len(key) < 8 {
		return fmt.Errorf("API key must be at least 8 characters")
	}
	if strings.ContainsAny(key, " \t\r\n") {
		return fmt.Errorf("API key must not contain whitespace")
	}
	return nil
}
