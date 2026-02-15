package config

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"sync"
)

const defaultProfileName = "default"

var (
	overrideMu      sync.RWMutex
	profileOverride string
)

type Profile struct {
	URL      string            `json:"url"`
	APIKey   string            `json:"api_key"`
	Defaults map[string]string `json:"defaults,omitempty"`
	Tags     []string          `json:"tags,omitempty"`
}

type Config struct {
	// Legacy V1 fields (read for migration; omitted on V2 writes).
	URL      string            `json:"url,omitempty"`
	APIKey   string            `json:"api_key,omitempty"`
	Defaults map[string]string `json:"defaults,omitempty"`

	ActiveProfile string              `json:"active_profile,omitempty"`
	Profiles      map[string]*Profile `json:"profiles,omitempty"`
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
	c.migrateLegacy()
	return &c, nil
}

func (c *Config) Save() error {
	c.migrateLegacy()
	c.mergeLegacyIntoProfile()

	dir := Dir()
	if err := os.MkdirAll(dir, 0700); err != nil {
		return fmt.Errorf("creating config dir: %w", err)
	}

	payload := c.cloneForSave()
	data, err := json.MarshalIndent(payload, "", "  ")
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
	p, _, err := c.resolveProfile(getProfileOverride())
	if err != nil {
		return err
	}
	return p.Validate()
}

func (p *Profile) Validate() error {
	if p.URL == "" {
		return fmt.Errorf("no URL configured. Run: p202 config set-url <url>")
	}
	if p.APIKey == "" {
		return fmt.Errorf("no API key configured. Run: p202 config set-key <key>")
	}
	return nil
}

func (c *Config) MaskedKey() string {
	p, _, err := c.resolveProfile(getProfileOverride())
	if err != nil {
		return "(not set)"
	}
	return p.MaskedKey()
}

func (p *Profile) MaskedKey() string {
	k := p.APIKey
	if k == "" {
		return "(not set)"
	}
	if len(k) <= 8 {
		return strings.Repeat("*", len(k))
	}
	return k[:4] + "..." + k[len(k)-4:]
}

func (p *Profile) GetDefault(key string) string {
	if p.Defaults == nil {
		return ""
	}
	return p.Defaults[key]
}

func (p *Profile) SetDefault(key, value string) {
	if p.Defaults == nil {
		p.Defaults = map[string]string{}
	}
	p.Defaults[key] = value
}

func (p *Profile) DeleteDefault(key string) bool {
	if p.Defaults == nil {
		return false
	}
	if _, exists := p.Defaults[key]; !exists {
		return false
	}
	delete(p.Defaults, key)
	if len(p.Defaults) == 0 {
		p.Defaults = nil
	}
	return true
}

func (c *Config) GetDefault(key string) string {
	p, _, err := c.resolveProfile(getProfileOverride())
	if err != nil {
		return ""
	}
	return p.GetDefault(key)
}

func (c *Config) SetDefault(key, value string) {
	p, _ := c.ensureWritableProfile()
	p.SetDefault(key, value)
}

func (c *Config) DeleteDefault(key string) bool {
	p, _ := c.ensureWritableProfile()
	return p.DeleteDefault(key)
}

func (c *Config) ResolveProfile(name string) (*Profile, error) {
	p, _, err := c.resolveProfile(name)
	return p, err
}

func (c *Config) ResolveGroup(tag string) []string {
	normalized := strings.ToLower(strings.TrimSpace(tag))
	if normalized == "" || len(c.Profiles) == 0 {
		return nil
	}
	out := make([]string, 0)
	for name, p := range c.Profiles {
		for _, t := range p.Tags {
			if strings.ToLower(strings.TrimSpace(t)) == normalized {
				out = append(out, name)
				break
			}
		}
	}
	sort.Strings(out)
	return out
}

func LoadProfile() (*Profile, error) {
	p, _, err := LoadProfileWithName("")
	return p, err
}

func LoadProfileWithName(name string) (*Profile, string, error) {
	cfg, err := Load()
	if err != nil {
		return nil, "", err
	}

	candidate := strings.TrimSpace(name)
	if candidate == "" {
		candidate = getProfileOverride()
	}

	p, resolvedName, err := cfg.resolveProfile(candidate)
	if err != nil {
		return nil, "", err
	}

	return p, resolvedName, nil
}

func SetActiveOverride(name string) {
	overrideMu.Lock()
	defer overrideMu.Unlock()
	profileOverride = strings.TrimSpace(name)
}

func ResetActiveOverride() {
	SetActiveOverride("")
}

