// Package atomicfile writes a file's full contents in one all-or-nothing step.
//
// It exists because the CLI keeps two things under ~/.p202 that must never be
// observed half-written: the config file holding the API key, and the sync
// manifest that decides what an incremental sync will skip. A plain
// os.WriteFile is wrong for both — it truncates in place, so a crash mid-write
// leaves a corrupt file, and its permission argument applies only when it
// creates the file, so a file that already exists with looser permissions keeps
// them forever.
package atomicfile

import (
	"fmt"
	"os"
	"path/filepath"
)

// Write creates data at path with the given permissions, atomically.
//
// The write goes to a temp file in the same directory (same filesystem, so the
// rename cannot fail with EXDEV), is flushed to disk, and is then renamed over
// path. Rename replaces the destination directory entry, which also means a
// symlink planted at path is replaced rather than written through. On any
// failure the temp file is removed and path is left untouched.
func Write(path string, data []byte, perm os.FileMode) error {
	dir := filepath.Dir(path)
	tmp, err := os.CreateTemp(dir, "."+filepath.Base(path)+".*.tmp")
	if err != nil {
		return fmt.Errorf("creating temp file in %s: %w", dir, err)
	}
	tmpName := tmp.Name()
	abandon := func(verb string, cause error) error {
		_ = tmp.Close()
		_ = os.Remove(tmpName)
		return fmt.Errorf("%s %s: %w", verb, tmpName, cause)
	}

	// os.CreateTemp already uses 0600, but the caller's intent is what must hold
	// on the final file, so set it explicitly rather than inheriting a default.
	if err := tmp.Chmod(perm); err != nil {
		return abandon("setting permissions on", err)
	}
	if _, err := tmp.Write(data); err != nil {
		return abandon("writing", err)
	}
	// Flush before the rename: without it a crash can leave the renamed entry
	// pointing at unwritten data.
	if err := tmp.Sync(); err != nil {
		return abandon("flushing", err)
	}
	if err := tmp.Close(); err != nil {
		_ = os.Remove(tmpName)
		return fmt.Errorf("closing %s: %w", tmpName, err)
	}
	if err := os.Rename(tmpName, path); err != nil {
		_ = os.Remove(tmpName)
		return fmt.Errorf("renaming %s to %s: %w", tmpName, path, err)
	}
	return nil
}
