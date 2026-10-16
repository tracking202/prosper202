//go:build js

package config

import (
	"errors"
	"os"
)

// hostedConfig is the only configuration a hosted (js/wasm) run has: the host
// hands each run the install's URL and key. There is no file and no other
// profile to fall back on, so a run without both refuses rather than guessing.
func hostedConfig() (*Config, bool, error) {
	url, key := os.Getenv("P202_HOSTED_URL"), os.Getenv("P202_HOSTED_API_KEY")
	if url == "" || key == "" {
		return nil, true, errors.New("hosted run: the host did not supply the install URL and key")
	}
	return &Config{
		ActiveProfile: "hosted",
		Profiles:      map[string]*Profile{"hosted": {URL: url, APIKey: key}},
	}, true, nil
}
