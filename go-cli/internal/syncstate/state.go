package syncstate

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"

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

	path := ManifestPath(manifest.Source, manifest.Target)
	tmpPath := path + ".tmp"

	data, err := json.MarshalIndent(manifest, "", "  ")
	if err != nil {
		return fmt.Errorf("encoding manifest: %w", err)
	}
	data = append(data, '\n')

	if err := os.WriteFile(tmpPath, data, 0600); err != nil {
		return fmt.Errorf("writing temp manifest: %w", err)
	}
	if err := os.Rename(tmpPath, path); err != nil {
		return fmt.Errorf("renaming manifest: %w", err)
	}
	return nil
}

func AcquireLock(source, target string) (func(), error) {
	if err := os.MkdirAll(Dir(), 0700); err != nil {
		return nil, fmt.Errorf("creating sync state dir: %w", err)
	}
	path := LockPath(source, target)
	file, err := os.OpenFile(path, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0600)
	if err != nil {
		if os.IsExist(err) {
			return nil, fmt.Errorf("sync lock is already held for %s -> %s", source, target)
		}
		return nil, fmt.Errorf("creating lock file: %w", err)
	}

	_, _ = file.WriteString(fmt.Sprintf("pid=%d time=%s\n", os.Getpid(), time.Now().UTC().Format(time.RFC3339)))

	release := func() {
		_ = file.Close()
		_ = os.Remove(path)
	}
	return release, nil
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
