package config

import (
	"encoding/json"
	"os"
	"path/filepath"
	"testing"
)

func writeRawConfig(t *testing.T, home, contents string) {
	t.Helper()
	dir := filepath.Join(home, ".p202")
	if err := os.MkdirAll(dir, 0700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, "config.json"), []byte(contents), 0600); err != nil {
		t.Fatal(err)
	}
}

func readRawConfig(t *testing.T) *Config {
	t.Helper()
	data, err := os.ReadFile(Path())
	if err != nil {
		t.Fatal(err)
	}
	var c Config
	if err := json.Unmarshal(data, &c); err != nil {
		t.Fatalf("saved config is not valid JSON: %v", err)
	}
	return &c
}

// A V1 config file keeps its legacy top-level url/api_key after Load()
// migrates them into a profile. Save() then re-merges those stale legacy
// values over the profile, so the credential the user just set is discarded.
// This is the exact `p202 config set-key` path.
func TestSetKeyOnLegacyConfigPersistsNewKey(t *testing.T) {
	home := t.TempDir()
	setTestHome(t, home)
	writeRawConfig(t, home, `{"url":"https://old.example.com","api_key":"old-key-12345678"}`)

	cfg, err := Load()
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	p, name, err := cfg.EnsureProfile("")
	if err != nil {
		t.Fatalf("EnsureProfile: %v", err)
	}
	if name != defaultProfileName {
		t.Fatalf("resolved profile = %q, want %q", name, defaultProfileName)
	}
	p.APIKey = "new-key-87654321"
	if err := cfg.Save(); err != nil {
		t.Fatalf("Save: %v", err)
	}

	// The in-memory profile must still hold what the caller assigned; command
	// code prints p.MaskedKey() after Save().
	if p.APIKey != "new-key-87654321" {
		t.Fatalf("in-memory API key = %q, want new-key-87654321", p.APIKey)
	}

	reloaded, err := Load()
	if err != nil {
		t.Fatalf("reload: %v", err)
	}
	got, _, err := reloaded.resolveProfile("")
	if err != nil {
		t.Fatalf("resolveProfile after reload: %v", err)
	}
	if got.APIKey != "new-key-87654321" {
		t.Fatalf("persisted API key = %q, want new-key-87654321", got.APIKey)
	}
	if got.URL != "https://old.example.com" {
		t.Fatalf("persisted URL = %q, want the migrated legacy URL", got.URL)
	}
}

// Same defect via set-url.
func TestSetURLOnLegacyConfigPersistsNewURL(t *testing.T) {
	home := t.TempDir()
	setTestHome(t, home)
	writeRawConfig(t, home, `{"url":"https://old.example.com","api_key":"old-key-12345678"}`)

	cfg, err := Load()
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	p, _, err := cfg.EnsureProfile("")
	if err != nil {
		t.Fatalf("EnsureProfile: %v", err)
	}
	p.URL = "https://new.example.com"
	if err := cfg.Save(); err != nil {
		t.Fatalf("Save: %v", err)
	}

	reloaded, err := Load()
	if err != nil {
		t.Fatalf("reload: %v", err)
	}
	got, _, err := reloaded.resolveProfile("")
	if err != nil {
		t.Fatalf("resolveProfile after reload: %v", err)
	}
	if got.URL != "https://new.example.com" {
		t.Fatalf("persisted URL = %q, want https://new.example.com", got.URL)
	}
}

