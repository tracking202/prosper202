package config

import (
	"encoding/json"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
)

// setTestHome overrides HOME (or USERPROFILE on Windows) so Dir()/Path()
// resolve into a temporary directory.
func setTestHome(t *testing.T, dir string) {
	t.Helper()
	if runtime.GOOS == "windows" {
		t.Setenv("USERPROFILE", dir)
	} else {
		t.Setenv("HOME", dir)
	}
}

func TestLoadNonexistentFile(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)

	cfg, err := Load()
	if err != nil {
		t.Fatalf("Load() returned error for nonexistent file: %v", err)
	}
	if cfg == nil {
		t.Fatal("Load() returned nil config")
	}
	if cfg.URL != "" || cfg.APIKey != "" {
		t.Fatalf("expected empty legacy fields, got URL=%q APIKey=%q", cfg.URL, cfg.APIKey)
	}
	if len(cfg.Profiles) != 0 {
		t.Fatalf("expected no profiles, got %d", len(cfg.Profiles))
	}
}

func TestLoadLegacyMigratesToDefaultProfile(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)

	dir := filepath.Join(tmp, ".p202")
	if err := os.MkdirAll(dir, 0700); err != nil {
		t.Fatal(err)
	}

	data := `{"url":"https://example.com","api_key":"test-key-12345678","defaults":{"report.period":"last30"}}`
	if err := os.WriteFile(filepath.Join(dir, "config.json"), []byte(data), 0600); err != nil {
		t.Fatal(err)
	}

	cfg, err := Load()
	if err != nil {
		t.Fatalf("Load() error: %v", err)
	}
	if cfg.ActiveProfile != "default" {
		t.Fatalf("ActiveProfile = %q, want default", cfg.ActiveProfile)
	}
	p, ok := cfg.Profiles["default"]
	if !ok || p == nil {
		t.Fatalf("default profile missing after migration")
	}
	if p.URL != "https://example.com" {
		t.Fatalf("default URL = %q", p.URL)
	}
	if p.APIKey != "test-key-12345678" {
		t.Fatalf("default API key = %q", p.APIKey)
	}
	if p.GetDefault("report.period") != "last30" {
		t.Fatalf("default profile defaults not migrated")
	}
}

func TestLoadInvalidJSON(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)

	dir := filepath.Join(tmp, ".p202")
	if err := os.MkdirAll(dir, 0700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, "config.json"), []byte("{not valid json"), 0600); err != nil {
		t.Fatal(err)
	}

	_, err := Load()
	if err == nil {
		t.Fatal("Load() should return error for invalid JSON")
	}
}

func TestSaveCreatesDirectoryAndFile(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)

	cfg := &Config{
		URL:    "https://example.com",
		APIKey: "my-secret-key",
	}
	if err := cfg.Save(); err != nil {
		t.Fatalf("Save() error: %v", err)
	}

	dirPath := filepath.Join(tmp, ".p202")
	info, err := os.Stat(dirPath)
	if err != nil {
		t.Fatalf("config dir not created: %v", err)
	}
	if !info.IsDir() {
		t.Fatal("config dir path is not a directory")
	}
	if runtime.GOOS != "windows" {
		if perm := info.Mode().Perm(); perm != 0700 {
			t.Fatalf("dir permissions = %o, want 0700", perm)
		}
	}

	filePath := filepath.Join(dirPath, "config.json")
	fInfo, err := os.Stat(filePath)
	if err != nil {
		t.Fatalf("config file not created: %v", err)
	}
	if runtime.GOOS != "windows" {
		if perm := fInfo.Mode().Perm(); perm != 0600 {
			t.Fatalf("file permissions = %o, want 0600", perm)
		}
	}

	content, err := os.ReadFile(filePath)
	if err != nil {
		t.Fatal(err)
	}

	var raw map[string]interface{}
	if err := json.Unmarshal(content, &raw); err != nil {
		t.Fatalf("saved file is not valid JSON: %v", err)
	}
	if _, exists := raw["url"]; exists {
		t.Fatalf("saved V2 config should omit legacy url field")
	}
	if _, exists := raw["api_key"]; exists {
		t.Fatalf("saved V2 config should omit legacy api_key field")
	}

	profiles, ok := raw["profiles"].(map[string]interface{})
	if !ok {
		t.Fatalf("saved config should contain profiles map")
	}
	def, ok := profiles["default"].(map[string]interface{})
	if !ok {
		t.Fatalf("saved config should include default profile")
	}
	if def["url"] != "https://example.com" {
		t.Fatalf("saved profile URL mismatch: %v", def["url"])
	}
	if def["api_key"] != "my-secret-key" {
		t.Fatalf("saved profile API key mismatch: %v", def["api_key"])
	}
}

func TestSaveThenLoadRoundTrip(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)

	original := &Config{
		URL:    "https://tracker.example.com",
		APIKey: "roundtrip-key-abcd1234",
	}
	if err := original.Save(); err != nil {
		t.Fatalf("Save() error: %v", err)
	}

	loaded, err := Load()
	if err != nil {
		t.Fatalf("Load() error: %v", err)
	}
	profile, err := loaded.ResolveProfile("default")
	if err != nil {
		t.Fatalf("ResolveProfile(default) error: %v", err)
	}
	if profile.URL != original.URL {
		t.Fatalf("URL round-trip: got %q, want %q", profile.URL, original.URL)
	}
	if profile.APIKey != original.APIKey {
		t.Fatalf("APIKey round-trip: got %q, want %q", profile.APIKey, original.APIKey)
	}
}