func getProfileOverride() string {
	overrideMu.RLock()
	defer overrideMu.RUnlock()
	return strings.TrimSpace(profileOverride)
}

func (c *Config) migrateLegacy() {
	if len(c.Profiles) > 0 {
		return
	}
	if c.URL == "" && c.APIKey == "" && len(c.Defaults) == 0 {
		return
	}

	c.Profiles = map[string]*Profile{
		defaultProfileName: {
			URL:      c.URL,
			APIKey:   c.APIKey,
			Defaults: cloneDefaults(c.Defaults),
		},
	}
	if strings.TrimSpace(c.ActiveProfile) == "" {
		c.ActiveProfile = defaultProfileName
	}
}

func (c *Config) mergeLegacyIntoProfile() {
	if len(c.Profiles) == 0 {
		return
	}

	targetName := strings.TrimSpace(c.ActiveProfile)
	if targetName == "" {
		targetName = defaultProfileName
	}
	target, ok := c.Profiles[targetName]
	if !ok {
		target = c.Profiles[defaultProfileName]
	}
	if target == nil {
		return
	}

	if c.URL != "" {
		target.URL = c.URL
	}
	if c.APIKey != "" {
		target.APIKey = c.APIKey
	}
	for k, v := range c.Defaults {
		target.SetDefault(k, v)
	}
}

func (c *Config) cloneForSave() *Config {
	out := &Config{
		URL:           c.URL,
		APIKey:        c.APIKey,
		Defaults:      cloneDefaults(c.Defaults),
		ActiveProfile: strings.TrimSpace(c.ActiveProfile),
		Profiles:      cloneProfiles(c.Profiles),
	}

	if len(out.Profiles) > 0 {
		if out.ActiveProfile == "" {
			if _, ok := out.Profiles[defaultProfileName]; ok {
				out.ActiveProfile = defaultProfileName
			} else {
				names := profileNames(out.Profiles)
				if len(names) > 0 {
					out.ActiveProfile = names[0]
				}
			}
		}
		out.URL = ""
		out.APIKey = ""
		out.Defaults = nil
	}

	return out
}

func (c *Config) ensureWritableProfile() (*Profile, string) {
	if len(c.Profiles) == 0 {
		c.migrateLegacy()
	}
	if len(c.Profiles) == 0 {
		c.Profiles = map[string]*Profile{}
	}

	name := strings.TrimSpace(c.ActiveProfile)
	if name == "" {
		name = getProfileOverride()
	}
	if name == "" {
		name = defaultProfileName
	}

	p, ok := c.Profiles[name]
	if !ok || p == nil {
		p = &Profile{}
		c.Profiles[name] = p
	}
	if strings.TrimSpace(c.ActiveProfile) == "" {
		c.ActiveProfile = name
	}

	return p, name
}

func (c *Config) resolveProfile(name string) (*Profile, string, error) {
	c.migrateLegacy()

	target := strings.TrimSpace(name)
	if target == "" {
		target = strings.TrimSpace(c.ActiveProfile)
	}
	if target == "" {
		target = defaultProfileName
	}

	if len(c.Profiles) == 0 {
		if c.URL != "" || c.APIKey != "" || len(c.Defaults) > 0 {
			return &Profile{
				URL:      c.URL,
				APIKey:   c.APIKey,
				Defaults: cloneDefaults(c.Defaults),
			}, target, nil
		}
		if target == defaultProfileName {
			return &Profile{}, target, nil
		}
		return nil, "", fmt.Errorf("profile %q not found. available profiles: (none)", target)
	}

	p, ok := c.Profiles[target]
	if ok && p != nil {
		return p, target, nil
	}

	return nil, "", fmt.Errorf("profile %q not found. available profiles: %s", target, strings.Join(profileNames(c.Profiles), ", "))
}

func cloneDefaults(in map[string]string) map[string]string {
	if len(in) == 0 {
		return nil
	}
	out := make(map[string]string, len(in))
	for k, v := range in {
		out[k] = v
	}
	return out
}

func cloneProfiles(in map[string]*Profile) map[string]*Profile {
	if len(in) == 0 {
		return nil
	}
	out := make(map[string]*Profile, len(in))
	for name, p := range in {
		if p == nil {
			continue
		}
		cp := &Profile{
			URL:      p.URL,
			APIKey:   p.APIKey,
			Defaults: cloneDefaults(p.Defaults),
			Tags:     append([]string(nil), p.Tags...),
		}
		out[name] = cp
	}
	return out
}

func profileNames(profiles map[string]*Profile) []string {
	names := make([]string, 0, len(profiles))
	for name := range profiles {
		names = append(names, name)
	}
	sort.Strings(names)
	return names
}
