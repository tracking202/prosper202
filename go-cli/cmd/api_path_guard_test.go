package cmd

import (
	"strings"
	"testing"
)

// An id is part of the request's address, so one that holds "?", "#" or a
// ".." segment would send the request elsewhere: `change discard
// chg_1/apply?` applied the change. The client refuses such a path before
// anything is sent.
func TestAnIdCannotMoveTheRequest(t *testing.T) {
	fake := &mcpFakeInstall{}
	srv := fake.server(t)
	tmp := t.TempDir()
	setTestHome(t, tmp)
	writeTestConfig(t, tmp, srv.URL, "test-key-123456")
	for _, args := range [][]string{
		{"change", "discard", "chg_1/apply?"},
		{"change", "show", "../campaigns/7"},
		{"click", "get", "7#fragment"},
	} {
		_, _, err := executeCommand(args...)
		if err == nil || !strings.Contains(err.Error(), "is not an address this command makes") || exitCodeForError(err) != 1 {
			t.Errorf("%q answered %v", args, err)
		}
	}
	for _, hit := range fake.requests() {
		if !strings.HasPrefix(hit, "GET /api/versions") && !strings.HasPrefix(hit, "GET /api/v3/capabilities") {
			t.Errorf("a refused id reached the install: %s", hit)
		}
	}
}
