package cmd

import (
	"encoding/json"
	"fmt"
	"sort"
	"strings"

	configpkg "p202/internal/config"
	"p202/internal/output"

	"github.com/spf13/cobra"
)

var configAddProfileCmd = &cobra.Command{
	Use:   "add-profile <name>",
	Short: "Create a named profile",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		name, err := normalizeProfileName(args[0])
		if err != nil {
			return err
		}

		urlVal, _ := cmd.Flags().GetString("url")
		keyVal, _ := cmd.Flags().GetString("key")
		urlVal, err = normalizeBaseURL(urlVal)
		if err != nil {
			return err
		}
		keyVal = strings.TrimSpace(keyVal)
		if err := validateAPIKey(keyVal); err != nil {
			return err
		}

		cfg, err := configpkg.Load()
		if err != nil {
			return err
		}

		if len(cfg.Profiles) == 0 {
			cfg.Profiles = map[string]*configpkg.Profile{}
		}
		if _, exists := cfg.Profiles[name]; exists {
			return fmt.Errorf("profile %q already exists", name)
		}

		cfg.Profiles[name] = &configpkg.Profile{
			URL:    urlVal,
			APIKey: keyVal,
		}
		if strings.TrimSpace(cfg.ActiveProfile) == "" {
			cfg.ActiveProfile = name
		}

		if err := cfg.Save(); err != nil {
			return err
		}
		output.Success("Profile %s added.", name)
		return nil
	},
}

var configRemoveProfileCmd = &cobra.Command{
	Use:   "remove-profile <name>",
	Short: "Remove a named profile",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		name, err := normalizeProfileName(args[0])
		if err != nil {
			return err
		}

		cfg, err := configpkg.Load()
		if err != nil {
			return err
		}
		if len(cfg.Profiles) == 0 {
			return fmt.Errorf("no profiles configured")
		}
		if _, exists := cfg.Profiles[name]; !exists {
			return fmt.Errorf("profile %q not found. available profiles: %s", name, strings.Join(cfg.ProfileNames(), ", "))
		}
		if strings.TrimSpace(cfg.ActiveProfile) == name {
			return fmt.Errorf("cannot remove active profile %q; run `p202 config use <name>` first", name)
		}

		force, _ := cmd.Flags().GetBool("force")
		if !force {
			fmt.Printf("Remove profile %s? [y/N] ", name)
			var answer string
			fmt.Scanln(&answer)
			answer = strings.ToLower(strings.TrimSpace(answer))
			if answer != "y" && answer != "yes" {
				fmt.Println("Cancelled.")
				return nil
			}
		}

		delete(cfg.Profiles, name)
		if err := cfg.Save(); err != nil {
			return err
		}

		output.Success("Profile %s removed.", name)
		return nil
	},
}

var configUseProfileCmd = &cobra.Command{
	Use:   "use <name>",
	Short: "Set the active profile",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		name, err := normalizeProfileName(args[0])
		if err != nil {
			return err
		}

		cfg, err := configpkg.Load()
		if err != nil {
			return err
		}
		if len(cfg.Profiles) == 0 {
			return fmt.Errorf("no profiles configured")
		}
		if _, exists := cfg.Profiles[name]; !exists {
			return fmt.Errorf("profile %q not found. available profiles: %s", name, strings.Join(cfg.ProfileNames(), ", "))
		}

		cfg.ActiveProfile = name
		if err := cfg.Save(); err != nil {
			return err
		}
		output.Success("Active profile set to %s.", name)
		return nil
	},
}

var configListProfilesCmd = &cobra.Command{
	Use:   "list-profiles",
	Short: "List all configured profiles",
	RunE: func(cmd *cobra.Command, args []string) error {
		cfg, err := configpkg.Load()
		if err != nil {
			return err
		}

		filterTag, _ := cmd.Flags().GetString("tag")
		filterTag = strings.ToLower(strings.TrimSpace(filterTag))

		rows := make([]map[string]interface{}, 0, len(cfg.Profiles))
		names := cfg.ProfileNames()
		for _, name := range names {
			p := cfg.Profiles[name]
			if p == nil {
				continue
			}
			if filterTag != "" && !profileHasTag(p, filterTag) {
				continue
			}
			row := map[string]interface{}{
				"name":    name,
				"url":     p.URL,
				"api_key": p.MaskedKey(),
				"active":  name == cfg.ActiveProfile,
				"tags":    strings.Join(sortedCopy(p.Tags), ","),
			}
			rows = append(rows, row)
		}

		payload, _ := json.Marshal(map[string]interface{}{
			"active_profile": cfg.ActiveProfile,
			"data":           rows,
		})
		render(payload)
		return nil
	},
}

var configRenameProfileCmd = &cobra.Command{
	Use:   "rename-profile <old> <new>",
	Short: "Rename a profile",
	Args:  cobra.ExactArgs(2),
	RunE: func(cmd *cobra.Command, args []string) error {
		oldName, err := normalizeProfileName(args[0])
		if err != nil {
			return err
		}
		newName, err := normalizeProfileName(args[1])
		if err != nil {
			return err
		}
		if oldName == newName {
			return fmt.Errorf("old and new profile names are the same")
		}

		cfg, err := configpkg.Load()
		if err != nil {
			return err
		}
		if len(cfg.Profiles) == 0 {
			return fmt.Errorf("no profiles configured")
		}
		p, exists := cfg.Profiles[oldName]
		if !exists {
			return fmt.Errorf("profile %q not found. available profiles: %s", oldName, strings.Join(cfg.ProfileNames(), ", "))
		}
		if _, exists := cfg.Profiles[newName]; exists {
			return fmt.Errorf("profile %q already exists", newName)
		}

		cfg.Profiles[newName] = p
		delete(cfg.Profiles, oldName)
		if cfg.ActiveProfile == oldName {
			cfg.ActiveProfile = newName
		}
		if err := cfg.Save(); err != nil {
			return err
		}

		output.Success("Profile %s renamed to %s.", oldName, newName)
		return nil
	},
}

func normalizeProfileName(raw string) (string, error) {
	name := strings.TrimSpace(raw)
	if name == "" {
		return "", fmt.Errorf("profile name is required")
	}
	if strings.ContainsAny(name, " \t\r\n,") {
		return "", fmt.Errorf("profile name must not contain whitespace or commas")
	}
	return name, nil
}

func profileHasTag(p *configpkg.Profile, tag string) bool {
	for _, t := range p.Tags {
		if strings.ToLower(strings.TrimSpace(t)) == tag {
			return true
		}
	}
	return false
}

func sortedCopy(items []string) []string {
	out := append([]string(nil), items...)
	sort.Strings(out)
	return out
}

func init() {
	configAddProfileCmd.Flags().String("url", "", "Profile base URL (required)")
	configAddProfileCmd.Flags().String("key", "", "Profile API key (required)")
	_ = configAddProfileCmd.MarkFlagRequired("url")
	_ = configAddProfileCmd.MarkFlagRequired("key")

	configRemoveProfileCmd.Flags().BoolP("force", "f", false, "Skip confirmation prompt")
	configListProfilesCmd.Flags().String("tag", "", "Filter profiles by tag")

	configCmd.AddCommand(
		configAddProfileCmd,
		configRemoveProfileCmd,
		configUseProfileCmd,
		configListProfilesCmd,
		configRenameProfileCmd,
	)
}
