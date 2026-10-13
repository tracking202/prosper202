package cmd

import (
	"bufio"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/url"
	"os"
	"strings"

	"p202/internal/api"
	"p202/internal/config"
	"p202/internal/output"

	"github.com/spf13/cobra"
	"golang.org/x/term"
)

var configCmd = &cobra.Command{
	Use:   "config",
	Short: "Manage CLI configuration",
}

var configSetURLCmd = &cobra.Command{
	Use:   "set-url <url>",
	Short: "Set the Prosper202 instance URL the API is reached at (the install's address)",
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
	Use:   "set-key [api-key]",
	Short: "Set the API key (omit the argument to be prompted without echoing)",
	Args:  cobra.MaximumNArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		cfg, err := config.Load()
		if err != nil {
			return err
		}
		var apiKey string
		if len(args) == 1 {
			apiKey = strings.TrimSpace(args[0])
		} else {
			// An API key is a bearer credential — at least as sensitive as the
			// password that `user create` already reads with term.ReadPassword.
			// Prompting keeps it out of shell history and ps output.
			//
			// term.ReadPassword needs a real terminal, so when stdin is piped
			// (echo "$KEY" | p202 config set-key, or CI) fall back to a plain
			// read instead of failing with "inappropriate ioctl for device".
			if term.IsTerminal(int(os.Stdin.Fd())) {
				fmt.Fprint(os.Stderr, "API key (hidden): ")
				keyBytes, err := term.ReadPassword(int(os.Stdin.Fd()))
				fmt.Fprintln(os.Stderr)
				if err != nil {
					return fmt.Errorf("reading API key: %w", err)
				}
				apiKey = strings.TrimSpace(string(keyBytes))
			} else {
				line, err := bufio.NewReader(os.Stdin).ReadString('\n')
				if err != nil && !errors.Is(err, io.EOF) {
					return fmt.Errorf("reading API key: %w", err)
				}
				apiKey = strings.TrimSpace(line)
			}
		}
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
				"output_format":   outputFormatName(),
				"output_source":   outputSource,
			}
			data, err := json.Marshal(obj)
			if err != nil {
				return fmt.Errorf("encoding output: %w", err)
			}
			output.RenderWith(data, output.Opts{JSON: true, Compact: compactJSON})
		} else {
			fmt.Printf("Config file: %s\n", config.Path())
			fmt.Printf("Active:      %s\n", cfg.ActiveProfile)
			fmt.Printf("Profile:     %s\n", resolvedName)
			fmt.Printf("URL:         %s\n", p.URL)
			fmt.Printf("API key:     %s\n", p.MaskedKey())
			fmt.Printf("Profiles:    %s\n", profilesStr)
			fmt.Printf("Output:      %s (%s)\n", outputFormatName(), outputSource)
		}
		return nil
	},
}

var configTestCmd = &cobra.Command{
	Use:   "test",
	Short: "Test the connection to the Prosper202 instance and that the API key works",
	Long: "Checks that the instance answers (GET /system/health) and then that it accepts the\n" +
		"configured key, and says which user the key acts as (`p202 whoami` shows more).\n" +
		"The health check alone is answered before authentication, so it passed with any key.",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, err := api.NewFromConfig()
		if err != nil {
			return err
		}
		data, err := c.Get("system/health", nil)
		if err != nil {
			return withHint(fmt.Errorf("connection failed: %w", err), "Check `p202 config show` (URL and key), that the instance is reachable, and that the key is valid in the Prosper202 UI under API keys.")
		}
		p, err := fetchPrincipal(c)
		if err != nil && !errors.Is(err, errNoPrincipal) {
			return withHint(fmt.Errorf("the instance answers, but the key was not accepted: %w", err),
				"Set a valid key with `p202 config set-key` (Account > API keys in the UI, or `p202 user apikey create` from a working key).")
		}
		var health map[string]interface{}
		if json.Unmarshal(data, &health) != nil {
			health = map[string]interface{}{}
		}
		if d, ok := health["data"].(map[string]interface{}); ok {
			health = d
		}
		if p.UserID > 0 {
			health["user_id"] = p.UserID
			health["roles"] = p.Roles
			health["scopes"] = p.Scopes
		}
		encoded, err := json.Marshal(map[string]interface{}{"data": health})
		if err != nil {
			return fmt.Errorf("encoding the result: %w", err)
		}
		if !jsonOutput {
			if p.UserID > 0 {
				fmt.Printf("Connection successful: the key acts as user %d.\n", p.UserID)
			} else {
				fmt.Println("Connection successful: the key is accepted (this server does not say which user it belongs to).")
			}
		}
		render(encoded)
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
			return validationError("default value cannot be empty")
		}
		if !isSupportedDefaultKey(key) {
			return validationError("unsupported default key %q. Supported keys: %s", key, strings.Join(supportedDefaultKeys(), ", "))
		}
		if key == outputDefaultKey {
			format, ok := parseOutputFormat(value)
			if !ok {
				return validationError("%s must be one of: %s (got %q)", outputDefaultKey, strings.Join(outputFormats, ", "), value)
			}
			value = format
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
				return validationError("unsupported default key %q. Supported keys: %s", key, strings.Join(supportedDefaultKeys(), ", "))
			}
			value := p.GetDefault(key)
			if value == "" {
				return validationError("default %q is not set", key).WithHint("Set it with `p202 config set-default %s <value>`.", key)
			}
			payload, err := json.Marshal(map[string]interface{}{
				"data": map[string]string{
					"key":   key,
					"value": value,
				},
			})
			if err != nil {
				return fmt.Errorf("encoding output: %w", err)
			}
			render(payload)
			return nil
		}

		rows := make([]map[string]string, 0, len(p.Defaults))
		for _, key := range supportedDefaultKeys() {
			if val := p.GetDefault(key); val != "" {
				rows = append(rows, map[string]string{"key": key, "value": val})
			}
		}
		payload, err := json.Marshal(map[string]interface{}{"data": rows})
		if err != nil {
			return fmt.Errorf("encoding output: %w", err)
		}
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
			return validationError("unsupported default key %q. Supported keys: %s", key, strings.Join(supportedDefaultKeys(), ", "))
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
			return validationError("default %q is not set", key).WithHint("Set it with `p202 config set-default %s <value>`.", key)
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
		return "", validationError("URL is required").WithHint("Example: `p202 config set-url https://tracking.example.com`.")
	}
	parsed, err := url.Parse(trimmed)
	if err != nil || parsed == nil {
		return "", validationError("URL must be a valid http(s) URL").WithHint("Example: `p202 config set-url https://tracking.example.com`.")
	}
	if parsed.Scheme != "http" && parsed.Scheme != "https" {
		return "", validationError("URL must use http or https").WithHint("Example: `p202 config set-url https://tracking.example.com`.")
	}
	if parsed.Host == "" {
		return "", validationError("URL must include a host").WithHint("Example: `p202 config set-url https://tracking.example.com`.")
	}
	return strings.TrimRight(trimmed, "/"), nil
}

func validateAPIKey(key string) error {
	if len(key) < 8 {
		return validationError("API key must be at least 8 characters").WithHint("Create or copy a key in the Prosper202 UI (API keys), then `p202 config set-key <key>`.")
	}
	if strings.ContainsAny(key, " \t\r\n") {
		return validationError("API key must not contain whitespace").WithHint("Paste the key exactly as shown in the Prosper202 UI; quote it if your shell splits it.")
	}
	return nil
}
