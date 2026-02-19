package cmd

import (
	"os"
	"strings"
)

func envFlagEnabled(name string, defaultValue bool) bool {
	raw, exists := os.LookupEnv(name)
	if !exists {
		return defaultValue
	}

	value := strings.TrimSpace(strings.ToLower(raw))
	switch value {
	case "", "1", "true", "yes", "on", "enabled":
		return true
	case "0", "false", "no", "off", "disabled":
		return false
	default:
		return defaultValue
	}
}
