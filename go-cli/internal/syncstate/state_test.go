package syncstate

import (
	"encoding/json"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
	"time"

	syncdata "p202/internal/sync"
)

// setTestHome overrides HOME (or USERPROFILE on Windows) so Dir()/ManifestPath()
// resolve into a temporary directory.
func setTestHome(t *testing.T, dir string) {
	t.Helper()
	if runtime.GOOS == "windows" {
		t.Setenv("USERPROFILE", dir)
	} else {
		t.Setenv("HOME", dir)
	}
}

func TestNewManifestInitializesEmpty(t *testing.T) {
	m := NewManifest("src", "dst")
	if m.Source != "src" {
		t.Fatalf("Source = %q, want %q", m.Source, "src")
	}
	if m.Target != "dst" {
		t.Fatalf("Target = %q, want %q", m.Target, "dst")
	}
	if m.Mappings == nil || len(m.Mappings) != 0 {
		t.Fatalf("Mappings should be empty non-nil map, got %v", m.Mappings)
	}
	if m.History == nil || len(m.History) != 0 {
		t.Fatalf("History should be empty non-nil slice, got %v", m.History)
	}
	if m.LastSync != "" {
		t.Fatalf("LastSync should be empty, got %q", m.LastSync)
	}
}

func TestSetMappingGetMappingRoundTrip(t *testing.T) {
	m := NewManifest("src", "dst")
	now := time.Date(2025, 6, 15, 12, 0, 0, 0, time.UTC)

	m.SetMapping("campaigns", "10", "20", "Test Campaign", "abc123", now)

	entry, ok := m.GetMapping("campaigns", "10")
	if !ok {
		t.Fatal("GetMapping() returned false for known key")
	}
	if entry.TargetID != "20" {
		t.Fatalf("TargetID = %q, want %q", entry.TargetID, "20")
	}
	if entry.SourceName != "Test Campaign" {
		t.Fatalf("SourceName = %q, want %q", entry.SourceName, "Test Campaign")
	}
	if entry.SourceHash != "abc123" {
		t.Fatalf("SourceHash = %q, want %q", entry.SourceHash, "abc123")
	}
	if entry.SyncedAt != "2025-06-15T12:00:00Z" {
		t.Fatalf("SyncedAt = %q, want %q", entry.SyncedAt, "2025-06-15T12:00:00Z")
	}
}

func TestGetMappingUnknownEntity(t *testing.T) {
	m := NewManifest("src", "dst")
	m.SetMapping("campaigns", "10", "20", "C", "", time.Now())

	_, ok := m.GetMapping("offers", "10")
	if ok {
		t.Fatal("GetMapping() should return false for unknown entity")
	}
}

func TestGetMappingUnknownSourceID(t *testing.T) {
	m := NewManifest("src", "dst")
	m.SetMapping("campaigns", "10", "20", "C", "", time.Now())

	_, ok := m.GetMapping("campaigns", "999")
	if ok {
		t.Fatal("GetMapping() should return false for unknown sourceID")
	}
}

func TestGetMappingNilMappings(t *testing.T) {
	m := &Manifest{}
	_, ok := m.GetMapping("campaigns", "10")
	if ok {
		t.Fatal("GetMapping() should return false when Mappings is nil")
	}
}

func TestRecordHistoryAppendsEntry(t *testing.T) {
	m := NewManifest("src", "dst")
	t1 := time.Date(2025, 6, 15, 10, 0, 0, 0, time.UTC)
	t2 := time.Date(2025, 6, 15, 11, 0, 0, 0, time.UTC)

	results1 := map[string]syncdata.EntityResult{
		"campaigns": {Synced: 3, Skipped: 1},
	}
	results2 := map[string]syncdata.EntityResult{
		"offers": {Synced: 5, Failed: 2, Errors: []string{"err1", "err2"}},
	}

	m.RecordHistory(results1, false, t1)
	m.RecordHistory(results2, true, t2)

	if len(m.History) != 2 {
		t.Fatalf("History length = %d, want 2", len(m.History))
	}

	h1 := m.History[0]
	if h1.Timestamp != "2025-06-15T10:00:00Z" {
		t.Fatalf("h1.Timestamp = %q, want %q", h1.Timestamp, "2025-06-15T10:00:00Z")
	}
	if h1.DryRun {
		t.Fatal("h1.DryRun should be false")
	}
	if h1.Results["campaigns"].Synced != 3 {
		t.Fatalf("h1 campaigns.Synced = %d, want 3", h1.Results["campaigns"].Synced)
	}

	h2 := m.History[1]
	if h2.Timestamp != "2025-06-15T11:00:00Z" {
		t.Fatalf("h2.Timestamp = %q, want %q", h2.Timestamp, "2025-06-15T11:00:00Z")
	}
	if !h2.DryRun {
		t.Fatal("h2.DryRun should be true")
	}
}

