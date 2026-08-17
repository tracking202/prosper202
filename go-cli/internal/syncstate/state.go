package syncstate

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"p202/internal/atomicfile"
	configpkg "p202/internal/config"
	syncdata "p202/internal/sync"
)

type MappingEntry struct {
	TargetID   string `json:"target_id"`
	SourceName string `json:"source_name"`
	SourceHash string `json:"source_hash,omitempty"`
	SyncedAt   string `json:"synced_at"`
}

type HistoryEntry struct {
	Timestamp string                           `json:"timestamp"`
	DryRun    bool                             `json:"dry_run"`
	Results   map[string]syncdata.EntityResult `json:"results"`
}

type Manifest struct {
	Source   string                             `json:"source"`
	Target   string                             `json:"target"`
	LastSync string                             `json:"last_sync,omitempty"`
	Mappings map[string]map[string]MappingEntry `json:"mappings"`
	History  []HistoryEntry                     `json:"history,omitempty"`
}

func NewManifest(source, target string) *Manifest {
	return &Manifest{
		Source:   source,
		Target:   target,
		Mappings: map[string]map[string]MappingEntry{},
		History:  []HistoryEntry{},
	}
}

func Dir() string {
	return filepath.Join(configpkg.Dir(), "sync")
}

func ManifestPath(source, target string) string {
	safe := sanitizeProfilePair(source, target)
	return filepath.Join(Dir(), safe+".json")
}

func LockPath(source, target string) string {
	safe := sanitizeProfilePair(source, target)
	return filepath.Join(Dir(), safe+".lock")
}

func LoadManifest(source, target string) (*Manifest, error) {
	path := ManifestPath(source, target)
	data, err := os.ReadFile(path)
	if err != nil {
		if os.IsNotExist(err) {
			return NewManifest(source, target), nil
		}
		return nil, fmt.Errorf("reading manifest: %w", err)
	}

	var manifest Manifest
	if err := json.Unmarshal(data, &manifest); err != nil {
		return nil, fmt.Errorf("parsing manifest: %w", err)
	}
	if manifest.Source == "" {
		manifest.Source = source
	}
	if manifest.Target == "" {
		manifest.Target = target
	}
	if manifest.Mappings == nil {
		manifest.Mappings = map[string]map[string]MappingEntry{}
	}
	if manifest.History == nil {
		manifest.History = []HistoryEntry{}
	}
	return &manifest, nil
}

func SaveManifestAtomic(manifest *Manifest) error {
	if manifest == nil {
		return fmt.Errorf("manifest is nil")
	}
	if err := os.MkdirAll(Dir(), 0700); err != nil {
		return fmt.Errorf("creating sync state dir: %w", err)
	}

	data, err := json.MarshalIndent(manifest, "", "  ")
	if err != nil {
		return fmt.Errorf("encoding manifest: %w", err)
	}
	data = append(data, '\n')

	// The manifest decides what the next incremental sync skips, so a truncated
	// one would make the CLI silently re-create or skip records. The previous
	// write-then-rename left its temp file behind whenever the rename failed and
	// never flushed before renaming.
	if err := atomicfile.Write(ManifestPath(manifest.Source, manifest.Target), data, 0600); err != nil {
		return fmt.Errorf("writing manifest: %w", err)
	}
	return nil
}

// ErrLockHeld reports that another live process holds the sync lock.
var ErrLockHeld = errors.New("sync lock is already held")

