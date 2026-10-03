package atomicfile

import (
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
)

func TestWriteCreatesFileWithContentAndMode(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "config.json")

	if err := Write(path, []byte("hello\n"), 0600); err != nil {
		t.Fatalf("Write: %v", err)
	}

	got, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	if string(got) != "hello\n" {
		t.Fatalf("content = %q, want %q", got, "hello\n")
	}
	if runtime.GOOS != "windows" {
		info, err := os.Stat(path)
		if err != nil {
			t.Fatal(err)
		}
		if mode := info.Mode().Perm(); mode != 0600 {
			t.Fatalf("mode = %04o, want 0600", mode)
		}
	}
}

// os.WriteFile's mode argument applies only on creation, so an existing file
// with looser permissions kept them. Write must enforce the mode every time.
func TestWriteTightensModeOnExistingFile(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("POSIX permission bits are not meaningful on Windows")
	}
	dir := t.TempDir()
	path := filepath.Join(dir, "config.json")

	if err := os.WriteFile(path, []byte("old"), 0644); err != nil {
		t.Fatal(err)
	}
	if err := Write(path, []byte("new"), 0600); err != nil {
		t.Fatalf("Write: %v", err)
	}

	info, err := os.Stat(path)
	if err != nil {
		t.Fatal(err)
	}
	if mode := info.Mode().Perm(); mode != 0600 {
		t.Fatalf("mode = %04o, want 0600", mode)
	}
}

// A symlink planted at the destination must be replaced, not written through —
// otherwise an API key could be redirected into an attacker-readable file.
func TestWriteReplacesSymlinkInsteadOfFollowingIt(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("symlink creation needs elevation on Windows")
	}
	dir := t.TempDir()
	outside := filepath.Join(dir, "outside.txt")
	if err := os.WriteFile(outside, []byte("untouched"), 0600); err != nil {
		t.Fatal(err)
	}
	path := filepath.Join(dir, "config.json")
	if err := os.Symlink(outside, path); err != nil {
		t.Fatal(err)
	}

	if err := Write(path, []byte("secret"), 0600); err != nil {
		t.Fatalf("Write: %v", err)
	}

	victim, err := os.ReadFile(outside)
	if err != nil {
		t.Fatal(err)
	}
	if string(victim) != "untouched" {
		t.Fatalf("symlink was followed: target now %q", victim)
	}
	info, err := os.Lstat(path)
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode()&os.ModeSymlink != 0 {
		t.Fatal("destination is still a symlink")
	}
	got, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	if string(got) != "secret" {
		t.Fatalf("content = %q, want %q", got, "secret")
	}
}

func TestWriteLeavesNoTempFilesBehind(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "config.json")

	for i := 0; i < 3; i++ {
		if err := Write(path, []byte("data"), 0600); err != nil {
			t.Fatalf("Write: %v", err)
		}
	}

	entries, err := os.ReadDir(dir)
	if err != nil {
		t.Fatal(err)
	}
	for _, e := range entries {
		if strings.HasSuffix(e.Name(), ".tmp") {
			t.Fatalf("temp file left behind: %s", e.Name())
		}
	}
	if len(entries) != 1 {
		t.Fatalf("expected exactly the target file, got %d entries", len(entries))
	}
}

// A failure must leave the previous contents intact rather than a truncated file.
func TestWriteFailureLeavesDestinationIntact(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "sub", "config.json")

	// The parent directory does not exist, so the temp create fails.
	if err := Write(path, []byte("data"), 0600); err == nil {
		t.Fatal("expected an error writing into a missing directory")
	}
	if _, err := os.Stat(path); !os.IsNotExist(err) {
		t.Fatalf("destination should not exist, stat err = %v", err)
	}
}