func TestRecordHistoryUpdatesLastSync(t *testing.T) {
	m := NewManifest("src", "dst")
	t1 := time.Date(2025, 6, 15, 12, 0, 0, 0, time.UTC)
	m.RecordHistory(map[string]syncdata.EntityResult{}, false, t1)

	if m.LastSync != "2025-06-15T12:00:00Z" {
		t.Fatalf("LastSync = %q, want %q", m.LastSync, "2025-06-15T12:00:00Z")
	}

	t2 := time.Date(2025, 6, 16, 8, 0, 0, 0, time.UTC)
	m.RecordHistory(map[string]syncdata.EntityResult{}, true, t2)
	if m.LastSync != "2025-06-16T08:00:00Z" {
		t.Fatalf("LastSync after second record = %q, want %q", m.LastSync, "2025-06-16T08:00:00Z")
	}
}

func TestSaveAndLoadManifestRoundTrip(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)

	m := NewManifest("alpha", "beta")
	m.SetMapping("campaigns", "1", "100", "Camp 1", "hash1", time.Date(2025, 1, 1, 0, 0, 0, 0, time.UTC))
	m.RecordHistory(map[string]syncdata.EntityResult{
		"campaigns": {Synced: 1},
	}, false, time.Date(2025, 1, 1, 0, 0, 0, 0, time.UTC))

	if err := SaveManifestAtomic(m); err != nil {
		t.Fatalf("SaveManifestAtomic() error: %v", err)
	}

	loaded, err := LoadManifest("alpha", "beta")
	if err != nil {
		t.Fatalf("LoadManifest() error: %v", err)
	}

	if loaded.Source != "alpha" || loaded.Target != "beta" {
		t.Fatalf("Source/Target mismatch: %q/%q", loaded.Source, loaded.Target)
	}

	entry, ok := loaded.GetMapping("campaigns", "1")
	if !ok {
		t.Fatal("mapping not found after round-trip")
	}
	if entry.TargetID != "100" {
		t.Fatalf("TargetID = %q, want %q", entry.TargetID, "100")
	}
	if entry.SourceName != "Camp 1" {
		t.Fatalf("SourceName = %q, want %q", entry.SourceName, "Camp 1")
	}

	if len(loaded.History) != 1 {
		t.Fatalf("History length = %d, want 1", len(loaded.History))
	}
	if loaded.LastSync != "2025-01-01T00:00:00Z" {
		t.Fatalf("LastSync = %q, want %q", loaded.LastSync, "2025-01-01T00:00:00Z")
	}
}

func TestLoadManifestNonExistentPath(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)

	m, err := LoadManifest("no-such", "profile")
	if err != nil {
		t.Fatalf("LoadManifest() should succeed for non-existent path: %v", err)
	}
	if m.Source != "no-such" || m.Target != "profile" {
		t.Fatalf("fresh manifest Source/Target = %q/%q", m.Source, m.Target)
	}
	if len(m.Mappings) != 0 || len(m.History) != 0 {
		t.Fatal("fresh manifest should have empty mappings and history")
	}
}

func TestLoadManifestFillsNilFields(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)

	syncDir := filepath.Join(tmp, ".p202", "sync")
	if err := os.MkdirAll(syncDir, 0700); err != nil {
		t.Fatal(err)
	}

	// Write partial JSON with nil mappings and history.
	partial := `{"source":"a","target":"b"}`
	path := ManifestPath("a", "b")
	if err := os.WriteFile(path, []byte(partial), 0600); err != nil {
		t.Fatal(err)
	}

	m, err := LoadManifest("a", "b")
	if err != nil {
		t.Fatalf("LoadManifest() error: %v", err)
	}
	if m.Mappings == nil {
		t.Fatal("Mappings should be initialized, got nil")
	}
	if m.History == nil {
		t.Fatal("History should be initialized, got nil")
	}
}