// A hand-edited or partially-upgraded file can carry BOTH legacy top-level
// fields and a profiles map. The legacy values must be consumed once (merged
// into the active profile at load) and then never re-applied, otherwise every
// later write is silently reverted to them.
func TestLegacyFieldsAreConsumedNotReappliedOnEverySave(t *testing.T) {
	home := t.TempDir()
	setTestHome(t, home)
	writeRawConfig(t, home, `{
	  "url":"https://legacy.example.com",
	  "api_key":"legacy-key-1234",
	  "active_profile":"prod",
	  "profiles":{"prod":{"url":"https://prod.example.com","api_key":"prod-key-1234"}}
	}`)

	cfg, err := Load()
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	p, _, err := cfg.EnsureProfile("prod")
	if err != nil {
		t.Fatalf("EnsureProfile: %v", err)
	}
	p.APIKey = "chosen-key-1234"
	if err := cfg.Save(); err != nil {
		t.Fatalf("Save: %v", err)
	}

	saved := readRawConfig(t)
	if saved.URL != "" || saved.APIKey != "" {
		t.Fatalf("legacy fields survived the save: url=%q api_key=%q", saved.URL, saved.APIKey)
	}
	prod := saved.Profiles["prod"]
	if prod == nil {
		t.Fatal("prod profile missing after save")
	}
	if prod.APIKey != "chosen-key-1234" {
		t.Fatalf("persisted API key = %q, want chosen-key-1234", prod.APIKey)
	}
}

// When active_profile names a profile that isn't in the map, the legacy
// credential still has to land somewhere — clearing it with nowhere to go would
// silently destroy the only credential the config has.
func TestLegacyFieldsSurviveMissingActiveProfile(t *testing.T) {
	home := t.TempDir()
	setTestHome(t, home)
	writeRawConfig(t, home, `{
	  "url":"https://legacy.example.com",
	  "api_key":"legacy-key-1234",
	  "active_profile":"prod",
	  "profiles":{"other":{"url":"https://other","api_key":"other-key-1234"}}
	}`)

	cfg, err := Load()
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if err := cfg.Save(); err != nil {
		t.Fatalf("Save: %v", err)
	}

	saved := readRawConfig(t)
	prod := saved.Profiles["prod"]
	if prod == nil {
		t.Fatalf("legacy credential dropped: no prod profile in %+v", saved.Profiles)
	}
	if prod.APIKey != "legacy-key-1234" || prod.URL != "https://legacy.example.com" {
		t.Fatalf("legacy values not preserved: url=%q api_key=%q", prod.URL, prod.APIKey)
	}
	// The unrelated profile must be left exactly as it was.
	other := saved.Profiles["other"]
	if other == nil || other.APIKey != "other-key-1234" {
		t.Fatalf("unrelated profile was modified: %+v", other)
	}
}

// A nil profile entry is representable in JSON (`"profiles":{"x":null}`) and
// every other accessor guards against it. ResolveGroup must not panic.
func TestResolveGroupToleratesNilProfileEntry(t *testing.T) {
	home := t.TempDir()
	setTestHome(t, home)
	writeRawConfig(t, home, `{"active_profile":"a","profiles":{"a":{"url":"https://a","api_key":"k1234567","tags":["prod"]},"b":null}}`)

	cfg, err := Load()
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	got := cfg.ResolveGroup("prod")
	if len(got) != 1 || got[0] != "a" {
		t.Fatalf("ResolveGroup(prod) = %v, want [a]", got)
	}
}

// The config file holds a bearer credential. A file that already exists with
// looser permissions (an older CLI wrote 0644, or an admin copied it in) must
// be tightened on write — os.WriteFile's mode argument only applies when it
// creates the file.
func TestSaveTightensPermissionsOnPreexistingFile(t *testing.T) {
	home := t.TempDir()
	setTestHome(t, home)
	writeRawConfig(t, home, `{"active_profile":"default","profiles":{"default":{"url":"https://a","api_key":"k1234567"}}}`)
	if err := os.Chmod(Path(), 0644); err != nil {
		t.Fatal(err)
	}

	cfg, err := Load()
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if err := cfg.Save(); err != nil {
		t.Fatalf("Save: %v", err)
	}

	info, err := os.Stat(Path())
	if err != nil {
		t.Fatal(err)
	}
	if mode := info.Mode().Perm(); mode != 0600 {
		t.Fatalf("config mode = %04o, want 0600", mode)
	}
}
