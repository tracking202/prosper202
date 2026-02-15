package config

import (
	"encoding/json"
	"os"
	"path/filepath"
	"runtime"
	"testing"
)

// setTestHome overrides HOME (or USERPROFILE on Windows) so that Dir()/Path()
// resolve to a temporary directory. Returns a cleanup function.
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
	if cfg.URL != "" {
		t.Errorf("expected empty URL, got %q", cfg.URL)
	}
	if cfg.APIKey != "" {
		t.Errorf("expected empty APIKey, got %q", cfg.APIKey)
	}
}

func TestLoadValidJSON(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)

	dir := filepath.Join(tmp, ".p202")
	if err := os.MkdirAll(dir, 0700); err != nil {
		t.Fatal(err)
	}

	data := `{"url": "https://example.com", "api_key": "test-key-12345678"}`
	if err := os.WriteFile(filepath.Join(dir, "config.json"), []byte(data), 0600); err != nil {
		t.Fatal(err)
	}

	cfg, err := Load()
	if err != nil {
		t.Fatalf("Load() error: %v", err)
	}
	if cfg.URL != "https://example.com" {
		t.Errorf("URL = %q, want %q", cfg.URL, "https://example.com")
	}
	if cfg.APIKey != "test-key-12345678" {
		t.Errorf("APIKey = %q, want %q", cfg.APIKey, "test-key-12345678")
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

	// Check directory was created with 0700 permissions
	dirPath := filepath.Join(tmp, ".p202")
	info, err := os.Stat(dirPath)
	if err != nil {
		t.Fatalf("config dir not created: %v", err)
	}
	if !info.IsDir() {
		t.Fatal("config dir path is not a directory")
	}
	if runtime.GOOS != "windows" {
		perm := info.Mode().Perm()
		if perm != 0700 {
			t.Errorf("dir permissions = %o, want 0700", perm)
		}
	}

	// Check file was created with 0600 permissions
	filePath := filepath.Join(dirPath, "config.json")
	fInfo, err := os.Stat(filePath)
	if err != nil {
		t.Fatalf("config file not created: %v", err)
	}
	if runtime.GOOS != "windows" {
		perm := fInfo.Mode().Perm()
		if perm != 0600 {
			t.Errorf("file permissions = %o, want 0600", perm)
		}
	}

	// Verify file content is valid JSON
	content, err := os.ReadFile(filePath)
	if err != nil {
		t.Fatal(err)
	}
	var parsed Config
	if err := json.Unmarshal(content, &parsed); err != nil {
		t.Fatalf("saved file is not valid JSON: %v", err)
	}
	if parsed.URL != "https://example.com" {
		t.Errorf("saved URL = %q, want %q", parsed.URL, "https://example.com")
	}
	if parsed.APIKey != "my-secret-key" {
		t.Errorf("saved APIKey = %q, want %q", parsed.APIKey, "my-secret-key")
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

	if loaded.URL != original.URL {
		t.Errorf("URL round-trip: got %q, want %q", loaded.URL, original.URL)
	}
	if loaded.APIKey != original.APIKey {
		t.Errorf("APIKey round-trip: got %q, want %q", loaded.APIKey, original.APIKey)
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
			cfg:     Config{URL: "https://example.com", APIKey: "key123"},
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
					t.Error("Validate() should return error")
				} else if tt.errMsg != "" {
					if got := err.Error(); !contains(got, tt.errMsg) {
						t.Errorf("error = %q, want it to contain %q", got, tt.errMsg)
					}
				}
			} else {
				if err != nil {
					t.Errorf("Validate() unexpected error: %v", err)
				}
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
		{
			name: "long key (16 chars)",
			key:  "abcdefghijklmnop",
			want: "abcd...mnop",
		},
		{
			name: "exactly 9 chars (> 8)",
			key:  "123456789",
			want: "1234...6789",
		},
		{
			name: "exactly 8 chars (<= 8)",
			key:  "12345678",
			want: "12345678",
		},
		{
			name: "short key (4 chars)",
			key:  "abcd",
			want: "abcd",
		},
		{
			name: "empty key",
			key:  "",
			want: "",
		},
		{
			name: "1 char",
			key:  "x",
			want: "x",
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			cfg := &Config{APIKey: tt.key}
			got := cfg.MaskedKey()
			if got != tt.want {
				t.Errorf("MaskedKey() = %q, want %q", got, tt.want)
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
		t.Errorf("Dir() = %q, want %q", dir, expectedDir)
	}

	path := Path()
	expectedPath := filepath.Join(tmp, ".p202", "config.json")
	if path != expectedPath {
		t.Errorf("Path() = %q, want %q", path, expectedPath)
	}
}

func contains(s, substr string) bool {
	return len(s) >= len(substr) && (s == substr || len(s) > 0 && containsStr(s, substr))
}

func containsStr(s, substr string) bool {
	for i := 0; i <= len(s)-len(substr); i++ {
		if s[i:i+len(substr)] == substr {
			return true
		}
	}
	return false
}
