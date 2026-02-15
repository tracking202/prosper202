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
		p, resolvedName, err := cfg.EnsureProfile(profileName)
		if err != nil {
			return err
		}
		p.URL = u
		if err := cfg.Save(); err != nil {
			return err
		}
		fmt.Printf("URL set for profile %s: %s\n", resolvedName, p.URL)
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
		p, resolvedName, err := cfg.EnsureProfile(profileName)
		if err != nil {
			return err
		}
		p.APIKey = apiKey
		if err := cfg.Save(); err != nil {
			return err
		}
		fmt.Printf("API key set for profile %s (%s)\n", resolvedName, p.MaskedKey())
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
		p, resolvedName, err := cfg.ResolveProfileWithName(profileName)
		if err != nil {
			return err
		}

		profiles := cfg.ProfileNames()
		profilesStr := "(none)"
		if len(profiles) > 0 {
			profilesStr = strings.Join(profiles, ", ")
		}

		if jsonOutput {
			obj := map[string]interface{}{
				"profile":         resolvedName,
				"active_profile":  cfg.ActiveProfile,
				"url":             p.URL,
				"api_key":         p.MaskedKey(),
				"config_path":     config.Path(),
				"available_names": profiles,
			}
			data, _ := json.Marshal(obj)
			output.Render(data, true)
		} else {
			fmt.Printf("Config file: %s\n", config.Path())
			fmt.Printf("Active:      %s\n", cfg.ActiveProfile)
			fmt.Printf("Profile:     %s\n", resolvedName)
			fmt.Printf("URL:         %s\n", p.URL)
			fmt.Printf("API key:     %s\n", p.MaskedKey())
			fmt.Printf("Profiles:    %s\n", profilesStr)
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

var configSetDefaultCmd = &cobra.Command{
	Use:   "set-default <key> <value>",
	Short: "Set a default value for supported command flags",
	Args:  cobra.ExactArgs(2),
	RunE: func(cmd *cobra.Command, args []string) error {
		key := strings.TrimSpace(args[0])
		value := strings.TrimSpace(args[1])
		if value == "" {
			return fmt.Errorf("default value cannot be empty")
		}
		if !isSupportedDefaultKey(key) {
			return fmt.Errorf("unsupported default key %q. Supported keys: %s", key, strings.Join(supportedDefaultKeys(), ", "))
		}

		cfg, err := config.Load()
		if err != nil {
			return err
		}
		p, _, err := cfg.EnsureProfile(profileName)
		if err != nil {
			return err
		}
		p.SetDefault(key, value)
		if err := cfg.Save(); err != nil {
			return err
		}
		output.Success("Default set: %s=%s", key, value)
		return nil
	},
}

var configGetDefaultCmd = &cobra.Command{
	Use:   "get-default [key]",
	Short: "Get one default value or list all defaults",
	Args:  cobra.MaximumNArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		cfg, err := config.Load()
		if err != nil {
			return err
		}
		p, _, err := cfg.ResolveProfileWithName(profileName)
		if err != nil {
			return err
		}

		if len(args) == 1 {
			key := strings.TrimSpace(args[0])
			if !isSupportedDefaultKey(key) {
				return fmt.Errorf("unsupported default key %q. Supported keys: %s", key, strings.Join(supportedDefaultKeys(), ", "))
			}
			value := p.GetDefault(key)
			if value == "" {
				return fmt.Errorf("default %q is not set", key)
			}
			payload, _ := json.Marshal(map[string]interface{}{
				"data": map[string]string{
					"key":   key,
					"value": value,
				},
			})
			render(payload)
			return nil
		}

		rows := make([]map[string]string, 0, len(p.Defaults))
		for _, key := range supportedDefaultKeys() {
			if val := p.GetDefault(key); val != "" {
				rows = append(rows, map[string]string{"key": key, "value": val})
			}
		}
		payload, _ := json.Marshal(map[string]interface{}{"data": rows})
		render(payload)
		return nil
	},
}

var configUnsetDefaultCmd = &cobra.Command{
	Use:   "unset-default <key>",
	Short: "Remove a configured default value",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		key := strings.TrimSpace(args[0])
		if !isSupportedDefaultKey(key) {
			return fmt.Errorf("unsupported default key %q. Supported keys: %s", key, strings.Join(supportedDefaultKeys(), ", "))
		}

		cfg, err := config.Load()
		if err != nil {
			return err
		}
		p, _, err := cfg.ResolveProfileWithName(profileName)
		if err != nil {
			return err
		}
		if !p.DeleteDefault(key) {
			return fmt.Errorf("default %q is not set", key)
		}
		if err := cfg.Save(); err != nil {
			return err
		}
		output.Success("Default removed: %s", key)
		return nil
	},
}

func init() {
	configCmd.AddCommand(configSetURLCmd, configSetKeyCmd, configShowCmd, configTestCmd)
	configCmd.AddCommand(configSetDefaultCmd, configGetDefaultCmd, configUnsetDefaultCmd)
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