func TestResolveProfilePrecedence(t *testing.T) {
	cfg := &Config{
		ActiveProfile: "prod",
		Profiles: map[string]*Profile{
			"default": {URL: "https://default.example.com", APIKey: "default-key-1234"},
			"prod":    {URL: "https://prod.example.com", APIKey: "prod-key-1234"},
			"stage":   {URL: "https://stage.example.com", APIKey: "stage-key-1234"},
		},
	}

	p, err := cfg.ResolveProfile("stage")
	if err != nil {
		t.Fatalf("ResolveProfile(stage) error: %v", err)
	}
	if p.URL != "https://stage.example.com" {
		t.Fatalf("explicit resolve picked wrong profile URL: %q", p.URL)
	}

	p, err = cfg.ResolveProfile("")
	if err != nil {
		t.Fatalf("ResolveProfile(\"\") error: %v", err)
	}
	if p.URL != "https://prod.example.com" {
		t.Fatalf("fallback to active profile failed: %q", p.URL)
	}
}

func TestResolveProfileNotFound(t *testing.T) {
	cfg := &Config{
		Profiles: map[string]*Profile{
			"default": {},
			"prod":    {},
		},
	}
	_, err := cfg.ResolveProfile("missing")
	if err == nil {
		t.Fatal("ResolveProfile should error for missing profile")
	}
	if !strings.Contains(err.Error(), "available profiles: default, prod") {
		t.Fatalf("missing profile error should list available profiles: %v", err)
	}
}

func TestLoadProfileWithOverride(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)

	cfg := &Config{
		ActiveProfile: "default",
		Profiles: map[string]*Profile{
			"default": {URL: "https://default.example.com", APIKey: "default-key-1234"},
			"prod":    {URL: "https://prod.example.com", APIKey: "prod-key-1234"},
		},
	}
	if err := cfg.Save(); err != nil {
		t.Fatalf("Save() error: %v", err)
	}

	ResetActiveOverride()
	SetActiveOverride("prod")
	t.Cleanup(ResetActiveOverride)

	p, resolvedName, err := LoadProfileWithName("")
	if err != nil {
		t.Fatalf("LoadProfileWithName error: %v", err)
	}
	if resolvedName != "prod" {
		t.Fatalf("resolvedName = %q, want prod", resolvedName)
	}
	if p.URL != "https://prod.example.com" {
		t.Fatalf("override profile URL mismatch: %q", p.URL)
	}
}

func TestValidate(t *testing.T) {
	tests := []struct {
		name    string
		cfg     Config
		wantErr bool
		errMsg  string
	}{
		{
			name:    "empty URL",
			cfg:     Config{URL: "", APIKey: "some-key"},
			wantErr: true,
			errMsg:  "no URL configured",
		},
		{
			name:    "empty APIKey",
			cfg:     Config{URL: "https://example.com", APIKey: ""},
			wantErr: true,
			errMsg:  "no API key configured",
		},
		{
			name:    "both set",
			cfg:     Config{URL: "https://example.com", APIKey: "key123456"},
			wantErr: false,
		},
		{
			name:    "both empty",
			cfg:     Config{URL: "", APIKey: ""},
			wantErr: true,
			errMsg:  "no URL configured",
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			err := tt.cfg.Validate()
			if tt.wantErr {
				if err == nil {
					t.Fatal("Validate() should return error")
				}
				if tt.errMsg != "" && !strings.Contains(err.Error(), tt.errMsg) {
					t.Fatalf("error = %q, want contains %q", err.Error(), tt.errMsg)
				}
				return
			}
			if err != nil {
				t.Fatalf("Validate() unexpected error: %v", err)
			}
		})
	}
}

func TestMaskedKey(t *testing.T) {
	tests := []struct {
		name string
		key  string
		want string
	}{
		{name: "long key", key: "abcdefghijklmnop", want: "abcd...mnop"},
		{name: "exactly 9 chars", key: "123456789", want: "1234...6789"},
		{name: "exactly 8 chars", key: "12345678", want: "********"},
		{name: "short key", key: "abcd", want: "****"},
		{name: "empty key", key: "", want: "(not set)"},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			cfg := &Config{APIKey: tt.key}
			if got := cfg.MaskedKey(); got != tt.want {
				t.Fatalf("MaskedKey() = %q, want %q", got, tt.want)
			}
		})
	}
}

func TestDirAndPath(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)

	dir := Dir()
	expectedDir := filepath.Join(tmp, ".p202")
	if dir != expectedDir {
		t.Fatalf("Dir() = %q, want %q", dir, expectedDir)
	}

	path := Path()
	expectedPath := filepath.Join(tmp, ".p202", "config.json")
	if path != expectedPath {
		t.Fatalf("Path() = %q, want %q", path, expectedPath)
	}
}