// AcquireLock takes the exclusive sync lock for a profile pair and returns the
// release function.
//
// The lock is a kernel-held file lock (flock on unix, LockFileEx on Windows),
// not the mere existence of the lock file. That distinction is the fix for a
// permanent wedge: the previous implementation used O_CREATE|O_EXCL, so a sync
// killed mid-run (SIGKILL, crash, power loss) left the file behind and every
// later sync for that pair failed forever — and the pid it recorded, the one
// piece of data that could have diagnosed it, was never read by anything. The
// OS drops a kernel lock when the holding process dies, so a leftover lock file
// is now harmless. It is deliberately not unlinked on release: unlinking a
// flock'd path lets a waiter hold a lock on an already-unlinked inode while a
// third process locks the freshly created one, and both would think they won.
func AcquireLock(source, target string) (func(), error) {
	if err := os.MkdirAll(Dir(), 0700); err != nil {
		return nil, fmt.Errorf("creating sync state dir: %w", err)
	}
	path := LockPath(source, target)

	file, err := acquireLockFile(path)
	if err != nil {
		if errors.Is(err, ErrLockHeld) {
			holder := readLockHolder(path)
			if holder.pid > 0 {
				return nil, fmt.Errorf("%w for %s -> %s by pid %d (since %s); wait for it to finish",
					ErrLockHeld, source, target, holder.pid, holder.since)
			}
			return nil, fmt.Errorf("%w for %s -> %s", ErrLockHeld, source, target)
		}
		return nil, fmt.Errorf("creating lock file: %w", err)
	}

	// Record the holder for the contention message above. Truncate first: the
	// file survives across runs, so a shorter pid line must not leave a previous
	// holder's trailing bytes behind.
	if err := writeLockHolder(file); err != nil {
		releaseLockFile(file)
		return nil, fmt.Errorf("writing lock file: %w", err)
	}

	return func() { releaseLockFile(file) }, nil
}

func writeLockHolder(file *os.File) error {
	if err := file.Truncate(0); err != nil {
		return err
	}
	if _, err := file.Seek(0, io.SeekStart); err != nil {
		return err
	}
	line := fmt.Sprintf("pid=%d time=%s\n", os.Getpid(), time.Now().UTC().Format(time.RFC3339))
	if _, err := file.WriteString(line); err != nil {
		return err
	}
	return file.Sync()
}

type lockHolder struct {
	pid   int
	since string
}

// readLockHolder parses the "pid=N time=T" line written by AcquireLock. It is
// purely informational — the kernel lock, not this content, decides ownership —
// so an unreadable or malformed file just yields an unidentified holder.
func readLockHolder(path string) lockHolder {
	data, err := os.ReadFile(path)
	if err != nil {
		return lockHolder{}
	}
	holder := lockHolder{since: "unknown"}
	for _, field := range strings.Fields(strings.TrimSpace(string(data))) {
		key, value, ok := strings.Cut(field, "=")
		if !ok {
			continue
		}
		switch key {
		case "pid":
			if pid, err := strconv.Atoi(value); err == nil && pid > 0 {
				holder.pid = pid
			}
		case "time":
			if value != "" {
				holder.since = value
			}
		}
	}
	return holder
}

func (m *Manifest) SetMapping(entity, sourceID, targetID, sourceName, sourceHash string, at time.Time) {
	if m.Mappings == nil {
		m.Mappings = map[string]map[string]MappingEntry{}
	}
	if _, ok := m.Mappings[entity]; !ok {
		m.Mappings[entity] = map[string]MappingEntry{}
	}
	m.Mappings[entity][sourceID] = MappingEntry{
		TargetID:   targetID,
		SourceName: sourceName,
		SourceHash: sourceHash,
		SyncedAt:   at.UTC().Format(time.RFC3339),
	}
}

func (m *Manifest) GetMapping(entity, sourceID string) (MappingEntry, bool) {
	if m.Mappings == nil {
		return MappingEntry{}, false
	}
	perEntity, ok := m.Mappings[entity]
	if !ok {
		return MappingEntry{}, false
	}
	entry, ok := perEntity[sourceID]
	return entry, ok
}

func (m *Manifest) RecordHistory(results map[string]syncdata.EntityResult, dryRun bool, at time.Time) {
	if m.History == nil {
		m.History = []HistoryEntry{}
	}
	copyResults := map[string]syncdata.EntityResult{}
	for entity, result := range results {
		copyResults[entity] = result
	}
	m.History = append(m.History, HistoryEntry{
		Timestamp: at.UTC().Format(time.RFC3339),
		DryRun:    dryRun,
		Results:   copyResults,
	})
	m.LastSync = at.UTC().Format(time.RFC3339)
}

func sanitizeProfilePair(source, target string) string {
	normalize := func(v string) string {
		trimmed := strings.TrimSpace(strings.ToLower(v))
		if trimmed == "" {
			return "unknown"
		}
		replacer := strings.NewReplacer("/", "-", "\\", "-", " ", "-", ":", "-", ",", "-")
		return replacer.Replace(trimmed)
	}
	return normalize(source) + "-" + normalize(target)
}
