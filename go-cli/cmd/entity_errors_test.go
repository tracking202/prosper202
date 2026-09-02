package cmd

import (
	"strings"
	"testing"
)

// The Go CLI error contract puts lists of valid values in the message
// itself and keeps the hint for the next action. Every command that takes
// a portable entity name must report the supported set the same way.
func TestUnsupportedEntityMessageListsSupportedValues(t *testing.T) {
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, "http://127.0.0.1:9", "test-key")

	cases := []struct {
		name     string
		run      func() error
		allowAll bool
	}{
		{"diff", func() error { _, _, err := executeCommand("diff", "bogus", "--from", "a", "--to", "b"); return err }, true},
		{"export", func() error { _, _, err := executeCommand("export", "bogus"); return err }, true},
		{"import", func() error { _, _, err := executeCommand("import", "bogus", "missing.json"); return err }, false},
		{"sync", func() error { _, err := selectedSyncEntities("bogus"); return err }, true},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			err := tc.run()
			if err == nil {
				t.Fatal("expected an error for an unsupported entity")
			}
			msg := err.Error()
			if !strings.Contains(msg, `unsupported entity "bogus"`) || !strings.Contains(msg, "supported:") {
				t.Errorf("message should name the bad value and the supported set, got %q", msg)
			}
			for _, entity := range sortedPortableEntities() {
				if !strings.Contains(msg, entity) {
					t.Errorf("message missing supported entity %q: %q", entity, msg)
				}
			}
			if tc.allowAll != strings.Contains(msg, "supported: all,") {
				t.Errorf("message should list `all` = %v, got %q", tc.allowAll, msg)
			}
			if hint := hintFor(err); hint == "" || strings.Contains(hint, "Supported entities") {
				t.Errorf("hint should carry the next action, not the value list, got %q", hint)
			}
			if exitCodeForError(err) != ExitValidation {
				t.Errorf("exit code = %d, want %d", exitCodeForError(err), ExitValidation)
			}
		})
	}
}

func TestDiffMissingEntityMessageListsSupportedValues(t *testing.T) {
	_, _, err := executeCommand("diff", " ", "--from", "a", "--to", "b")
	if err == nil {
		t.Fatal("expected an error for a blank entity")
	}
	if msg := err.Error(); !strings.Contains(msg, "entity is required (one of: all,") || !strings.Contains(msg, "campaigns") {
		t.Errorf("message should list the entities to choose from, got %q", msg)
	}
	if hint := hintFor(err); !strings.Contains(hint, "p202 diff") {
		t.Errorf("hint should show an example invocation, got %q", hint)
	}
}
