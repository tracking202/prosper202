package cmd

import (
	"fmt"
	"sort"
	"strings"
	"unicode"

	configpkg "p202/internal/config"
	"p202/internal/output"

	"github.com/spf13/cobra"
)

var configTagProfileCmd = &cobra.Command{
	Use:   "tag-profile <name> <tag> [<tag>...]",
	Short: "Add tags to a profile",
	Args:  cobra.MinimumNArgs(2),
	RunE: func(cmd *cobra.Command, args []string) error {
		name, err := normalizeProfileName(args[0])
		if err != nil {
			return err
		}

		cfg, err := configpkg.Load()
		if err != nil {
			return err
		}
		profile, exists := cfg.Profiles[name]
		if !exists || profile == nil {
			return fmt.Errorf("profile %q not found. available profiles: %s", name, strings.Join(cfg.ProfileNames(), ", "))
		}

		tagSet := map[string]bool{}
		for _, tag := range profile.Tags {
			tagSet[strings.ToLower(strings.TrimSpace(tag))] = true
		}
		for _, rawTag := range args[1:] {
			tag, err := normalizeTag(rawTag)
			if err != nil {
				return err
			}
			tagSet[tag] = true
		}

		profile.Tags = make([]string, 0, len(tagSet))
		for tag := range tagSet {
			profile.Tags = append(profile.Tags, tag)
		}
		sort.Strings(profile.Tags)

		if err := cfg.Save(); err != nil {
			return err
		}
		output.Success("Tags updated for profile %s.", name)
		return nil
	},
}

var configUntagProfileCmd = &cobra.Command{
	Use:   "untag-profile <name> <tag> [<tag>...]",
	Short: "Remove tags from a profile",
	Args:  cobra.MinimumNArgs(2),
	RunE: func(cmd *cobra.Command, args []string) error {
		name, err := normalizeProfileName(args[0])
		if err != nil {
			return err
		}

		cfg, err := configpkg.Load()
		if err != nil {
			return err
		}
		profile, exists := cfg.Profiles[name]
		if !exists || profile == nil {
			return fmt.Errorf("profile %q not found. available profiles: %s", name, strings.Join(cfg.ProfileNames(), ", "))
		}

		removeSet := map[string]bool{}
		for _, rawTag := range args[1:] {
			tag, err := normalizeTag(rawTag)
			if err != nil {
				return err
			}
			removeSet[tag] = true
		}

		remaining := make([]string, 0, len(profile.Tags))
		for _, tag := range profile.Tags {
			normalized := strings.ToLower(strings.TrimSpace(tag))
			if removeSet[normalized] {
				continue
			}
			remaining = append(remaining, normalized)
		}
		sort.Strings(remaining)
		profile.Tags = remaining

		if err := cfg.Save(); err != nil {
			return err
		}
		output.Success("Tags updated for profile %s.", name)
		return nil
	},
}

func normalizeTag(raw string) (string, error) {
	tag := strings.ToLower(strings.TrimSpace(raw))
	if tag == "" {
		return "", fmt.Errorf("tag cannot be empty")
	}
	for _, ch := range tag {
		if unicode.IsLetter(ch) || unicode.IsDigit(ch) {
			continue
		}
		switch ch {
		case ':', '-', '_', '.':
			continue
		default:
			return "", fmt.Errorf("invalid tag %q: only letters, digits, and : - _ . are allowed", raw)
		}
	}
	return tag, nil
}

func init() {
	configCmd.AddCommand(configTagProfileCmd, configUntagProfileCmd)
}