func TestLoadManifestFillsEmptySourceTarget(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)

	syncDir := filepath.Join(tmp, ".p202", "sync")
	if err := os.MkdirAll(syncDir, 0700); err != nil {
		t.Fatal(err)
	}

	partial := `{"mappings":{},"history":[]}`
	path := ManifestPath("x", "y")
	if err := os.WriteFile(path, []byte(partial), 0600); err != nil {
		t.Fatal(err)
	}

	m, err := LoadManifest("x", "y")
	if err != nil {
		t.Fatalf("LoadManifest() error: %v", err)
	}
	if m.Source != "x" {
		t.Fatalf("Source should be filled to %q, got %q", "x", m.Source)
	}
	if m.Target != "y" {
		t.Fatalf("Target should be filled to %q, got %q", "y", m.Target)
	}
}

func TestSaveManifestAtomicNilError(t *testing.T) {
	err := SaveManifestAtomic(nil)
	if err == nil {
		t.Fatal("SaveManifestAtomic(nil) should return error")
	}
	if !strings.Contains(err.Error(), "nil") {
		t.Fatalf("error should mention nil: %v", err)
	}
}

func TestSaveManifestAtomicWritesValidJSON(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)

	m := NewManifest("src", "dst")
	m.SetMapping("campaigns", "5", "50", "C5", "", time.Now())

	if err := SaveManifestAtomic(m); err != nil {
		t.Fatalf("SaveManifestAtomic() error: %v", err)
	}

	path := ManifestPath("src", "dst")
	data, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("ReadFile error: %v", err)
	}

	var raw map[string]interface{}
	if err := json.Unmarshal(data, &raw); err != nil {
		t.Fatalf("saved manifest is not valid JSON: %v", err)
	}

	if raw["source"] != "src" || raw["target"] != "dst" {
		t.Fatalf("saved source/target mismatch: %v/%v", raw["source"], raw["target"])
	}
}

func TestAcquireLockSucceedsFirst(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)

	release, err := AcquireLock("src", "dst")
	if err != nil {
		t.Fatalf("AcquireLock() error: %v", err)
	}
	if release == nil {
		t.Fatal("release function should not be nil")
	}

	// Lock file should exist.
	lockPath := LockPath("src", "dst")
	if _, err := os.Stat(lockPath); err != nil {
		t.Fatalf("lock file should exist: %v", err)
	}

	release()

	// Lock file should be removed after release.
	if _, err := os.Stat(lockPath); !os.IsNotExist(err) {
		t.Fatalf("lock file should be removed after release, stat err = %v", err)
	}
}

func TestAcquireLockContention(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)

	release, err := AcquireLock("src", "dst")
	if err != nil {
		t.Fatalf("first AcquireLock() error: %v", err)
	}
	defer release()

	_, err = AcquireLock("src", "dst")
	if err == nil {
		t.Fatal("second AcquireLock() should fail due to contention")
	}
	if !strings.Contains(err.Error(), "lock is already held") {
		t.Fatalf("contention error should mention lock: %v", err)
	}
}

func TestSanitizeProfilePair(t *testing.T) {
	tests := []struct {
		source string
		target string
		want   string
	}{
		{"alpha", "beta", "alpha-beta"},
		{"Alpha", "Beta", "alpha-beta"},
		{"  alpha  ", "  beta  ", "alpha-beta"},
		{"a/b", "c\\d", "a-b-c-d"},
		{"a:b", "c,d", "a-b-c-d"},
		{"has space", "also space", "has-space-also-space"},
		{"", "beta", "unknown-beta"},
		{"alpha", "", "alpha-unknown"},
		{"", "", "unknown-unknown"},
	}

	for _, tt := range tests {
		t.Run(tt.source+"_"+tt.target, func(t *testing.T) {
			got := sanitizeProfilePair(tt.source, tt.target)
			if got != tt.want {
				t.Fatalf("sanitizeProfilePair(%q, %q) = %q, want %q", tt.source, tt.target, got, tt.want)
			}
		})
	}
}

func TestManifestPathAndLockPath(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)

	mp := ManifestPath("alpha", "beta")
	expected := filepath.Join(tmp, ".p202", "sync", "alpha-beta.json")
	if mp != expected {
		t.Fatalf("ManifestPath() = %q, want %q", mp, expected)
	}

	lp := LockPath("alpha", "beta")
	expectedLock := filepath.Join(tmp, ".p202", "sync", "alpha-beta.lock")
	if lp != expectedLock {
		t.Fatalf("LockPath() = %q, want %q", lp, expectedLock)
	}
}

func TestDirUsesConfigDir(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)

	d := Dir()
	expected := filepath.Join(tmp, ".p202", "sync")
	if d != expected {
		t.Fatalf("Dir() = %q, want %q", d, expected)
	}
}
