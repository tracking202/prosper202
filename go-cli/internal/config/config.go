package config

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"
)

type Config struct {
	URL      string            `json:"url"`
	APIKey   string            `json:"api_key"`
	Defaults map[string]string `json:"defaults,omitempty"`
}

func Dir() string {
	home, err := os.UserHomeDir()
	if err != nil {
		home = os.TempDir()
	}
	return filepath.Join(home, ".p202")
}

func Path() string {
	return filepath.Join(Dir(), "config.json")
}

func Load() (*Config, error) {
	data, err := os.ReadFile(Path())
	if err != nil {
		if os.IsNotExist(err) {
			return &Config{}, nil
		}
		return nil, fmt.Errorf("reading config: %w", err)
	}
	var c Config
	if err := json.Unmarshal(data, &c); err != nil {
		return nil, fmt.Errorf("parsing config: %w", err)
	}
	return &c, nil
}

func (c *Config) Save() error {
	dir := Dir()
	if err := os.MkdirAll(dir, 0700); err != nil {
		return fmt.Errorf("creating config dir: %w", err)
	}
	data, err := json.MarshalIndent(c, "", "  ")
	if err != nil {
		return fmt.Errorf("encoding config: %w", err)
	}
	data = append(data, '\n')
	if err := os.WriteFile(Path(), data, 0600); err != nil {
		return fmt.Errorf("writing config: %w", err)
	}
	return nil
}

func (c *Config) Validate() error {
	if c.URL == "" {
		return fmt.Errorf("no URL configured. Run: p202 config set-url <url>")
	}
	if c.APIKey == "" {
		return fmt.Errorf("no API key configured. Run: p202 config set-key <key>")
	}
	return nil
}

func (c *Config) MaskedKey() string {
	k := c.APIKey
	if k == "" {
		return "(not set)"
	}
	if len(k) <= 8 {
		return strings.Repeat("*", len(k))
	}
	return k[:4] + "..." + k[len(k)-4:]
}

func (c *Config) GetDefault(key string) string {
	if c.Defaults == nil {
		return ""
	}
	return c.Defaults[key]
}

func (c *Config) SetDefault(key, value string) {
	if c.Defaults == nil {
		c.Defaults = map[string]string{}
	}
	c.Defaults[key] = value
}

func (c *Config) DeleteDefault(key string) bool {
	if c.Defaults == nil {
		return false
	}
	if _, exists := c.Defaults[key]; !exists {
		return false
	}
	delete(c.Defaults, key)
	if len(c.Defaults) == 0 {
		c.Defaults = nil
	}
	return true
}
